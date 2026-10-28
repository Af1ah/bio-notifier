# Production stamps and attendance rollout

Release: `bio-stamps-20261020-171423`, deployed 20 October 2026. The user authorized both Laravel and Go gateway updates. No Git commit or push was made.

## Backup

Verified backup: `/opt/db_backups/bio-stamps-20261020-171423` on the production server. Custom-format dumps cover `bio_notifier`, both tenant databases and `device_gateway`. Protected archives retain application/private files, gateway files/configuration and the previous Docker image. Archive catalogs and SHA-256 checksums passed. No restore rehearsal was performed.

The gateway image was built in staging before maintenance. Laravel, gateway intake and application workers were paused for the database backups and coordinated upgrade. Existing environment files and service credentials were preserved; their checksums match before and after deployment.

## Changes and migrations

The release includes 41 Laravel files and 12 Go gateway files compared with the previous production deployment. It includes tenant timezone display/correction fixes, shared add-or-correct attendance evidence, immutable raw punches, protected manual decisions during replay, saved ADMS stamps, bounded large uploads, device time sync and typed attendance-clear variants.

Central migrations `2026_10_20_170000_enable_gateway_time_sync` and `2026_10_20_180000_enable_gateway_attendance_clear` completed. Tenant migration `2026_10_20_171000_preserve_raw_attendance` completed for both companies. Both PostgreSQL raw-log update/delete and truncate triggers are present. The Go startup migration added its saved-stamp column and capability support.

Source files at `/var/www/bio-notifier` and `/opt/bio-device-gateway` match the tested release files by checksum. The running gateway image is `sha256:73c0c8b5552786c6229e95ea08910a682702a3cec9ddf98759da9353cd0f2805`. Release scripts, build logs, data manifests and health evidence are retained under `/opt/bio-releases/bio-stamps-20261020-171423`.

## Data preservation and services

Before/after row hashes matched for existing raw punches, corrections, manual attendance and payslips. Secumax retained 912 raw rows and all 10 payslips; ARIISE retained 64 raw rows. Device ownership/version hashes match. No historical attendance or salary data was rewritten by the upgrade.

The gateway reports healthy with two delivery workers and zero container restarts. Gateway ingestion, commands, attendance/notifications, the default eBio worker and the scheduler are running. PHP FPM was reloaded and Laravel configuration, route and view caches were rebuilt. Master, Secumax and ARIISE login endpoints returned HTTP 200.

Secumax remains on eBio and ARIISE on the Go gateway. At verification, all devices' last contacts predated deployment by several hours: ARIISE 11:48 UTC and Secumax 13:07 UTC. No fresh device connection, physical attendance punch, biometric recognition, time-sync or clear-log command was performed. Existing parked/quarantined work remains retained, with 33 parked and 62 quarantined gateway events and 16 parked Laravel receipts. These were not blindly replayed.

## Recovery

The backup retains the prior image ID, source archives and database dumps. Restore into an isolated environment first. Do not overwrite accepted post-release evidence with an older database snapshot. Reverting source alone also removes the manual-replay protections, so a rollback must deliberately preserve those safeguards and reconcile new evidence. Keep the original application and gateway encryption keys.
