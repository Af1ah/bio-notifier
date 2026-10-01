# Shift, holiday, and attendance calculation plan

Status: implemented locally on the `ebio` branch; migrations and deployment remain unapplied until separately requested.

Implementation completed: shift rule revisions and break/day rules; typed direct/branch/department/task-group/default assignments; one-time/yearly holidays; deterministic daily and multi-shift calculation; immutable raw-punch evidence; missing-checkout and OT candidates; manual corrections; separate Attendance and OT approval tabs; bounded queued recalculation; calculated reports, CSV, PDF, and employee calendar; tenant-admin policies, rate limits, and isolated tenant workflow tests.

Chosen policy for the previously open OT threshold question: the minimum is a qualification gate. Once the configured minimum is reached, the full eligible OT duration is submitted for approval. Scheduled breaks deduct only their actual overlap with a worked interval; no paid/unpaid distinction is introduced in this version.

Prepared: 24 September 2026. Scope: Bio-Notifier, local `ebio` branch.

## Purpose and scope

Create named shifts with explicit attendance rules, assign them from Branch, Department, and Task Group pages, and calculate consistent daily results for screen reports, CSV, and PDF. Add holiday configuration, audited manual corrections, and bounded recalculation. This is attendance infrastructure suitable for later payroll integration; salary, statutory deductions, leave accrual, and payroll processing are outside this phase.

Requirements below come from the current discussion. Unsettled business rules are identified explicitly; additional suggestions at the end are not accepted implementation scope.

## Current application findings

- Shifts currently live in `schedules`; weekly hours and breaks are nested in `rules.weekly`. Assignment is a polymorphic `target` on the schedule itself.
- `ScheduleForm` provides weekly editing, break modals, validity dates, and a Target field. The replacement removes Target from the shift editor.
- `User::getActiveSchedule()` resolves Task Group, Department, Branch, then an untargeted schedule using `first()`. Multiple matching schedules have no explicit conflict resolution.
- `ReportService::generateReport()` groups punches by calendar date and measures first IN to last OUT, falling back to first/last punch. It does not apply shift hours, breaks, holidays, or overnight attribution.
- Attendance presentation is spread across Reports, CSV/PDF exports, the user calendar, and the user shift summary. All must consume the same calculation result.
- The tenant panel already exists at `/{tenant}/admin`; new resources/pages belong there. Creating a second Filament panel is unnecessary.
- `agent.md` labels Filament as v3, but `composer.json` requires v4. Use the installed lockfile and dependency source when scaffolding and selecting APIs.
- The Livewire middleware contains a Referer-based tenant fallback. This is a security review target, not proof of an exploit; requests must establish tenant context and authorisation independently of that header.
- Existing webhook test teardown iterates over every organisation and deletes it. The new test suite must run only against dedicated test databases and clean up only its own fixtures.

## Shift editor

Use Filament sections and standard fields, with one Filament modal for adding/editing each break. Prefer a clear named shift with one start/end pair over the current wide weekly grid.

| Field | Initial value / behaviour |
| --- | --- |
| Shift name | Required; initial shift: General Shift |
| Active | Enabled |
| Start time | 09:30 |
| End time | 18:30; support an explicit next-day end for overnight work |
| Arrival grace | 15 minutes |
| Departure grace | 15 minutes |
| Punch method | First/last by default; device IN/OUT selectable |
| Earliest accepted arrival | 60 minutes before scheduled start |
| Half-day minimum | 120 net working minutes |
| Full-day minimum | 480 net working minutes |
| OT basis | Above required working hours by default; optional After shift end |
| OT qualification threshold | 30 minutes |
| Working weekdays | Sunday–Saturday selection; General Shift defaults Monday–Saturday |
| Valid from | Required |
| Valid to | Optional, inclusive |
| Auto checkout | Enable/disable |
| Auto-checkout time | Dedicated time field, independently entered; required when enabled, with explicit same-day/next-day offset |
| Default shift | Explicit tenant-wide fallback selection, separate from assignments |

Break modal: name, start time, duration in minutes (hours may be a display convenience), and applicable selected weekdays. Validate the break against the shift interval, including overnight day offsets. No paid/unpaid selector or break-pay rules in this version. The single attendance deduction rule still needs confirmation; absence of a paid/unpaid selector does not imply a deduction. Do not silently add a lunch break.

Validation: end after start after applying day offset; at least one working weekday; valid-to on/after valid-from; half-day threshold below full-day threshold; non-negative grace/window/OT values; positive break duration; no overlapping breaks; breaks contained in the shift. Flag a full-day threshold greater than scheduled net hours before saving.

Grace affects late/early flags only; it does not add worked minutes or relax the half-day/full-day thresholds. Keep actual late/early minutes and the grace-exceeded flags separately. Attendance reports must allow filtering and sorting by late arrival and early departure, including monthly views. More advanced late-report rules are deferred.

## Assignment flow

On Branch, Department, Task Group, and employee pages add an Assign shift action. Select Single shift or Multiple shifts per day, then one shift or an ordered shift set, effective-from date, optional effective-to date, and Submit. Add the same direct assignment action to the Users bulk menu. Display current and upcoming assignments and provide an end-assignment action. Historical assignments remain available to historical calculations.

Resolution order: Direct employee assignment → Task Group → Department → Branch → tenant default. A direct assignment replaces the complete inherited shift set for its effective dates. Use inherited shifts ends the direct override. Single/multiple mode belongs to an assignment, not a shift definition.

Multiple shifts within one assignment set are valid; competing independent assignments at the same priority are conflicts. Intersect each shift's weekdays and validity with the assignment. An assigned Off day does not fall through to a lower-priority working shift. Reject duplicate slots and overlapping scheduled work intervals. Calculate every shift occurrence separately; do not pair morning IN and evening OUT across separate shifts. A single punch cannot act as both one shift's checkout and the next shift's check-in.

Confirmed default behaviour: exactly one explicit active default for each effective date range. General Shift starts as that default; creating another unassigned shift does not automatically replace it. Explicit assignments covering all employees have the same practical coverage but do not change default selection.

Resolve the assignment effective on the workday. Track effective-dated employee membership changes affecting branch, department, and groups, so a later transfer does not silently rewrite old attendance. Preserve the resolved assignment and rule revision on calculated results.

## Workday windows and missing checkout

Represent each shift occurrence using a tenant-local work date plus absolute start/end instants. Store punch instants consistently and convert using the tenant's configured IANA timezone; confirm the existing device timestamp convention before backfilling. Do not silently reinterpret old timestamps.

For a 09:30 shift with 60 minutes early arrival, the new workday opens at 08:30. A punch at exactly 08:30 belongs to the new window. The previous shift cannot consume it as a missing checkout.

Use half-open windows: `[window opening, checkout cutoff)`. Determine the next assigned occurrence, including later shifts on the same day and effective assignment changes. An early-arrival window must not steal a previous same-day shift's checkout. If capture windows overlap, require an explicit boundary between the preceding scheduled end and the next scheduled start in the multiple-shift assignment. Preview and validate it before saving; never guess from missing punches. Ambiguous punches go to manual review.

Define two independent concepts:

1. **Checkout cutoff:** how long the application waits for a real checkout. The next shift's early-arrival boundary is an upper bound. A configured maximum checkout window is needed for weekends, holidays, assignment expiry, or no subsequent shift, so an open shift cannot run indefinitely.
2. **Assumed exit:** use the dedicated Auto-checkout time field and its day offset when checkout is still absent at cutoff. Do not derive it from scheduled closing time or change it when closing time changes. No default time has been agreed: require explicit entry when auto checkout is enabled. This must fall after the first IN and within the shift's allowed window; otherwise flag it for correction.

Before cutoff, a missing OUT is Pending/Open rather than final absence. At cutoff, apply auto checkout only if enabled and a valid IN exists. Store an inferred exit in the calculated result, not a fabricated device punch. Show `Missing checkout — assumed exit 18:30` in detail and retain a machine-readable exception flag alongside the attendance status.

The 18:30 above is an example of an explicitly configured auto-checkout time, not a default. The field specifies the assumed exit, while the cutoff specifies when that assumption can be made. An inferred exit creates a Pending approval item. Candidate hours remain visible separately and do not enter approved attendance totals until an administrator approves them.

For first/last mode, one punch is incomplete, not both IN and OUT. For device mode, unmatched OUT, repeated IN, and invalid sequence remain visible exceptions; do not silently switch methods. A next-day IN must not close the previous workday.

Late-arriving real OUT records are attributed using their punch time, not webhook receipt time. Recalculate the affected occurrence and its adjacent window; replace an inferred exit when valid, while retaining the audit history. Manual corrections take precedence until explicitly withdrawn.

## Calculation sequence and report behaviour

1. Resolve tenant, employee, work date, effective membership, the winning assignment's shift set, and rule revisions. Calculate one result per applicable shift occurrence.
2. Resolve selected weekday and holiday coverage. No applicable shift is a visible `No shift` state, not an assumed absence.
3. Build the shift window and collect eligible raw punches and manual corrections. Deduplicate equivalent evidence without deleting raw rows; never allocate one punch to two workdays.
4. Pair by the configured method. First/last uses first and last distinct eligible punches. Device mode pairs valid IN/OUT sessions and preserves gaps/exceptions.
5. Handle pending or missing checkout under the cutoff policy.
6. Compute gross duration, actual breaks/gaps, scheduled break deduction, and net work. Grace adds no credited minutes. Avoid deducting the same break twice; deduct only applicable overlapping time. Exact break policy awaits confirmation below.
7. Combine eligible non-overlapping working intervals from all occurrences assigned to the employee's work date. Apply one daily rule: Present at 480 minutes, Half Day at 120–479, below-minimum/Absent below 120 by default. These thresholds apply once per day, not once per shift. Individual occurrences retain measured hours, punch completeness and late/early flags; they do not independently award payroll attendance days. Assumed checkout hours require administrator approval. Unresolved evidence remains visible separately from any provisional daily result.
8. Calculate late/early flags and OT using the selected OT basis below. Do not infer payable OT from a generated checkout without an explicit policy.
9. Save a versioned result with evidence references and calculation explanation; serve all reports from that result.

Reports: shift name, work date, first IN, actual/assumed OUT, net hours, late/early minutes, OT minutes, status, and exception indicator. Detailed view shows source punches, break deductions, rule revision, and corrections. Totals distinguish Present, Half Day, Absent, Off, Holiday, Pending, Missing punch, and No shift; exception counts can overlap status counts and must be labelled accordingly.

Mixed off days: display Off per employee. Exclude Off and Holiday from absence totals. Hide a date column only when every employee in the filtered report is off and there is no punch, correction, or exception to display; never hide actual off-day work. A date that is a workday for any displayed employee remains visible. Apply the same rules to CSV and PDF.

Future days and ongoing windows do not increase absence totals. OT totals are separate from regular work totals and do not automatically turn extra time on one day into attendance credit on another.

Daily summaries expand into individual shift occurrences and show completed/assigned shift counts. Two shifts must never count as two present days. Show candidate and approved hours/OT separately, with approval status in detailed reports, CSV and PDF. Correct the existing user shift summary to support several occurrences rather than one `getActiveSchedule()` result.

### Combined daily hours policy

Confirmed: use hour completion for daily attendance, including multiple shifts. Examples with the configured eight-hour full-day minimum: 4h + 4h = Present; 3h + 5h = Present; 3h + 3h = Half Day. Completing every assigned shift is not an additional condition for Present. Missing a scheduled shift, late arrival, or early departure remains visible as an exception even if enough hours were completed elsewhere that day.

Sum the worked intervals within each occurrence; do not use the first punch of the first shift and last punch of the final shift as one continuous duration. Exclude gaps between occurrences and count overlaps only once. Overnight work belongs to the occurrence's starting work date, preserving the established workday rule.

Use one effective daily attendance/OT policy per winning assignment set. A single-shift assignment inherits that shift's thresholds. For multiple shifts, the assignment modal selects the daily rules source from its selected shifts; matching rules can be preselected, while differing rules require an explicit selection. Persist the selected rule revision. Never sum eight-hour thresholds into sixteen hours or silently choose the first shift's conflicting policy. Validate daily required hours against the combined planned working duration rather than against each short shift separately.

Industry context: combined daily working-time evaluation is a supported attendance/time-management approach, not a universal payroll standard. SAP documents daily time evaluation and daily overtime; Frappe also documents shift-level working-hour thresholds. This plan deliberately selects daily aggregation as the organisation's policy. The two-hour Half Day threshold is user-configured, not claimed as a standard half-day payroll entitlement. Payroll rates and jurisdiction-specific requirements are outside this attendance plan.

References: [SAP day processing](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/c6c3ffd90792427a9fee1a19df5b0925/f234e153a217424de10000000a174cb4.html), [Frappe Shift Type](https://docs.frappe.io/hr/shift-type).

### Overtime modes

- **Above required working hours (default):** eligible extra minutes are `max(0, combined daily net worked minutes - daily full-day required minutes)`. Apply this once per employee/work date. With an eight-hour requirement, 8h20m yields 20 candidate OT minutes, below the 30-minute qualification threshold; 8h30m meets the threshold. Two shifts of 4h and 5h yield nine working hours and 60 candidate OT minutes. Shift closing time does not gate this mode. Accepted early work can contribute to the net total.
- **After shift end (selectable):** candidate OT is actual eligible worked time after the scheduled end instant, excluding gaps and applicable deductions. Overnight shifts use the next-day end instant. This option replaces the working-hours basis; it does not silently combine both conditions.

Persist the selected basis in the shift rule revision and show it in attendance details. Whether a qualifying duration counts in full or only after subtracting the 30-minute threshold remains an open policy choice. No overtime pay rate or multiplier is introduced in this phase.

For working-hours mode, compute OT once on the daily aggregate, not independently per occurrence. For After shift end mode, collect eligible post-end intervals from each occurrence, exclude intervals already assigned to another scheduled shift, deduplicate, then apply the selected daily OT threshold. Store daily OT with its contributing occurrence evidence and approve it once per daily result revision. Qualifying OT enters the manual approval page; calculation alone does not approve it.

## Holiday configuration page

Add a Holidays resource with a Filament create/edit modal inside the existing tenant panel. Fields: name, date or date range, active status, coverage (all employees or selected branches/departments/task groups), and Recurrence: Does not repeat or Yearly on the same date. Yearly is the bounded initial interpretation; other patterns require agreement. Add start year and optional end year and preview generated dates. February 29 occurs only in leap years. Multi-day recurrence preserves the start/end range, including cross-year ranges.

Store definitions and dated occurrences separately with unique definition/date keys and bounded expansion. Effective revisions preserve past reviewed occurrences; cancellations and overrides are audited. Skip holiday imports in this version. Keep a reusable holiday validation/creation service and optional provenance boundary for next-version imports with tenant-scoped deduplication. Manual holidays must not depend on an external source.

Display the applicable holiday in daily attendance and employee calendars. Holiday suppresses ordinary absence. Preserve actual punches on a holiday and identify them as holiday work. Holiday pay, OT multiplier, or compensatory leave require a later explicit rule; this phase must not invent payroll entitlements.

Overlapping holidays must not double count a day. Changing coverage or dates marks only affected results stale and offers recalculation.

## Manual attendance corrections

Add Correct attendance to the daily detail page using a Filament modal. Allow effective IN/OUT corrections and an explicit status correction with a required reason. Capture actor, timestamp, original value, corrected value, and work date. Preserve actual computed hours when status is overridden, and display the override clearly.

Raw punches stay intact. Corrections are append-only revisions with a withdraw/supersede action. Submitting a correction produces a candidate result for administrator approval; approved calculations use the latest approved correction. Use optimistic concurrency to prevent conflicting edits. Only authorised tenant administrators use these pages in this version; employee login, self-service requests and employee approval routing are deferred.

## Attendance approvals page

Use two separate tabs on this dedicated page:

- **Attendance approvals:** missing/auto checkout and manual attendance corrections. Show proposed IN/OUT, candidate attendance hours/status and supporting punches. Provide Approve selected and Reject selected for attendance items only.
- **OT approvals:** daily overtime candidates. Show work date, total working hours, required hours, OT basis, candidate OT minutes and contributing shift details. Provide Approve selected and Reject selected for OT items only.

Each tab has its own pending count, filters, pagination and selection state. Clear bulk selection when switching tabs; bulk actions must never process items from the other tab. Keep Pending/Approved/Rejected filters within each tab. Server-side queries and action validation must enforce item type as well as tenant and permission.

Attendance approval does not approve overtime, and overtime approval cannot approve missing attendance evidence. If OT depends on a pending attendance correction or inferred checkout, show Awaiting attendance approval and block OT approval until that evidence is approved and the daily result is recalculated. If an attendance change alters previously approved OT, create a new OT revision for review and preserve the original decision history.

Create a dedicated Attendance Approvals page in the existing Tenant panel using Filament CLI scaffolding. Initial review scope: missing/auto checkout, manual corrections, and qualifying OT. Complete ordinary attendance does not require approval. Each item shows employee, work date, shift occurrence, type/reason, original/proposed values, candidate hours/OT, source evidence, calculation revision, and approval state.

States: Pending, Approved, Rejected, Superseded. Keep these separate from attendance status. Filter by date range, employee, branch, department, task group, shift, item type and approval state. Row details show evidence and before/after values, with Approve and Reject actions. Rejection requires a reason; rejecting an inferred exit leaves the exception unresolved rather than inventing a checkout.

Bulk actions: Approve selected and Reject selected. Preview exact item count, type breakdown, dates and affected minutes before confirmation. Bulk rejection requires a shared reason. Re-read every selected item on submission and check tenant, permission, Pending state and unchanged revision. Skip stale/ineligible items and report success/skipped/failed counts with reasons. Never silently select additional rows when filters change.

Initial bulk cap: 100 items per request. Use short transactions, idempotency keys and optimistic concurrency. Every decision records actor, timestamp, reason/note and exact evidence/result revision. Approved auto checkout permits candidate attendance hours to enter approved totals; OT requires its separate approval. Administrator approval is in scope, but a mandatory second approver is not assumed.

Recalculation must not overwrite approved revisions. Changed evidence or rules create a new Pending revision while the previous approved result remains visible and marked stale. Identical recalculation preserves approval. New evidence must supersede obsolete pending items. Reopening rejection creates a new revision. Decisions and published totals update atomically, preventing duplicate approval.

Keep subject employee, proposing actor, deciding actor and request source separate for later employee request compatibility. Employee login/self-service and multi-stage routing are future features. Payroll-period locking remains a separate unapproved suggestion.

## Recalculation

Add Recalculate to Attendance: choose a bounded date range and employee/branch/department/group filters, preview the number of employee-days, and Submit. Display queued/running/completed/failed counts and the last calculated time. Normal page renders must not recalculate the whole report.

Queue bounded chunks, with tenant-specific deduplication and overlap locks. Jobs carry explicit tenant and run IDs, initialise tenancy before reading models, and end it in `finally`. Retry failed chunks safely without duplicating daily results. Use the same calculation service for manual recalculation and incremental updates after new punches.

Rule and assignment changes flag affected results stale. Use effective rule revisions and record the chosen revision; historical recalculation must not silently apply today's rules to an older period. Run completion is not published until all chunks have a consistent result revision; reports show last complete results and stale/progress indicators during work.

## Normalised tenant database design

Keep all shift/holiday/attendance business tables in tenant databases. The central database remains the organisation registry and central administration store. Avoid a new shared employee database.

| Table / logical entity | Main purpose and constraints |
| --- | --- |
| Existing schedules | Stable shift identity and name; retained IDs where practical |
| Shift rule revisions | Effective interval, start/end offsets, grace, thresholds, OT basis, punch method, cutoff, independent auto-checkout time/day offset; immutable revision identity |
| Shift weekdays | One selected weekday per rule revision; unique `(revision_id, weekday)` |
| Shift breaks and break weekdays | Named time/duration rows with FK to revision and per-day applicability |
| Assignment sets and shift slots | Single/multiple mode, ordered shift references, effective dates and explicit punch-window boundaries |
| Assignment daily policy reference | One effective rule revision for daily thresholds and OT, explicitly selected when multiple shifts have differing rules |
| Employee / branch / department / task-group shift assignments | Typed owner tables with real FKs to owner and assignment set; avoid unconstrained polymorphic IDs |
| Default shift assignments | Effective default intervals, preventing overlapping defaults |
| Membership history | Effective employee-to-branch/department/group references used for historical resolution |
| Holiday definitions, revisions, occurrences and coverage | One-time/yearly rules, unique definition/date occurrences and typed coverage FKs; next-version import provenance compatibility |
| Attendance days | Unique `(user_id, work_date)` summary container and published revision; multiple child shift occurrences |
| Daily result revisions | Combined eligible hours, daily policy revision, one attendance status and daily OT candidate/approved minutes; links to contributing occurrence revisions |
| Attendance occurrences and result revisions | Stable identity from employee/work date/assignment slot; shift/rule references, measured/candidate/approved minutes, status, flags, revision; preserve superseded occurrences |
| Attendance occurrence punches | Evidence links and role; one punch allocation per active calculation revision, with previous allocation history preserved |
| Attendance corrections | Append-only actor/reason/before/after changes tied to occurrence and revision |
| Approval items and decisions | Type, exact occurrence/result/correction revision, candidate values, state, actors, audit history and idempotency constraints |
| Calculation runs / chunks | Tenant-local job scope, progress, idempotency key, failures, revision and watermarks |

Use integer minutes for durations, indexed timestamps for raw punches, and explicit status/source values. Small immutable explanation snapshots can use JSON, but core relationships, dates, and calculations belong in typed columns. Add FKs, uniqueness, date-order checks, and indexes on assignment intervals and `(pin, punched_at)`. Enforce overlapping effective ranges in PostgreSQL where practical, with transactional locking as a backstop. Reject conflicting concurrent assignments.

Multiple shifts per employee per workday are in scope. The day summary cannot have a single authoritative shift ID. Punches, rules, corrections and approvals belong to occurrences; daily totals derive from consistently published occurrence revisions without duplicating approved amounts in unrelated stores.

## Tenant isolation and data leak prevention

- Establish verified tenant context before model binding, authentication lookup, Livewire actions, option searches, exports, and jobs. A Referer or submitted tenant ID alone is insufficient.
- Confirm the authenticated account belongs to the active tenant; test identical user and resource IDs across two tenant databases and same-host path switching.
- Fail closed when tenant context is absent or mismatched. Validate selected assignment/holiday/user IDs inside the active tenant connection, not just in dropdown options.
- Apply policies server-side to view, edit, assign, correct, approve/reject, recalculate, and export. Hiding a button is not authorisation. Bulk approval checks tenant, permission, state and revision for each selected item.
- Scope locks, job uniqueness, cache keys, progress records, notifications, and download authorisation to tenant plus resource/run identity.
- Keep exports private with authorised downloads and expiry. Do not place attendance PDFs in a publicly accessible storage location. Avoid caching authenticated attendance screens in the PWA/service worker.
- Do not put biometric templates, credentials, complete raw payloads, or employee lists in diagnostics. Audit events store only required attendance changes.
- Validate session/guard behaviour on tenant switching, stale Livewire requests, and master impersonation; retain explicit master authority rather than allowing tenant users central access.
- Verify database-role privileges before describing database-per-tenant architecture as database-enforced isolation. A shared DB role still relies on correct application scoping.

## Request limits and performance

Proposed starting limits below are engineering defaults to validate under local load, not measured capacity guarantees.

- Recalculation: 5 submissions per minute per actor/tenant; 1 active run per tenant; coalesce identical requests. Maximum 31 days and 10,000 employee-days per request; split larger requests visibly.
- Exports: 5 requests per minute per actor/tenant, with a tenant-level concurrency limit of 1; queue expensive exports and reuse only authorised same-scope completed results.
- Manual corrections and assignments: 30 writes per minute per actor/tenant; reject repeated conflicting submissions via idempotency/concurrency checks.
- Approval submissions: 10 per minute per actor/tenant and 100 items maximum per bulk action. Bound calculations by shift-occurrence count as well as employee-days so multiple shifts cannot bypass workload limits.
- Search: paginate 25–50 options, debounce input, query indexed names/IDs, and avoid loading every employee or target on each modal render.
- Calculation chunks: initially up to 100 employees over at most 7 days, additionally capped by fetched punch volume; cursor/chunk large punch sets. Bulk load assignments, holidays, memberships and punches to prevent employee/day N+1 queries.
- No network calls inside calculation transactions. Use short per-day or bounded-batch transactions, deterministic lock ordering, bounded retries and tenant-specific timeouts.
- Throttles apply to submitted actions and queue workloads as well as routes. Return actionable limit messages with retry timing. Do not throttle normal Livewire keystrokes as if they were recalculation submissions.
- Keep attendance processing from starving webhook ingestion; use dedicated queue capacity and monitor pending work. Do not change eBio ingress limits during this feature without a separate payload-volume assessment.

## Filament CLI and implementation sequence

Use the existing Tenant panel. Prefer Filament/Artisan generators for new resources, pages, relation managers, policies, migrations, commands, jobs, and tests; keep calculation code in domain services and UI classes small.

Before generating, install locked dependencies in the local development environment, inspect the available commands and their `--help`, and verify exact options against that installed Filament version. Do not copy v3 scaffolding from `agent.md`.

Planned CLI discovery:

```bash
php artisan list --raw
php artisan make:filament-resource --help
php artisan make:filament-page --help
php artisan make:filament-relation-manager --help
php artisan make:migration --help
php artisan make:test --help
```

Then scaffold Holiday and AttendanceDay resources and an AttendanceApprovals page in panel `tenant`, retaining/refactoring the existing Schedule resource. Use conventional model/policy/job/test generators where available. Generator flags and exact final commands will be recorded during implementation after discovery.

Implementation order:

1. Confirm the policy questions below; establish isolated local PostgreSQL fixtures and tenant authorisation regression tests.
2. Add normalised structures and additive data migration; preserve legacy schedule IDs/rules for comparison and rollback.
3. Implement rule validation, shift forms/break modals, single/multiple assignment sets, direct employee overrides and default resolution.
4. Add Holiday configuration modal, yearly recurrence and effective holiday resolution with a future import-compatible service boundary.
5. Implement a deterministic calculation service for window attribution, pairing, breaks, grace, thresholds, missing OUT, and OT.
6. Add occurrence/detail/correction and administrator approval pages with bulk actions, and a shared data source for reports, calendar, CSV and PDF.
7. Add queued recalculation, incremental updates, cutoff finalisation, progress, limits, and concurrency controls.
8. Complete tests and compare old/new calculations on synthetic data. Present local changes for review before a separately requested production deployment.

Migration must flag legacy schedules whose daily start/end times differ instead of flattening them silently. Seed General Shift only when no valid default exists; never overwrite a configured tenant shift. Migrate tenants individually with backups and per-tenant checkpoints when deployment is authorised. No `migrate:fresh` or attendance deletion is part of this feature rollout.

## Test method and acceptance cases

Use PHPUnit already configured by this project, Laravel feature tests, Filament/Livewire component tests, and a real isolated PostgreSQL instance for tenancy/constraint behaviour. Mock eBio/WhatsApp; tests must never contact devices or real recipients. Verify test DB host/name/role before destructive fixtures; fail if pointed at deployment credentials. Cleanup only registered test-created tenant IDs.

| Area | Required checks |
| --- | --- |
| Thresholds | 119/120/479/480 minutes; grace at 14/15/16 affects flags only; OT at 29/30/31 in both modes; early work contributes in working-hours mode; exact boundaries and rounding |
| Pairing | First/last, multiple device IN/OUT sessions, repeated IN, one punch, OUT only, duplicate/reordered payloads |
| Boundaries | 08:29 vs 08:30 for a 09:30/60-minute window; yesterday missing OUT plus today's IN; overnight 22:00–06:00; changed next-day shift |
| Cutoff | Before/exactly at/after cutoff; weekend/holiday/no-next-shift; auto-out disabled; explicit time required when enabled; changing shift end leaves auto-out time unchanged; invalid assumed exit; late real OUT replacing assumed OUT |
| Breaks | Full/partial overlap, hours-to-minutes conversion, overnight breaks, overlaps rejected, no double deduction with device OUT gaps |
| Assignments | Priority, conflicting task groups, validity endpoints, changed membership, default gaps, simultaneous conflicting writes |
| Multiple/direct shifts | Direct override replaces inherited set; return to inherited; single/multiple modes; independent punch windows; boundary conflicts; no duplicate regular/OT time; one present-day maximum; occurrence history after reassignment |
| Daily hour completion | 4h+4h and 3h+5h Present; 3h+3h Half Day; 4h+5h gives 60 candidate OT minutes with eight-hour daily rule; gaps excluded; overlaps counted once; conflicting daily policies require selection; per-shift exceptions do not override completed daily hours |
| Holidays/off | All-tenant and scoped holidays, mixed Off cells, date hiding, off-day/holiday punches retained, future dates not absent |
| Recurrence | Yearly dates/ranges, cross-year ranges, leap-day handling, bounded generation, no duplicate occurrences, revision history and future import compatibility |
| Corrections | Required reason/policy, unchanged raw logs, audit actor, superseding edits, stale concurrent submission, persistence across recalculation |
| Approvals | Provisional auto-out, separate OT decision, correction review, rejection reason, mixed bulk selection, stale revisions, cross-tenant IDs, concurrent/idempotent decisions, recalculation after approval, accurate skipped/failed counts |
| Recalculation | Repeat produces same result, concurrent runs coalesce/block, failed chunk retry, changed punch watermark, no mixed result versions |
| Isolation | Tenants A/B with matching IDs/PINs; forged IDs/Referer/tenant route; stale Livewire state; sequential A/B jobs; cache, progress and export separation |
| Reports | Screen/calendar/CSV/PDF totals agree; late/early filtering and sorting in daily/monthly reports; statuses and exceptions distinct; no salary/OT pay claimed; large ranges capped |
| Limits | 429/action errors at limits, tenant fairness, input size limits, bounded SQL query counts and memory use |

Run focused tests first, then relevant regression suites plus PHP lint and diff checks. Browser/UI and deployed-site testing will be performed only when explicitly requested. Report calculation verification, component tests, browser checks, and production verification separately.

## Confirmed decisions

1. Auto checkout uses its own dedicated time field, independent of shift closing time.
2. OT defaults to work above the full-day working-hours requirement, with After shift end as a selectable alternative.
3. Grace affects late/early flags only. Add report filtering/sorting now in the implementation scope; defer advanced late/monthly policy logic.
4. Exactly one explicit default shift applies to otherwise unassigned employees.
5. First/last punch is the default method; device IN/OUT remains selectable.
6. Add administrator approval on a dedicated page with bulk approve/reject. Employee login and employee-submitted approval requests remain future features.
7. Add recurring holidays in the create/edit modal. Defer holiday imports with next-version compatibility.
8. No paid/unpaid break configuration in this version.
9. Support Single shift or Multiple shifts per day and direct employee assignments.
10. Determine daily attendance by combined eligible working hours, not by completing every shift. Apply the working-hours OT threshold once to that daily total.
11. Separate Attendance approvals and OT approvals into distinct tabs, with independent selections, counts and bulk actions; attendance approval never automatically approves OT.

## Remaining policy details

These do not change the confirmed decisions above and must not be silently inferred during implementation:

- When OT reaches its 30-minute qualification threshold, count the whole eligible duration or only minutes beyond 30?
- What maximum checkout wait applies when the next assigned shift is days away or does not exist? Assumed checkout hours now stay provisional until administrator approval.
- Without paid/unpaid options, should the single attendance rule deduct configured breaks or retain them in worked hours? Avoid double deduction with device OUT gaps.

## Additional suggestions — awaiting permission

These are optional additions, not part of the accepted plan. Ask before adding them:

- A review-and-lock step for completed attendance periods, so later recalculation cannot change payroll inputs silently.
- Recurrence patterns beyond yearly same-date holidays, if needed.

Explicitly deferred: employee login/self-service requests and multi-stage routing; holiday import UI/parsers (next version); paid/unpaid break configuration. Administrator approvals, yearly recurring holidays, and multiple/direct shift assignments are accepted scope, not deferred suggestions.

No additional suggestion will be implemented solely because it appears here.
