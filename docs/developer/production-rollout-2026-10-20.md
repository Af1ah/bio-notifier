# Production rollout: 20 October 2026

The user explicitly approved the full Laravel and Go gateway batch, including database upgrades. The application is deployed at `/var/www/bio-notifier` and the gateway at `/opt/bio-device-gateway`. No Git commit or push was made by this rollout.

## Backup and upgrade

Verified backups are retained in `/opt/db_backups/bio-full-20261020-112204`. They include custom-format dumps of the central database, both company databases and the gateway database; application files and private biometric storage; gateway source/configuration and the previous Docker image. Existing secrets are retained in protected backup archives. Logs, dependencies and generated caches were excluded from the application archive. Database archive catalogs and SHA-256 checksums were verified; no restore rehearsal was performed.

The central encryption backfill and both tenant credential/photo and data-receipt migrations completed. The gateway completed its startup schema/protection migration and runs the new image. Laravel discovery, route, configuration and view caches were rebuilt, and PHP 8.4 FPM was reloaded. Application and gateway environment-file checksums are unchanged. Existing payslip row hashes were identical before and after migration.

Four legacy central commands stored an extra JSON layer around array parameters. The initial backfill rejected these. ProtectedArray now unwraps that legacy layer once; invalid scalar data is still rejected. The backfill then resumed successfully without dropping commands. New source directory permissions and the optimized Composer class map were corrected before completing migrations.

## Background processing

The default worker remains for eBio and existing jobs. Dedicated gateway ingestion, command, and attendance/notification lanes and a supervised Laravel scheduler are running. The previous 64 queued attendance calculations drained. Gateway recovery queued bounded device-information discovery through the normal application workflow.

The gateway reports healthy with two delivery workers. Fresh application queue and scheduler heartbeats were observed. Secumax remains on eBio; ARIISE remains on the Go gateway. Existing webhook/service credentials were not rotated.

## Verification and limits

- The full pre-deployment Laravel run passed 263 tests and 1,558 assertions.
- After the legacy-data compatibility fix, its tests and the gateway workflow suite passed 33 tests and 273 assertions.
- Gateway PostgreSQL/race coverage passed 43 tests immediately before rollout.
- All deployed application files were syntax/checksum checked; production encryption backfill left no unprotected central command parameter rows.
- Master, Secumax and ARIISE login endpoints returned HTTP 200. The retired payload route returned HTTP 404. Only the company resource is registered in the master panel.

No new physical punch, face/fingerprint recognition or interactive browser session was performed. Existing parked/quarantined evidence remains available for deliberate investigation; it was not blindly replayed. At verification, the application still had 16 parked receipts and one parked submission; the gateway retained 33 parked and 62 quarantined events from earlier dates.

Encryption migration rollback requires matching files, databases and original keys. Do not restore an older plaintext-only application against the upgraded data, or overwrite accepted events accumulated after the backup.
