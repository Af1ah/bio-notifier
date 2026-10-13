# Pending beta tasks

Updated: 2 October 2026

Only unfinished code, product decisions and verification belong here. Completed local fixes have been removed. Deployment instructions and evidence are in [Beta verification and recovery](beta-verification.md). Local fixes are not yet deployed.

## Remaining code and logical gaps

### CALC-04 — Review and migrate legacy approved attendance

Old calculated days have no input fingerprint, so it is impossible to prove their inputs are unchanged from the stored day alone. A new guard preserves approved legacy results and refuses destructive recalculation. Add an explicit baseline-adoption/review flow before backfilling these days; preserve the decision, its original evidence and history. Do not fabricate a fingerprint from changed current inputs.

Acceptance: an HR-reviewed legacy day can be migrated deliberately; normal recalculation then preserves unchanged approvals and revises changed evidence safely.

### CALC-05 — Handle a trailing open session in device punch mode

Source review found that device-mode pairing returns an earlier completed OUT even if a later IN is still open (for example, IN → OUT → IN). The missing-checkout branch can therefore miss the last incomplete session. Fix only that session; retain earlier worked intervals and breaks. HR checkout edits must not fill non-working gaps between sessions. First/last punch mode is a separate policy and must remain unchanged.

Acceptance: completed sessions plus the trailing open session are counted once, with an approval for assumed checkout and consistent net minutes after HR correction.

### CALC-06 — Select the occurrence for multi-shift time corrections

The current day-level correction form does not select an occurrence. The approval service now refuses ambiguous multi-shift time edits instead of silently changing the first shift. Add an occurrence picker to the existing correction action, bind it server-side to that attendance day, and keep status-only corrections separate.

Acceptance: HR can correct either occurrence without changing another occurrence or its approved overtime.

### PAY-02 — Define eligibility for legacy/manual payroll without assignments

Coverage guards now check every scheduled date with an effective assignment, and late evidence flags derived attendance/payroll. Legacy/manual attendance with no assignment still has no authoritative expected calendar. Decide whether payroll should require an assignment or an explicitly approved manual period. Also document treatment of partial-month employment and status-only HR overrides; do not silently introduce salary proration.

Acceptance: legacy payroll cannot accidentally treat a sparse manually entered month as complete, while intentionally approved manual payroll remains possible.

### CALC-07 — Account for employees removed during a queued run

Source review found another completion gap: run totals are captured before dispatch, but each worker reselects current employees. A deleted or newly excluded employee can leave the run short of its total and still marked running, blocking the next nightly dispatch. Record skipped employee-days explicitly and preserve completed progress when an employee is deleted. Do not silently lower the total or count missing work as a successful calculation.

Acceptance: removing an employee mid-run produces an explained completed-with-skips result, and the next nightly run is not blocked.

### INGEST-01 — Improve receipt failure visibility and invalid-payload summaries

Accepted batches now have encrypted, deduplicated durable receipts, recoverable queue dispatch and explicit failure states. The CLI can replay failed receipts after investigation. Remaining enhancement: a small master-only receipt status/replay view and bounded rejection counters for malformed/decryption-failed input. Do not retain arbitrary invalid bodies, expose credentials or put biometric payloads into diagnostic logs. This is an operational safeguard, not a replacement for queue/scheduler monitoring.

### AUTH-01 — Finish non-update upload and legacy magic-login review

Livewire updates now resolve the tenant from verified snapshot context, reject mixed/tampered contexts, and use the tenant-specific session. Master authentication is pinned to the central database. Check Livewire upload endpoints separately: they do not carry update snapshots and still use the existing request-context fallback. Review whether the unused domain-based `/magic-login` compatibility route should be disabled or share the new single-use grant flow before enabling domain tenancy.

Acceptance: upload/download requests cannot access another tenant's private files; no alternative login route bypasses the intended expiry/replay policy.

## Deferred verification and deployment

- Deploy central and tenant migrations, update each eBio webhook URL, set the exact `Success` acknowledgement, and restart workers. Old signed quick-login links are intentionally rejected; open a freshly generated master link.
- Inspect bootstrap accounts renamed before the system-account migration. Never exclude all admins or a guessed ID. Verify deployed report/export/count consistency.
- Verify two real PostgreSQL tenant databases with overlapping IDs/PINs, authentication, Livewire, master transitions, queue failures/retries, cache, uploads, exports and deletion boundaries. Automated SQLite coverage does not prove PostgreSQL isolation.
- Exercise PostgreSQL concurrent calculation/approval and diagnostic FIFO writers. Code locks/idempotency guards are implemented; real concurrent-writer proof remains deferred.
- Perform the PostgreSQL staging backup/delete/restore drill with central metadata, tenant databases, uploads and the original encryption key. The disposable SQLite restore drill already passes.
- Verify scheduler/worker health, failed-job visibility, provisioning rollback, queue retry timing and capacity. Keep `retry_after` longer than the jobs' 60-second timeout.
- Inventory and rotate Laravel, worker and Nginx file logs by size. Avoid logging webhook credentials or signed-login query strings at the proxy. Assess historical secret exposure before separately approved cleanup/credential rotation. The 10,000-entry FIFO applies to application request diagnostics, not every file sink.
- Confirm legitimate vendor batches/rates and select device policy: discovery remains enabled for compatibility; `EBIO_ALLOW_DEVICE_DISCOVERY=false` requires registered devices. Missing serials use a tenant-local unidentified device, not a cross-tenant lookup.

## Optional feature suggestions

- Add a small payroll readiness summary showing missing dates, stale evidence and unresolved approvals before generation.
- Add configurable retention/reporting for quick-login audit grants and tenant-aware notification deduplication. Delivery retries can currently send duplicate WhatsApp messages; they must never duplicate punches or pay.

These suggestions are not prerequisites for controlled internal testing. Before external beta, complete tenant/security verification; before real salary payments, resolve the remaining calculation/payroll gaps and reconcile sample payroll independently.
