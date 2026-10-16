# Leave carry-forward and compensatory leave plan

Status: implemented in the development checkout on 14 October 2026. The original approved plan is retained below; the implemented controls and rollout instructions follow it. Production migration and company policy enrolment remain operational steps.

## Unified settings correction — 14 October 2026

This correction supersedes the duplicate settings described in the original plan and earlier implementation notes below. Leave settings provides inline paid-category creation and a link to category management. Leave categories is also available directly under Payroll; it does not introduce a separate entitlement calculation. New paid requests require an applicable policy; the legacy annual allowance remains stored only for historical unenrolled payroll evidence and is no longer an editable grant configuration.

Regular entitlement and unused-leave settings share one form section. New revisions have one explicit relative or fixed expiry rule, without a further-rollover flag. Previously issued credits retain their dates, and retained older revisions preserve their recorded expiry rules. Irrelevant form fields are hidden and normalized at the service boundary.

Work rewards inherit the effective daily shift thresholds captured by attendance, including the selected multi-shift daily rule. New awards record the source rule and thresholds. Existing awards are retained. The entitlement balance cap excludes opening and compensatory credits; carry limits are applied at eligible period boundaries independently of grant timing. Carried totals use the captured grant-period end, so annual entitlement becomes carried only after its annual period ends. Grant date limiting compares tenant calendar dates instead of mixed timezone instants.

Policies permitting unpaid excess require a category deduction rule, and category edits cannot remove that rule while an applicable revision permits unpaid excess. Policy conflicts are scoped to the affected category and employee assignments; assignment changes also check the selected employees' closed payroll periods. No historical credit migration or production deployment is implied by these source corrections.

## Before implementation

Leave types hold an annual allowance in integer half-day units. Employee leave records are approved/cancelled dates. SalarySlipService computes paid coverage from annual allowance and earlier approved leave. There is no dated accrual ledger, expiry, monthly carry-forward or compensatory credit. These features require a shared leave balance service rather than a second allowance calculation inside payroll.

## Policy and employee scope

Add effective-dated immutable leave policy revisions, assigned to employees with non-overlapping effective dates. Use the existing branch/department/group employee selection to assign policies explicitly; avoid ambiguous overlapping runtime scope rules. Retain annual allowance mode for existing policies.

Monthly policies specify grant amount and timing, join/exit proration, carry enabled, carry cap, maximum available balance, expiry rule, and whether carried units may roll again. Default recommendation: do not extend the original expiry on another rollover. Separate carried-unit expiry from current-month entitlement expiry. Expiry may be relative (for example, end of next month) or a fixed cutoff (for example, 31 March of the next leave year). Dates follow the tenant calendar/timezone, inclusive through the expiry date. Monthly entitlement and annual entitlement must not both grant the same allowance.

Compensatory policies specify eligible holiday/rostered-off work, approved net-minute thresholds for half/full-day credit, maximum credit per work date, availability date, expiry and reward choice: paid compensation, leave credit, or explicitly permitted both. Weekend eligibility comes from the employee roster, not a hard-coded Saturday/Sunday rule. Preserve the original calendar classification in attendance evidence even when worked status becomes Present.

## Tenant database design

- leave_policy_revisions: immutable rules, leave type, effective dates and creator.
- employee_leave_policy_assignments: employee, revision, effective window.
- leave_credit_lots: employee/type, granted half-units, source kind/reference/revision, available_on, expires_on, policy revision and grant audit. Each monthly grant or approved work reward has its own lot.
- leave_ledger_entries: append-only grant, reservation, consumption, release, expiry, reversal and adjustment entries; actor, reason, effective date, recorded time and unique idempotency key. Reference original entries for reversals.
- leave_allocations: leave request, credit lot, half-units and allocation lifecycle. Spend earliest-expiring eligible credits first, then oldest grant.
- compensatory_awards: source attendance day plus contributing shift occurrences/revision, approved minutes, original holiday/off classification, chosen reward, reviewer, state and awarded lot. Prevent duplicate active rewards for the same work date across recalculations or multiple shifts.

All tables live in the tenant database. Foreign keys restrict deletion of financial/leave evidence. Integer half-units preserve the existing full/half-day model. Transactional account locks serialize grants and allocations; retries use unique idempotency keys. Do not use a mutable balance field as the source of truth.

## Workflow and pages

Extend Leave Types with policy settings and revision history. Add Leave Balances showing available, reserved, expiring, carried and consumed units with an auditable history. Extend Employee Leave with pending/approved/rejected/cancelled transitions through a service; approval consumes eligible balance, cancellation reverses it without extending expiry. Pending requests reserve balance; expired reservations require revalidation. Retrospective approvals allocate as of the leave date, subject to authorization and payroll-period locks.

Attendance approval creates an eligible compensatory claim only from approved, current work evidence. HR chooses compensation or leave credit according to policy. Attendance recalculation must never grant another reward. Corrected/reversed work evidence invalidates an unconsumed award; consumed credit requires an explicit reviewed adjustment rather than silent deletion or negative balance.

Same-day availability means the credit can become available on the work date after approval. It cannot justify both a full worked day and full leave for the same employee/date. A half-day worked/half-day leave scenario requires the applicable policy and explicit non-overlap verification; deferred use is the simpler default.

## Payroll integration

A shared LeaveBalanceService determines covered leave units for approval and payroll. Replace annual subtraction in SalarySlipService with stored allocations for new-policy periods. Record allocation IDs, policy revisions, covered units and any selected holiday-work payment in the payroll snapshot. Include relevant balance/award evidence in manual payroll review signatures. Leave policy or allocation changes invalidate affected draft/review evidence. Paid slips remain immutable; later corrections use an approved adjustment.

Do not deduct a normal holiday or rostered weekly off as annual leave. Work reward selection must prevent accidental duplicate leave credit and holiday/overtime payment; explicitly allowed statutory/company combinations remain configurable.

## Rollout and verification

1. Implement revisioned policies, grants/ledger and allocation service with tenant migrations and concurrency constraints.
2. Route leave approval/cancellation through the service; expose balances and expiry warnings.
3. Add attendance-linked compensatory review and payment/credit selection.
4. Integrate payroll and manual-review evidence; lock closed payroll periods.
5. Migrate at an explicit cutover: retain historical payroll behavior, import verified opening balances with reviewer/reason, and do not invent historical monthly credits. Reconcile approved future leave against the opening lots.

Test month/year rollover, leap February, exact expiry boundary, carry caps and repeated rollover, join/exit proration, half-days, cancellation after expiry, simultaneous requests, retry idempotency, multi-shift duplication, changed attendance after credit use, mutually exclusive rewards, retrospective leave, paid-slip adjustments and overlapping employee IDs in separate tenants. Scheduled grant/expiry jobs must be replayable; balance eligibility must check dates even if the scheduler is delayed.

## Policy decisions before implementation

Confirm monthly grant amount/timing and join/exit proration; maximum carry and expiry rule; whether carried credits may roll again; approved-work thresholds; and whether holiday/off-day compensation replaces or supplements monetary overtime/holiday pay. These are tenant policy choices, not universal employment standards, and should support applicable local requirements.

## Documentation audiences

Client configuration and daily-use guides are kept in [docs/client](../client/README.md). This document covers developer design, database evidence, rollout and verification.

## Implemented tenant controls

Use **Payroll → Leave settings**. Scope displays Default or Assigned employees; row actions use a three-dot menu. Policies, calendar settings, balances, assignments and awards live in each tenant database; another company does not inherit changes. Administrators (privilege 14) can add starter policies, create additional policies, assign employees using the existing branch/department/group filters, save future revisions, and end assignments with an audit reason.

**Leave calendar settings** supplies the tenant timezone and expiry-warning window. Defaults are the application's attendance timezone (Asia/Kolkata unless configured otherwise) and 30 days. These settings govern leave date boundaries; they do not change the existing attendance engine timezone.

Starter policies are explicit opt-in examples, not a claim of universal statutory compliance. Existing leave types/annual allowances and historical paid slips are not automatically migrated or replaced.

| Setting | Editable starter value |
| --- | --- |
| Monthly paid entitlement | 2 days at period start |
| Join/exit proration | Actual eligible calendar days; round down to 0.5 day |
| Maximum carried entitlement | 6 days |
| Maximum available entitlement balance | 12 days |
| Relative entitlement expiry | End of the third month after the grant period |
| Further carry-forward | Allowed within original expiry; never extends expiry |
| Alternative cutoff | Annual cutoff date picker; month/day repeat yearly and leap day clamps to February month end |
| Excess leave | Reject; tenant may enable unpaid excess |
| Compensatory threshold | Inherited from the effective daily shift rule captured by attendance |
| Compensatory availability | Following date; same-date availability is configurable |
| Compensatory expiry | 90 days after availability, inclusive through expiry date |
| Compensatory reward | HR chooses leave or payment; both requires an explicit policy |
| Additional overtime with reward | Disabled; configurable |
| Monetary work reward | 0 until configured; policy currency defaults to INR and must match employee payroll currency |

Monthly and compensatory starter policies are separate leave types. Monthly policies do not earn compensatory credits unless explicitly enabled. Annual policies are also supported, with the grant amount configured by the company; annual periods follow the configured From/To window, with January–December retained when no window is configured. To allow only one following month in a new revision, set additional months to 1. The balance cap limits newly issued regular entitlement and excludes reviewed opening balances and compensatory credits.

Initial enrolment may start mid-period. Later monthly entitlement revisions start at a future month boundary; annual revisions start at the next configured annual-period start, or future 1 January without a configured period. Compensatory-only revisions may start on another future date. Existing grants/awards keep their captured revision. A policy retains its leave type and default/assigned scope. Assignments are explicit snapshots of selected employees, not automatic rules for employees who subsequently join a branch/group. Monthly assignments start at month boundaries and end at month end; annual assignments follow the configured period boundaries, with calendar-year boundaries as the fallback. Use End assignment before switching an employee to another policy. Future approved/reserved leave and issued credits must be reconciled before shortening an assignment or introducing a conflicting policy revision.

## Implemented evidence and flows

The tenant migration creates `tenant_leave_settings`, `leave_policies`, `leave_policy_revisions`, `employee_leave_policy_assignments`, `leave_credit_lots`, `leave_ledger_entries`, `leave_allocations` and `compensatory_awards`, and adds reviewer/balance-management metadata to employee leave. Credits and policy revisions cannot be changed or deleted through their models; balance reductions use append-only entries. Foreign keys retain referenced evidence. Allocation state is a workflow view; available credit is computed from grant evidence and ledger entries, with expiry and current award eligibility enforced separately.

**Leave balances** displays days, carried units, reservations, usage and expiring balances. History shows the dated credits and transactions. Add reviewed balance imports a verified opening balance or positive adjustment with a reason and expiry. Reduce reviewed balance records an idempotent negative adjustment only against free credit; reserved/consumed units must be reconciled first. Compensatory credits are reversed from their attendance row instead.

**Employee leave** supports Pending, Approved, Rejected and Cancelled. Pending records reserve earned credits; approval consumes them. Earliest expiry is used first. Saving an unchanged leave is a no-op; transitions retain release/consume history. Failed allocation rolls back the complete leave write. Cancellation releases credit but never changes expiry. Holidays and rostered off dates do not consume leave. Full leave cannot overlap approved work; half-day leave with work requires a verified half-day attendance result. Reviewed/approved/paid payroll periods reject leave changes.

Future monthly grants are not issued to fund advance bookings; leave requests may reserve existing credits that are valid on the requested date. Retrospective leave is subject to payroll locks, remaining credit and already applied carry-limit adjustments; reconcile opening balances explicitly when historical credit cannot be established. Missing employment dates mean eligibility begins at policy/assignment enrolment; set employment dates before issuing grants to obtain accurate join/exit proration. Already issued credits are not silently rewritten when employment dates change.

**Attendance Days → Award leave/payment** reviews actual approved work. Work reward, Reward status, Reward details and Reverse reward are on the attendance row; the separate compensatory page was removed. Attendance calculation now retains holiday/off classification alongside worked status and includes device punches on those dates. The work-date award aggregates the approved day/shift evidence and caps the reward at one day across shifts. Pending/stale/future work is blocked. One active reward per employee/date prevents retry or multi-shift duplicates. Stale award credits are unavailable; consumed credits with changed evidence block payroll and require HR reconciliation. Unconsumed awards may be reversed and re-awarded with both decisions retained. Consumed/reserved awards must have their leave reconciled first; paid-period adjustments remain a separate reviewed payroll operation. Same-date availability cannot turn a fully worked holiday into a simultaneous full-day leave claim.

**Payroll** uses approved allocations for enrolled policies rather than annual allowance subtraction. Legacy periods keep the existing calculation. Monthly employees retain salary for covered leave; daily/hourly employees receive allocated paid-leave earnings, with hourly leave requiring the effective shift daily-hours rule. Approved monetary work rewards are included in other earnings and identified in the slip/PDF. Allocations and awards are captured in payroll and manual-period-review evidence; changes invalidate draft progression. Pending leave blocks payroll. A changed compensatory source is checked even when its consumed leave falls in a later month.

## Scheduler and rollout

Deploy the tenant migration before accessing the new pages or restarting workers:

```bash
php artisan tenants:migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Follow the existing backed-up deployment procedure and pause workers during schema changes. Configure the normal Laravel scheduler. `leave:sync-balances` runs hourly, using each company's leave timezone; it issues due grants and records expiry idempotently. It accepts repeatable `--tenant=ID_OR_SHORTNAME` filters. Page/approval services also issue due grants, and lot dates reject expired credit even if the scheduler is delayed. Full historical payroll adjustments and recoverable opening balances must be reviewed from real company records; no automatic historical credits are fabricated during migration.

Each company should select a cutover after its closed payroll and existing future leave commitments, review the starter values or create its own policy, set employment dates, enrol employees, and import verified opening balances where needed. Company rules and jurisdictional requirements determine entitlements and reward combinations.

## Verification results

The final local suite passed **190 tests with 923 assertions**; 38 leave tests cover settings/actions, grants, expiry/cutoffs, carry limits, assignments, reservations, paid leave, compensatory rewards, payroll integration, authorization and migration rollback. Formatting and diff checks passed. Disposable PostgreSQL checks passed in two tenant databases for independent entitlements/calendars, overlapping employee identity isolation, concurrent approval serialization, allocation rollback, expiry processing and scheduler context restoration. Both temporary tenant databases were removed. Existing manual-attendance, partial-month payroll and removed-employee PostgreSQL checks also passed again. No production deployment or full browser/live eBio verification was performed.

## Attendance-page integration

Compensatory review is provided by `CompensatoryAttendanceActions` on the Attendance Days table. Day type is independent of worked status; work-reward amount/status and detail/reversal actions use the existing tenant award and credit records. The relation is eager-loaded with the attendance query. The separate page class/view and its discovered route have been removed. Existing reward services, unique work-date constraints and tenant schema remain unchanged by this UI move.

Client guides are in `docs/client`; this developer document retains the original approved plan and migration/test details. Regression checks exercise awarding, viewing the actual modal content, reversing from the same row, hiding awards on working dates, removal of the old route, and the fact that credit/paid leave does not inflate attendance report minutes or rewrite raw work status.

## Policy removal

The explicit administrator-only Remove policy action is the exception to ordinary immutable-model deletion guards. `LeavePolicyRemovalService` creates a review signature, locks administrators followed by affected employees and the selected policy, rechecks current dependencies and performs cleanup in one tenant transaction. It records the original policy, revisions, assignments, lots, entries, allocations, leave, rewards and affected drafts in `leave_policy_removals` with actor/reason. Ordinary model mutation/deletion guards remain intact.

Required processed-payroll evidence is retained, including approved leave history used by a later processed month's evidence signature and a work reward consumed as leave in a later processed month. Retained revision rows have a null parent policy; retained lots return zero availability. Historical awards continue to support closed-source payroll, but an archived award retained for later leave does not create payment again in an open source month. Category and attendance records are not removed. Unrelated employee/policy records and unimpacted drafts remain.

Migration `2026_10_14_170000_add_leave_policy_removal_archive.php` adds the tenant audit table and makes the revision parent nullable while preserving its foreign key. Apply this migration before using removal. Rollback is allowed before any removal; after removal it requires explicit recovery of archived history rather than silently dropping evidence.

The category form shows deduction controls only when Paid leave is disabled. Paid-category deduction values are cleared; unpaid-excess treatment belongs to policy revisions. The calendar form labels its timezone selector Select time zone. Policy sections span the content width with responsive field columns. The expiry warning is a display window, not a notification or expiry extension.

## Leave periods and annual expiry cutoffs — 14 October 2026

Optional `leave_period_from` / `leave_period_to` dates are stored in immutable policy revision rules. Both or neither must be supplied, dates must be ordered, and the revision effective date cannot follow the period end. Annual mode issues one grant for that explicit period; without the pair, the previous calendar-year behavior remains. Monthly grants are restricted to the configured window and partial months use the full calendar-month denominator for proration. Once the window ends, no further regular grants are created until an applicable next-period revision exists. Work rewards retain their separate work-date eligibility and credit expiry.

The annual expiry DatePicker submits `annual_cutoff_date`, converted to the existing `fixed_expiry_month` and `fixed_expiry_day` rules at the service boundary. The selected year is intentionally not retained. Actual credit expiry is the first occurrence of that month/day on or after the grant-period end, inclusive. Relative `expiry_months` remains the alternative, and choosing it clears the fixed cutoff. Legacy month/day revisions still resolve; editing a February day 29–31 rule uses a leap-year picker date so future February month-end semantics are not silently shortened to day 28. Previously issued lots are not changed.

Annual revision/assignment boundaries use configured period dates. A blank end on a bounded annual assignment is filled with the period end. The edit form proposes the next explicit period for review when the previous window has ended; it does not silently issue another annual grant. Existing assigned employees must be enrolled in the next annual window. No new tenant migration is required because rules remain revision JSON.

Frappe HR reference: [Leave Period](https://docs.frappe.io/hr/leave-period) uses explicit From/To dates for a calendar or fiscal year. Its [Leave Type schema](https://github.com/frappe/hrms/blob/develop/hrms/hr/doctype/leave_type/leave_type.json) separately supports a carry maximum and expiry duration in days. Bio-Notifier retains its approved monthly expiry duration and adds the requested annual cutoff; these are project policy choices, not a claim that Frappe provides the same recurring-cutoff field.

## Paid-category deduction cleanup — 14 October 2026

`2026_10_14_173000_remove_paid_leave_category_deductions.php` makes category deduction columns nullable, transfers configured unpaid-excess treatment to `unpaid_excess_deduction_method` / `unpaid_excess_deduction_minor` in existing policy revisions and clears both paid-category values without changing category timestamps. Unpaid category rules remain. The LeaveType saving boundary always clears deduction values when paid; details hide paid deductions and the category table removes the column entirely. Apply this tenant migration with the updated code.

Only unpaid-excess-enabled policies show the new controls. Payroll reads the applicable revision for the uncovered paid-category portion, while ordinary unpaid leave uses its category rule. A missing/invalid excess rule blocks calculation. Legacy uncovered paid-category leave without managed allocations requires explicit reconciliation before recalculation. Existing finalized financial snapshots are unchanged.

Manual payroll review signatures exclude the removed deduction fields only inside paid leave category metadata. Older retained review snapshots are accepted through this normalization only after verifying their original stored evidence hash; no stored reviews are rewritten. Genuine leave/profile/attendance/unpaid-category changes still invalidate review. This retains approved historical review evidence across the cleanup without ignoring relevant payroll data.

## 0.2 business rules

Recurring `reset_dates` compute a credit expiry before the next reset after availability; grants on a reset date start a new cycle. `comp_expiry_mode=work_month_end` binds awards to their work month. `LeaveRangeService` previews in a rolled-back transaction and submits daily allocations atomically with a unique request key and coverage signature. Holiday eligibility is effective-dated in separate tenant membership tables. Shift overtime eligibility/rates remain in immutable rule snapshots; approval proposals capture payment rates. Payroll templates copy reviewed defaults only to missing/empty profiles and preserve provenance. See [0.2 business rules](../beta-02-business-features.md).
