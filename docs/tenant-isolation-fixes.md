# Tenant isolation and payroll guard fixes

14 October 2026. Changes are local to the `work` branch; no deployment or commit was performed.

## Fixed

- **Livewire file requests:** upload and preview URLs include a signed tenant ID. Missing scope, altered scope and a contradictory Referer are rejected before selecting the database, session or disk. A Referer is not required to select the tenant. Existing unscoped file URLs are rejected; refresh an open page after deployment to obtain new URLs. Laravel's cached routes retain the scope.
- **eBio provider changes:** new webhooks require the current organisation provider to be eBio. Queued processing independently rechecks the current provider. Rejected queued receipts retain their encrypted payload and become failed for explicit investigation/replay; they are not silently discarded or acknowledged as processed.
- **Database cache:** tenant database-cache keys and locks use separate namespaces, and a tenant flush affects only its cache entries. Taggable stores retain the existing tenancy behavior. This works with the repository's configured database cache without requiring Redis.
- **Tenant lifecycle:** SOAP sync services and eBio jobs use an exception-safe context helper that restores the previous tenant or central context. Webhook jobs also restore their previous context. Success, exceptions and sync failure callbacks are covered.
- **SOAP handling:** all interpolated SOAP text is XML-escaped; response extraction recognizes namespaced XML and rejects malformed responses, SOAP faults and missing result elements. Sync rejection no longer becomes a zero-error success. Failed user push/delete responses fail the job. Requests have bounded connection and response timeouts. Face enrollment retains its legacy fallback and support for valid void result elements.
- **Monthly salary guard:** absent effective assignments no longer let missing dates count as a complete month. Every applicable date must have recorded attendance or an effective assignment whose off-day calendar accounts for it. Missing scheduled attendance continues to block pay. The same coverage check applies to readiness, generation, and transitions of existing monthly slips to reviewed, approved or paid. Historical amounts are not rewritten automatically.
- **Month-end attendance:** date comparisons now include the month's final recorded day, including its absence deduction. Daily/hourly sparse-period behavior retains its existing pay basis.

A complete manual calendar is accepted by the coverage guard; a separate formal HR manual-period approval workflow has not been added. Salary profile effective dates retain their existing treatment. No automatic partial-month proration was introduced.

Each client has a separate eBio server or a tenant-restricted SOAP account, as confirmed by the user. That vendor account boundary is retained; no shared-account location filtering was added.

## Validation

- Full existing and new regression suite: **128 passed, 636 assertions**.
- New tests exercise actual signed upload and preview endpoints, cross-tenant and tampered URLs, cache reads/locks/flush, provider changes with preserved receipts, SOAP serialization/parser/failure handling, nested tenant restoration, enrollment fallback, sparse monthly rejection, legacy-slip status guards and month-end absence deductions.
- Disposable PostgreSQL probe: two tenants with overlapping PINs and device serials passed isolation checks; webhook placement, private paths, cache writes, sparse salary rejection, complete-calendar salary generation, provider guards and tenant context restoration passed.
- Laravel route-cache generation and scoped file-route lookup passed; the temporary route cache was cleared afterward.
- All disposable PostgreSQL organisations, receipts, tenant databases and test storage were removed. Formatting and git diff checks passed.

SOAP responses in automated validation are simulated. No live vendor requests or external notifications were sent.

## Original follow-up items

The attendance and payroll items below were subsequently implemented; their current rules and data-review requirements are preserved in [Attendance and payroll design](attendance-payroll-design.md). The list records the original follow-up scope.

1. **Attendance calculations:** CALC-05 trailing open IN after a completed session; CALC-06 occurrence selection for multi-shift HR time edits; CALC-07 explicit skipped progress when employees disappear during queued runs; CALC-04 deliberate review/adoption of legacy approved evidence.
2. **Payroll policy:** formal manual-period review, partial-month employment/proration rules and status-only HR overrides. Existing sparse monthly slips may now be blocked and require HR investigation; historical approved/paid values are retained.
3. **Compatibility and operations:** review legacy domain `/magic-login` before enabling domain tenancy; master receipt status/replay UI remains an enhancement.
4. **Staging/production verification:** browser authentication and master/tenant transitions, real worker failure/retry sequences, live eBio smoke checks, PostgreSQL concurrent writers and backup/restore drills remain outstanding.
5. **Deployment:** these changes have not been deployed. Clear stale application/route caches and restart workers during the repository's normal deployment procedure. No new database migration or dependency change is required for this patch.

Related tracker: [Pending beta tasks](beta-feature-tasks.md).
