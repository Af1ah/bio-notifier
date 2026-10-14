# Pending beta tasks

Updated: 14 October 2026

Only unfinished code, product decisions and verification belong here. Completed local fixes have been removed. Deployment instructions and evidence are in [Beta verification and recovery](beta-verification.md). Local fixes are not yet deployed.

## Remaining code and logical gaps

Attendance/payroll follow-up implementation is recorded in [Attendance and payroll design](attendance-payroll-design.md). CALC-04, CALC-05, CALC-06, CALC-07 and PAY-02 now have implemented workflows and regression coverage. Historical records still need real HR evidence review where applicable; that is not an automatic data rewrite.

### INGEST-01 — Improve receipt failure visibility and invalid-payload summaries

Accepted batches now have encrypted, deduplicated durable receipts, recoverable queue dispatch and explicit failure states. The CLI can replay failed receipts after investigation. Remaining enhancement: a small master-only receipt status/replay view and bounded rejection counters for malformed/decryption-failed input. Do not retain arbitrary invalid bodies, expose credentials or put biometric payloads into diagnostic logs. This is an operational safeguard, not a replacement for queue/scheduler monitoring.

### AUTH-01 — Review the legacy domain magic-login route

Livewire uploads and previews now use signed tenant IDs in their routes; missing scope, changed IDs and conflicting request context are rejected. Actual upload and preview endpoint regressions pass, and cached routes retain that scope. Signed update snapshots and separate tenant sessions remain in place.

Remaining: the domain-based `/magic-login` compatibility route still needs review before domain tenancy is enabled. Decide whether to disable it or share the single-use grant flow.

Acceptance: no alternative domain login route bypasses expiry, replay prevention or tenant session policy.

## Deferred verification and deployment

- Deploy central and tenant migrations, update each eBio webhook URL, set the exact `Success` acknowledgement, and restart workers. Old signed quick-login links are intentionally rejected; open a freshly generated master link.
- Inspect bootstrap accounts renamed before the system-account migration. Never exclude all admins or a guessed ID. Verify deployed report/export/count consistency.
- Local disposable PostgreSQL checks passed for tenant provisioning, overlapping PINs/serials, webhook placement, private file paths, tenant cache operation, payroll guards and tenant login rendering. Still verify staging browser authentication/Livewire, master transitions, real worker failures/retries, uploads/exports and deletion/recovery boundaries. Local probes do not prove production isolation.
- Exercise PostgreSQL concurrent calculation/approval and diagnostic FIFO writers. Code locks/idempotency guards are implemented; real concurrent-writer proof remains deferred.
- Perform the PostgreSQL staging backup/delete/restore drill with central metadata, tenant databases, uploads and the original encryption key. The disposable SQLite restore drill already passes.
- Verify scheduler/worker health, failed-job visibility, provisioning rollback, queue retry timing and capacity. Keep `retry_after` longer than the jobs' 60-second timeout.
- Inventory and rotate Laravel, worker and Nginx file logs by size. Avoid logging webhook credentials or signed-login query strings at the proxy. Assess historical secret exposure before separately approved cleanup/credential rotation. The 10,000-entry FIFO applies to application request diagnostics, not every file sink.
- Confirm legitimate vendor batches/rates and select device policy: discovery remains enabled for compatibility; `EBIO_ALLOW_DEVICE_DISCOVERY=false` requires registered devices. Missing serials use a tenant-local unidentified device, not a cross-tenant lookup.

## Optional feature suggestions

- Add a small payroll readiness summary showing missing dates, stale evidence and unresolved approvals before generation.
- Add configurable retention/reporting for quick-login audit grants and tenant-aware notification deduplication. Delivery retries can currently send duplicate WhatsApp messages; they must never duplicate punches or pay.

These suggestions are not prerequisites for controlled internal testing. Before external beta, complete tenant/security verification; before real salary payments, apply the new tenant migration, perform required historical HR reviews, and reconcile sample payroll independently.
