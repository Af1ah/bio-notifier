# Beta verification and recovery

## Local verification

Latest local run, 2 October 2026: **94 tests passed, 445 assertions**. Formatting and diff checks passed. No browser, real-device, PostgreSQL concurrency or deployed-server verification was performed in this run.

Run from the application directory:

```bash
php artisan test --compact
php artisan test --compact tests/Feature/TenantBackupRestoreTest.php
```

Tests force a testing-only, in-memory SQLite central database and reject other configured connections before destructive fixtures run. Tenancy tests provision disposable tenant databases using the application's tenancy bootstrappers. Do not run these commands against a deployment checkout with cached production configuration; the guard will reject it.

Coverage includes authentication, tenant switching, webhook validation and credential rotation, secret-free request diagnostics, 10,000-event FIFO retention, duplicate punch immutability, employee scope, calendar navigation, attendance/break boundaries, approvals, payroll, and queue workflows. Existing tests and new regression cases are complementary; passing them is not a blanket security guarantee.

Employee CSV coverage includes the actual Filament upload/preview/confirm wizard, visible validation errors, payroll decimal conversion, create/update preservation, 1,000/1,001-record boundaries, whole-batch rollback, protected fields/accounts, SQL-like text handling, formula rejection, scoped paths, reviewed-file fingerprints, assignment staleness and abandoned-file retention. See [Employee CSV import](employee-csv-import.md) for templates, supported fields and scheduler requirements.

## Backup, destroy, restore drill

`TenantBackupRestoreTest` creates two disposable tenants with overlapping PINs. It backs up tenant A's database using SQLite `VACUUM INTO`, central organisation/domain metadata, and a tenant-local asset. It deletes only A, restores those exact assets, verifies the password hash and webhook credential, and checks that tenant B remains isolated and unchanged. It cleans up only resources created by the test.

This proves the local lifecycle, not PostgreSQL recovery. Before external beta, run a separate staging drill with PostgreSQL dumps of the central and tenant databases, a private upload archive, and the original `APP_KEY` stored securely. Restore into an isolated environment, never over a live tenant. Check authentication, encrypted credentials, attendance, approvals, payroll, uploads, and sibling-tenant isolation. Record recovery duration and backup age. A missing encryption key cannot be fixed by restoring the database alone.

## Deployment requirements for these fixes

Take verified backups first and pause workers during the schema rollout. From the deployment application directory:

```bash
php artisan down
php artisan migrate --force
php artisan tenants:migrate --force
php artisan optimize:clear
```

For each existing organisation, generate its separate webhook credential:

```bash
php artisan ebio:rotate-webhook-token ORGANISATION_ID_OR_SHORTNAME
```

Copy the new webhook URL from the master organisation form into eBio. Its configured Webhook Response must be exactly `Success` (case-sensitive). Organisation-ID webhook URLs are now rejected. Coordinate this change with eBio delivery; do not reset the vendor's historical sync stamp casually. Newly created organisations receive a credential automatically. Rotation revokes the old credential immediately.

Set `LOG_STACK=daily` and a suitable `LOG_DAILY_DAYS` in the deployment environment; an existing explicit `LOG_STACK=single` overrides the new default. Configure size-based rotation for worker and Nginx sinks separately. Request diagnostics store only bounded method, route name, status and timestamp, retaining the latest 10,000 events centrally. This does not cap raw attendance data or every external/file log sink. Old oversized logs are not deleted by these changes; assess exposure and clean up exact approved files separately.

```bash
php artisan config:cache
php artisan queue:restart
php artisan up
```

Check worker health and queued/failed jobs after rollout. The persistent system-account migration marks the known bootstrap email `admin@zkteco.local`; if it was renamed before migration, explicitly identify and mark that account after inspection. Never exclude all admins or a guessed numeric ID.

## Remaining release boundaries

See [pending beta tasks](beta-feature-tasks.md) for only unfinished work. No production deployment is performed by this local verification.

## Additional code safeguards

Unchanged calculation fingerprints preserve occurrence IDs, HR checkout corrections and approved totals. Changed evidence creates a new revision. Legacy approved days without a fingerprint are preserved and blocked from automatic recalculation until explicitly reviewed/migrated. Calculation and approval locks use a consistent day-before-approval order. Run-item uniqueness records completed work once, atomically with its calculation, so retries resume instead of double-counting.

Payroll generation now checks coverage of effective scheduled dates and rejects stale results. Late punches flag affected days and existing salary snapshots, then queue attendance refresh for historical dates. Changed decisions also flag payroll. Reviewed/approved/paid monetary amounts are not automatically rewritten. Draft payroll must be regenerated after resolving its readiness issues. Dates without an effective assignment still need the explicit legacy/manual policy listed in the backlog.

Nightly calculation revisits the previous three dates to catch overnight cutoffs and late delivery. WhatsApp delivery runs separately with a 10-second HTTP timeout and bounded job retries. Calculation and receipt jobs have a 60-second timeout; configure the database queue's `retry_after` above that value. The database queue defaults to the explicit central connection rather than inheriting an active tenant connection.

Valid webhook receipts are encrypted with the application key and deduplicated by organisation/payload hash. Acknowledgement means a receipt was persisted, not that every punch has already processed. The unresolved backlog limit is 1,000 receipts per organisation; a full backlog returns 503 for vendor retry. Completed receipts clear their temporary payload after raw punches are stored, and completed metadata is pruned after 30 days. Pending/failed receipts and raw tenant punches are not pruned by this maintenance.

The scheduler runs receipt recovery every minute. After investigating failed batches:

```bash
php artisan ebio:replay-receipts --failed
```

Automatic recovery retries pending dispatches and releases queued/processing leases older than ten minutes. Failures remain available for deliberate replay. Enable strict registered-device handling with `EBIO_ALLOW_DEVICE_DISCOVERY=false` only after provisioning the expected devices; discovery remains enabled by default for existing installations.

Master quick-login links now use five-minute, single-use grants. Central grant rows record issuer, target, expiry and consumption time without storing the raw token. Deleted/demoted master actors cannot consume outstanding grants. The central and tenant migrations must finish before using new links. Livewire update tenant selection verifies the installed framework's snapshot HMAC before choosing a tenant; normal Livewire authorization, CSRF and release-token checks remain active.
