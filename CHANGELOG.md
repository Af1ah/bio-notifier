# Changelog

## 2026-10-13 — eBio integration merged into main

This entry covers the existing eBio branch changes merged into `main` and the corrections made during integration. Matrix bridge changes were excluded.

### Device and gateway integration

- Added shared device transport contracts and implementations for eBio and gateway devices.
- Added tenant device registration, gateway connection management, and device binding configuration.
- Added command submission, receipt storage, background processing, monitoring, and recovery/replay commands.
- Updated eBio webhook processing and added durable webhook receipt records.

### Attendance, payroll, and imports

- Updated attendance calculation, approval, queued recalculation, and attendance refresh handling.
- Added payroll freshness tracking when attendance data changes.
- Added employee CSV import services, validation, documentation, and import cleanup.

### Administration and security

- Added a Device Gateway management page.
- Updated organisation, device, command, user, and attendance administration screens.
- Updated tenant authentication, Livewire tenant handling, quick-login grants, and request diagnostics.
- Added database migrations, gateway settings, and queue configuration for these workflows.

### Integration corrections

- Fixed gateway punch processing to match attendance records by calendar date, correctly marking affected attendance and payroll calculations as stale.
- Removed the tracked `testing` SQLite database from the main source tree and added it to `.gitignore`.

### Validation and release scope

- Full automated suite passed: **116 tests, 586 assertions**, using the dependencies specified by `composer.lock`.
- Expanded test coverage includes gateway workflows, employee imports, attendance, payroll, tenant authentication, security boundaries, and backup/restore.
- Merge commit: `0c99a0b` (`Merge eBio integration into main`).
- Attendance correction commit: `daf1e20` (`fix: invalidate gateway attendance using date-aware lookup`).
- These checks establish source-level integration; no production deployment or database migration was performed as part of the merge.
