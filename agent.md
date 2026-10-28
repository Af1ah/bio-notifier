# Bio-Notifier agent context

Updated 28 October 2026. Read [the system handoff](docs/developer/system-handoff.md) before device, capacity, database or deployment work, and [Deploy.md](Deploy.md) before a rollout. The handoff contains verified hosts/ports, paths, tenant database layout, actual request destinations, service commands and outstanding tests. Verify drift-prone operational facts before acting.

## Project boundaries

- Laravel source: `/home/aflah/projects/bio-ebio/bio-notifier`; production `/var/www/bio-notifier`.
- Go gateway source: `/home/aflah/projects/bio-ebio/device-gateway`; production `/opt/bio-device-gateway`. Separate Git repositories.
- `/home/aflah/Downloads/eBioServerNew/Iclock` is vendor/reference material, not the app checkout.
- Current architecture: Laravel 12 / Filament 4, PostgreSQL central plus one database per company, separate Go ADMS service and shared gateway database. eBio SOAP/webhooks remain supported per organisation. The old instruction that all device work must use eBio is superseded.

## Implementation and isolation

Use DeviceCommandService and the current transport resolver; do not create parallel command paths. Tenant adds each terminal once, which automatically creates/syncs the binding. Preserve exact serial, company, device ID and binding version checks. Unknown serial requests are rejected by Go; there is no durable unknown-device payload inbox. Registered malformed uploads may enter encrypted quarantine. See the handoff before describing any request as saved.

Initialize the original owning tenant before tenant model queries and end context in finally. Never resolve employees globally by PIN, infer identity from office IP or weaken server-side authorisation to expose a button. Model/algorithm compatibility and registered credential choices are separate checks. A selected fingerprint/face box does not prove transfer compatibility. Source IP restrictions have been removed; a serial is not cryptographic authentication.

Preserve device raw punches and source provenance. Corrections belong in manual/correction records; replay must preserve them. Keep processed salary amounts unchanged during display fixes and late-punch processing. Respect existing approval and stale-evidence guards. Do not shift stored history to repair timezone presentation.

## Redis and capacity

Sizing target: 50 companies, 200 devices, 2,500 employees, 40 concurrent authenticated operators and 20 manual entries. This full scenario has not passed an end-to-end test. Report gateway, PDO, cache, UI, physical-device and production evidence separately.

Production uses PostgreSQL-backed sessions, general cache and queues. Redis is limited to 30-second branch/department/task-group display lists with database fallback. Do not switch global CACHE_STORE, SESSION_DRIVER or QUEUE_CONNECTION casually. Tenant Redis values and locks have tenant prefixes; central locks remain global intentionally. Raw tenant Redis flush is blocked. Redis logical DB numbers do not create independent memory/failure boundaries.

Gateway has a bounded processing/waiting queue and durable ACK-after-commit receipts. It retains the global admission lock; the two admission indexes are deployed and part of startup schema. Do not remove concurrency guards for a benchmark. Test backlog, duplicate/retry, cancellation, tenant fairness and outage/restart behavior when changing intake.

## Work and release rules

Read relevant installed documentation and current files first. Follow the user's current scope, preserve unrelated local changes and report newly found issues. Do not open a browser or perform interactive UI tests unless explicitly requested. No production load test or physical mutation merely to check health. Use isolated databases for destructive tests.

Use reviewed release files, preserve relative paths when syncing, and verify remote paths/hashes. A previous flattened upload into app/ did not update the active classes. Keep production .env, storage, encryption keys and databases intact. Use a backup unless the user explicitly waives it for that rollout. No Git push/main update unless requested. Never print keys, tokens, private payloads or credentials. Ignore rules do not remove historical exposures.

For retained eBio SOAP: use raw XML with Http::send('POST', ..., ['body' => $xml]), correct text/xml and SOAPAction, and exact employee PIN mapping. Configure its URL/credentials from Organisation; do not hardcode them. Do not rotate webhook credentials without coordinating the sender. Existing eBio functionality remains in scope.
