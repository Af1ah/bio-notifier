# Tenant timezone display audit

Date: 20 October 2026. The audit and local fixes were initially held under the user's instruction. The user subsequently authorized the paired Laravel/gateway deployment; these display and correction-form fixes are now deployed in bio-stamps-20261020-171423. Historic raw punches, manual records and payslip hashes remained unchanged. See [rollout evidence](production-stamps-rollout-2026-10-20.md).

## Confirmed cause

Secumax's company timezone and leave-calendar timezone both resolve to Asia/Kolkata. Production log 912 contains the same timestamp in its eBio LogDate and stored punched_at: `2026-10-20 18:37:09`. TenantWallTime correctly reads that value as `18:37:09 +05:30`. Filament's attendance-log column defaults to UTC and renders `13:07:09`. Explicit tenant formatting renders `18:37:09`.

This is a presentation regression after introducing timezone-aware attendance casts without updating all consuming UI components. Do not rewrite punch rows or shift the host/application clock to compensate.

## Local fix

AttendanceLogResource now resolves the tenant timezone when formatting its punch column and hydrating its read-only detail field. The callback is evaluated in the current tenant context rather than fixing the timezone when the application boots. A Livewire regression checks the real list and detail form for Kolkata and Dubai, including unchanged stored punch timestamps. The resource has no create/edit page; this patch does not change attendance entry or payroll calculations.

## Initial findings before the local fixes

| Surface | Finding | Evidence boundary |
| --- | --- | --- |
| Dashboard: Recent attendance | The punched_at time column has no tenant timezone and converts timezone-aware punches to UTC | Confirmed same formatter defect from registered widget source and installed Filament implementation |
| Device detail: Attendance logs | The punched_at datetime column likewise defaults to UTC | Confirmed same formatter defect; relation manager is registered by DeviceResource |
| Dashboard: Action required | Absolute command created_at is displayed in UTC | Confirmed missing tenant presentation setting; command storage should stay UTC |
| Payslip details | Calculated, reviewed, approved and paid timestamps use the default UTC formatter | Confirmed missing tenant presentation setting; no evidence of changed salary amounts |
| Payslip PDF | Calculated-at directly formats a UTC model value without converting to the tenant timezone | Confirmed source behavior; no new PDF was generated during this audit |
| Reports | Last Updates and report/PDF Printed On timestamps use app-default UTC | Confirmed source behavior; underlying report work-time data needs separate evaluation before claiming a calculation defect |
| Branches, Departments, Task Groups | Created/updated timestamp columns default to UTC | Confirmed missing tenant presentation setting |
| Device users tab | Last received uses updated_at without tenant formatting | Confirmed missing tenant presentation setting |
| Dashboard: Device status | last_ping is formatted without tenant timezone, and the Device model has no matching cast/alias; current connection logic uses last_activity_at | Separate stale-field candidate; do not claim a live device-connectivity failure from this widget |
| Attendance correction / missing-checkout approval dialogs | DateTimePicker defaults to UTC, while attendance source casts/services use tenant-local wall times | Requires dedicated hydration/submission round-trip testing. Correction forms fill Carbon values, while the approval dialog fills formatted strings; their behavior must be tested separately. This is potentially an input-boundary problem, not safely classified as display-only |

Existing main Devices and Device Commands screens already request the tenant timezone explicitly. Duration totals and relative labels such as “5 minutes ago” are not equivalent to absolute timestamp formatting. No blanket conversion of every date or time field was made.

## Central solution

Reuse TenantTimezone as the company timezone authority. Tenant middleware should establish and clear company context; queue jobs must initialize/end that context independently. Do not change Laravel APP_TIMEZONE, PHP's global clock or the PostgreSQL session timezone per company in a long-running process.

Introduce a small shared presentation boundary with explicit temporal semantics:

- UTC instants: audit, requested/sent/completed, calculation and payment timestamps. Convert to the tenant timezone for UI/PDF/export; retain UTC storage.
- Local attendance datetimes: preserve the local clock and work date through list, detail and input round trips. Existing TenantWallTime values must not be converted a second time.
- Time-only shift values, dates and durations: preserve their clock/date/quantity. They are not UTC instants. Avoid globally applying the tenant timezone to every TimePicker or time column.

A tenant-aware default for datetime display components can reduce repeated configuration, but input components need explicit storage semantics. Filament DateTimePicker's installed DateTimeStateCast converts submitted values to app timezone; a universal default alone does not prove safe attendance input. Prefer reusable typed datetime fields with conversion rules, shared formatting for Blade/PDF/export, and regression coverage for tenant switching, cross-midnight shifts and unchanged stored attendance.

## Implemented local resolution

AppServiceProvider registers tenant-aware datetime defaults for Filament TextColumn and TextEntry. Each closure resolves the active tenant when formatting; the defaults are registered once during application boot. This covers payslip audit entries, report update timestamps, organisational created/updated columns and command/user audit timestamps without duplicating each page's setting. Explicit component overrides still apply. Central pages with no tenant retain the application timezone. Date-only and time-only values are excluded from this default.

RecentAttendanceLogs explicitly uses the tenant timezone for its time-only punch column. Device attendance details inherit the shared datetime default. DeviceStatus now reads last_activity_at instead of the obsolete last_ping field. PDFs and printable reports use TenantTimezone::formatInstant, and report date defaults follow the tenant's current day, including after local midnight.

LocalDateTimePicker and LocalDateTimeStateCast replace UTC-oriented state conversion only for local attendance datetimes: log details, correction check-in/checkout and missing-checkout approval. They accept existing timezone-aware values and local strings, retain local date/time on submission, and leave invalid input available for normal field validation. Ordinary UTC audit storage, Laravel timezone, shift time-only inputs, duration totals and existing database rows remain unchanged.

## Correction code context for the next requested changes

The correction layout, labels, selected-occurrence behavior and validation remain in app/Filament/Tenant/Resources/AttendanceDays/Tables/AttendanceDaysTable.php. The Correct action fills proposed_in_at and proposed_out_at from the selected occurrence, allows an optional status override and requires a reason. It calls AttendanceEntryService::correct; the service creates a pending AttendanceCorrection and an attendance approval while retaining original device punches. The new local-time field changes timezone handling only.

The missing-checkout dialog remains in AttendanceApprovalsTable.php. It fills checkout_at from the occurrence or proposal and passes the local value to ApprovalService::decide. Approval permissions, revision/shift checks and payroll freshness behavior are unchanged. Keep these action/service boundaries for the user's next correction-page requirements; no additional layout or workflow redesign is included here.

The user explicitly released the deployment hold. The display fixes themselves require no data rewrite; the paired attendance changes added immutable-raw protection migrations. Existing punch, correction and payslip hashes were verified unchanged during deployment.

## Verification of the local resolution

The full Laravel suite passed: **269 tests, 1,618 assertions**. Following final provider cleanup and expanded focused checks, the timezone UI regression passed **4 tests, 67 assertions**. Coverage includes the attendance list/detail, dashboard recent punches and device log relation; dynamic UTC audit formatting across Kolkata and Dubai; unchanged shift clock values and input Carbon objects; local report dates after midnight; overnight correction hydration and submission; invalid correction input rejection; and the exact local checkout value passed to the approval service. Original attendance occurrence and punch values remain unchanged in these tests. PHP syntax and patch whitespace checks passed.

These are local source/Livewire/service checks, not interactive browser or production validation. This follow-up has not been deployed or pushed to Git.
