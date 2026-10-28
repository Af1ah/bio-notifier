# Device time sync and attendance replay

Implemented and deployed on 20 October 2026 in release bio-stamps-20261020-171423. See [rollout evidence](production-stamps-rollout-2026-10-20.md).

## Source evidence and manual decisions

`attendance_logs` is the original device evidence. Corrections are stored separately in `attendance_corrections`; newly entered manual intervals remain in `manual_attendance_entries`. Neither form rewrites raw punches. The raw log UI has no delete action. Model guards reject updates/deletes; tenant database triggers reject SQL updates/deletes, and PostgreSQL also rejects TRUNCATE. The sample attendance command refuses nonempty databases and environments other than local/testing.

A device transaction-stamp reset requests another attendance upload; it does not clear application history. Both eBio and Go ingestion retain their existing punch identities for deduplication. Repeated records do not replace the original payload. Newly received records are inserted normally. Duplicate records can also schedule recovery when the employee has no corresponding calculated attendance day or overnight occurrence; each employee/date is checked once per batch and queue signals are deduplicated.

Ordinary days reconcile new evidence normally. A day with manual or mixed source, a pending/approved manual correction, or an approved missing-checkout decision preserves its effective result. Its occurrence identifiers, revision, work time, overtime and approvals survive new raw evidence and recalculation. This deliberately protects the whole day; it does not silently recompute other shifts on a manually decided day. Changing that result requires an explicit manual action through the existing approval flow.

The calculator records a separate observed input signature for later evidence. It retains the original calculation baseline, so rejecting a pending manual proposal permits reconciliation from raw punches. A pending correction remains pending during a resend. No upload automatically approves a correction.

A replay on a protected manual day must not invalidate its existing processed payslip. A newly repaired, unprotected day can legitimately mark payroll for review through the existing freshness rules; this does not automatically rewrite paid financial values.

## Shared attendance form

The header action is **Add or correct attendance**. Employee/date selection loads raw evidence using the same shared correction schema as the row action. Existing days submit a correction; dates without a day create a manual interval. The server verifies the selected mode again on submission and refuses a stale selection. Overnight shift evidence includes the following day inside the shift window. Evidence is limited to 100 displayed punches with a truncation message.

A pending attendance approval overlays **Waiting for approval** in the table. The actual calculated status remains intact and is shown as supporting text. Rejecting or approving the proposal clears the overlay naturally.

## Device time sync

The Add device form saves **Device time zone** separately from the organisation timezone. **Sync time** uses that owned registration. It queues a normal gateway command and calculates the current device-local clock when the command is delivered, avoiding an old enqueue-time clock.

The command follows the checked local upstream example: `SET OPTION ServerLocalTime=YYYY-MM-DD HH:MM:SS`. Caller-supplied time parameters are rejected. Unknown device profiles remain blocked. A device acknowledgement is transport evidence; physical clock behavior still needs confirmation on each firmware family. This command does not shift historical punches or change the organisation timezone.

## Verification and release requirements

The application suite passed 275 tests with 1,693 assertions before the final duplicate-only recovery test was added. Focused replay tests cover duplicate uploads, missing raw punches, missing derived days, approved manual checkout preservation, pending correction rejection, raw immutability, eBio/Go ingestion and a paid payslip snapshot. The complete gateway workflow file then passed 35 tests with 302 assertions, and the final replay/seeder checks passed four tests with 33 assertions. The Go PostgreSQL race suite passed 48 tests including timezone conversion, dispatch-time clock generation, acknowledgement and capability migration. PostgreSQL update/delete/truncate triggers were checked in an isolated schema and rolled back. Go vet and build passed; the build disables VCS stamping because this local gateway directory has no usable Git metadata.

Release requires the paired Go gateway update, central capability migration `2026_10_20_170000_enable_gateway_time_sync`, and tenant raw-protection migration `2026_10_20_171000_preserve_raw_attendance`. The capability upgrade preserves ownership/version and only extends bindings that already permit user updates and option reads. No historic raw data needs rewriting. Production deployment completed after user authorization. No physical-device time-sync test was performed.
