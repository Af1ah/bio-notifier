# Windows desktop edition and release plan

Date: 20 October 2026. Status: proposed implementation plan; no Windows binaries, installer or updater have been implemented or certified by this document. Production deployment remains postponed.

## Product scope

Deliver one Windows setup program that installs the existing payroll application, the existing Go ADMS gateway and a local PostgreSQL service. A desktop EXE opens the company workspace. Attendance collection and queue processing continue after the window closes and before a user signs in to Windows.

The primary objective is to move ordinary attendance/payroll processing off our servers and keep customer cloud usage small or absent. See the [semi-offline and fully offline comparison](local-first-options.md) for the two product modes.

The desktop edition has **no master panel and no eBio support**. It uses only our Go ADMS gateway. The hosted product keeps eBio until its later migration. See the [master-panel duty map](master-panel-desktop-scope.md) for exact removal and replacement boundaries. Start with one company per host, keeping the existing tenant boundaries internally. Several client PCs can later connect to that host; they must not each create a separate attendance database.

Use the same ADMS routes, serial ownership, employee PIN handling, templates, command acknowledgements, durable receipts and payroll rules as the hosted product. Keep changes in shared code behind an explicit deployment mode, not a separate payroll fork. A Windows binary does not add device dialects or SDK support automatically.

Initial qualification target: Windows 11 x64. Qualify Windows Server 2022/2025 separately. Do not promise every Windows version, ARM machine or biometric terminal. Installation needs administrator rights; ordinary payroll use does not. No Docker, WSL, Go, Composer or Node installation is required on the customer's PC.

## Components

| Component | Proposed implementation | Responsibility |
| --- | --- | --- |
| BioNotifier.exe | Small C# WinForms shell using WebView2; self-contained publish | Opens the existing local Laravel/Filament interface, print/download dialogs and service-status screen |
| BioNotifierGateway.exe | Existing Go gateway with a Windows service entry point | ADMS intake, durable device queue, results and delivery |
| BioNotifierHost.exe | Small Go Windows service host | Supervises Caddy, PHP FastCGI, bounded queue workers and one scheduler; captures health and bounded logs |
| BioNotifierDatabase | Dedicated PostgreSQL Windows service | Central application database, company database and separate gateway database |
| BioNotifierUpdate.exe | Signed, short-lived elevated updater | Validates packages, backs up, coordinates maintenance, migrates and switches releases |
| BioNotifier-Setup-VERSION-win-x64.exe | Signed Inno Setup installer | Prerequisite detection, service registration, directory permissions, shortcuts and uninstall |

The EXE contains a browser shell, not a rewrite of payroll. PHP and the compiled web assets still run locally. Self-contained publishing must include the actual native dependencies; do not describe the package as a dependency-free single EXE.

Use bundled, pinned PHP NTS x64 with the required extensions and Caddy/FastCGI. Do not ship `artisan serve` as the production server. PHP documents NTS for FastCGI; Caddy documents the PHP gateway configuration. [PHP Windows builds](https://www.php.net/manual/en/install.windows.manual.php), [Caddy PHP FastCGI](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi).

Use WebView2 Evergreen with its offline installer included in the complete setup, detecting an existing supported runtime first. Offline sites need an explicit runtime-update process; Evergreen does not deliver updates without connectivity. Bundle all application fonts, scripts and styles needed offline. [Microsoft distribution guidance](https://learn.microsoft.com/en-us/microsoft-edge/webview2/concepts/distribution).

## No master panel

Add a server-side `DEPLOYMENT_MODE=desktop` configuration boundary. In desktop mode, do not register MasterPanelProvider, master authentication pages or master organisation-management routes. Clear/rebuild route and configuration caches during installation. Direct master URLs must return 404 or an explicit denial. Hiding links is insufficient. Hosted mode keeps its existing panel.

| Existing master responsibility | Desktop replacement |
| --- | --- |
| Create company and first administrator | One-time setup wizard creates the company, timezone and tenant administrator |
| Configure gateway connection and service secrets | Installer/bootstrap configures the sole local gateway; secrets remain outside the frontend |
| Add and own devices | Existing company Add device flow automatically binds serials to the installed company |
| Inspect receipts and worker failures | Company administrator Maintenance screen shows local service state, queue age, parked receipts and scoped recovery |
| eBio integration | Excluded from desktop: no provider choice, SOAP credentials, webhook route, setup URL or eBio jobs |
| Backup, restore and updates | Company administrator controls the desktop maintenance workflow; elevated helper performs privileged steps |

Retain the central database and tenant database internally. Removing the panel does not justify flattening the tenant schema or changing company UUIDs. Do not create a default master account. The setup wizard's provisioning endpoint is disabled after success and protected by a one-time local installation authorization; an arbitrary web request must not create companies or administrators.

Branches, departments, employees, shifts, leave, attendance and payroll remain in the company workspace. No customer sees central gateway configuration or database passwords.

## Service lifecycle and Windows workers

Register auto-start services with Windows Service Control Manager, dependencies, restart backoff and shutdown deadlines. The current Go command listens for POSIX-style process signals; add Windows service-control handling that cancels its existing context and drains/shuts down listeners. Use platform-specific entry points and retain the current Linux behavior. Go provides Windows service APIs. [Go Windows service package](https://pkg.go.dev/golang.org/x/sys/windows/svc).

Keep PostgreSQL as a separate service, starting before the host and gateway. The host supervises its own child processes with Windows Job Objects; it must never kill another application's PHP or PostgreSQL process by executable name. Closing BioNotifier.exe does not stop services. Stopping a service uses bounded drain then child-tree termination.

**Worker timeouts require an implementation change.** Installed Laravel Worker only registers alarm handlers when PCNTL exists. PHP PCNTL is unavailable on Windows. Merely copying `queue:work --timeout=60` does not enforce that deadline. [PHP PCNTL installation](https://www.php.net/manual/en/pcntl.installation.php).

For the first Windows release, supervise `queue:work ... --once` child processes per lane, with a hard process-tree deadline and restart backoff. Start with existing gateway lanes plus the default application/payroll lane, excluding eBio jobs; central durable rows remain authoritative. A proposed short-job process limit is 90 seconds, below the current 120-second queue visibility and 180-second application claim leases. Keep network/database timeouts below that budget. Qualify these values on Windows and explicitly handle default jobs whose timeout is absent. Long exports must be bounded/chunked or assigned a lane with coordinated visibility, claims and process deadlines; do not extend a watchdog alone.

A watchdog kill does not prove a device mutation failed. Preserve same-ID retries, delivery receipts, outcome-unknown states and dependency guards. Verify Windows process termination before queue visibility expires; recovery still needs idempotency and fencing. Use one scheduler instance, invoking `schedule:run` each minute with non-overlap and restart protection. Disable overlapping Task Scheduler or second-service instances.

## Local networking

| Surface | Default boundary |
| --- | --- |
| Payroll UI | `http://127.0.0.1:8787`; desktop shell uses the configured company landing route |
| PostgreSQL | Loopback only, product-specific port selected during preflight, for example 55432 |
| Gateway management and FastCGI | Separate loopback-only ports; never exposed to the LAN |
| Device ADMS | LAN listener, preferably port 80 for devices without a port field; existing `/iclock/*` and `.aspx` aliases |
| Optional additional payroll workstations | Explicit LAN mode with authenticated HTTPS and trusted certificates; disabled by default |

Detect port conflicts before installing. Never stop an existing web server or another PostgreSQL instance to obtain a port. Desktop does not depend on eBio. Where a device accepts only a hostname without a port, an occupied port 80 requires a deliberate host/address choice; changing to 8080 is not automatically compatible.

Installer firewall rules are limited to the ADMS listener on the intended private network profile. Private network/firewall policy is distinct from the removed per-device public-IP allowlist. Preserve company/serial ownership, but do not claim serial numbers cryptographically authenticate a plain-HTTP terminal.

Show the actual LAN address/hostname and connection status in the existing device setup screen. Recommend a DHCP reservation or stable local DNS record. A sleeping or powered-off host cannot receive punches: device buffering/replay and its capacity must be tested. This is an always-on host installation, not a promise of uninterrupted collection from a closed laptop.

## Storage and portability

Keep installed code under `C:\Program Files\BioNotifier\releases\VERSION\`. Keep persistent state under `C:\ProgramData\BioNotifier\`: configuration, PostgreSQL data, tenant storage, private biometrics, logs, backups and update journals. WebView2's per-user browser data belongs under that Windows user's local application-data directory.

Stable launchers/services resolve a protected active-release manifest. An elevated updater switches it only after validation; it does not overwrite running executables. Update the stable launcher itself through an installer operation with the affected service stopped. Runtime PHP/OPcache caches must not mix releases; restart the appropriate children and build caches for the selected release.

Run services under least-privilege identities with directory ACLs. Use separate database roles: application runtime, gateway runtime restricted to its own database, and an installer/migration role for creating tenant databases. The gateway must not read payroll tables. Migration privileges are not passed into the desktop shell. Provision PostgreSQL under its service identity, not as a payroll user or unrestricted administrator.

Keep APP_KEY, gateway encryption keys and service tokens stable across patches. Protect local configuration with ACLs and suitable Windows protection. Export portable encrypted backups with a separate recovery secret: machine-bound protection alone cannot restore keys on another PC. Never build an installer containing customer secrets or live databases.

Move a site using logical database backups, private storage and wrapped encryption keys, plus an installation manifest. Preserve company UUID, employee PINs, device serials, receipts, wire-ID sequence and command ledger. Restore to a staging host, validate, then stop the old host before redirecting devices. Do not copy a running PostgreSQL data directory or run two active collectors against one restored identity. The target host rewraps secrets for its service identity.

## Build-script contract

Create these files during implementation; they are not supplied as runnable scripts by this plan:

```text
packaging/windows/
  build.ps1                 # compile and assemble releases
  installer.iss             # installer definition
  runtime-lock.json         # pinned tools, runtimes, hashes and licenses
  release-manifest.schema.json
  verify-package.ps1
  smoke-test.ps1
  templates/                # runtime/service configuration templates
```

Proposed build invocation:

```powershell
.\packaging\windows\build.ps1 -Version 0.2.0-beta.1 -Architecture x64 -Channel beta
```

The build runs on a clean Windows release runner:

1. Require explicit Laravel and gateway source revisions. For a release, fail on a dirty working tree; optionally label a developer-only package clearly. The gateway currently lacks usable independent Git metadata, so establish a tracked source/release boundary or record its complete source digest. Do not silently bundle whichever sibling folder exists.
2. Read the runtime lock. Verify downloaded/prefetched PHP, PostgreSQL, Caddy, VC runtime, WebView2, .NET/tooling and installer inputs. Offline builds use the same verified artifact cache. Pick tested supported versions, not “latest” URLs.
3. Install locked PHP dependencies on the actual Windows PHP runtime; run platform checks without ignoring missing extensions. Use `npm ci` and build assets. Include production dependencies and built assets only; no customer `.env`, test data or Linux dependency binaries.
4. Compile the existing gateway and the Windows host. The installed Go module currently declares Go 1.26.0: pin and qualify that toolchain. Publish the desktop shell for win-x64 with its actual runtime dependencies.
5. Assemble immutable component directories, checksums, source versions, schema requirements, package inventory and compatibility metadata. Build production caches only when installation-specific configuration is available.
6. Run source regressions plus Windows packaging/service smoke tests. Sign owned EXEs and the installer; sign the release manifest and update package with the release key. Verify third-party redistribution conditions, including any future vendor SDK DLLs.
7. Produce a full setup, a signed patch package and release/compatibility notes. The full setup includes prerequisites; patches carry only changed application/runtime components. Prefer complete changed-component archives initially over complicated binary deltas.

Example outputs are `BioNotifier-Setup-VERSION-win-x64.exe`, `BioNotifier-Patch-FROM-TO.bnpkg` and a signed release manifest. Inno Setup provides command-line compilation/signing support. [Compiler documentation](https://jrsoftware.org/ishelp/topic_compilercmdline.htm).

## Install and update flow

Setup performs OS/architecture, disk, permissions, ports and existing-installation checks. It installs prerequisites and services, generates unique secrets, creates databases, runs migrations, and launches first-company setup. No shared default administrator password. The user then adds devices through the existing company workflow.

Updates can arrive from a beta/stable update feed or a manually imported offline patch. Default to user-approved installation outside payroll processing, with release notes and an estimated maintenance window. Check availability online without forcing an Internet dependency for normal payroll.

A signed manifest includes product/channel/architecture, package hash/size, source and target version bounds, component versions, database schema compatibility and required disk/backup space. Embed the trusted update public key in the installed updater; HTTPS and a checksum from the same untrusted download are insufficient. Validate signatures before extraction, reject path traversal and prevent unapproved downgrades. Keep signing private keys out of customer packages.

Update sequence:

1. Acquire a machine-wide update lock; validate the package and current version. Stage files separately and check space before touching active services.
2. Check attendance, payroll and queue health. Drain bounded application work and block new writes/commands during maintenance. Leave ADMS intake running only while its current binary/schema remains compatible and backlog capacity permits it.
3. Back up every affected database, private assets, configuration and wrapped keys into a recoverable checkpoint. Prove backup success; record state in a resumable update journal.
4. Stop consumers before incompatible migrations. If the gateway database also changes, explicitly stop its service for that step; devices must retry after the short intake pause. Do not acknowledge a punch before durable acceptance.
5. Run named, ordered central/tenant/gateway migrations with the migration role. Record compatibility and completion. Existing startup Go migrations must gain an explicit preflight/migrate path for coordinated upgrades; do not start a new gateway against an unplanned schema.
6. Atomically select the new release, rebuild runtime caches, start PostgreSQL/dependent services in order, and verify UI, scheduler, receipt application, queues and database access.
7. Resume writes, keep the previous package and checkpoint for the retention window, and display the installed version and update outcome.

Prefer additive expand/contract migrations so an application rollback remains compatible. Destructive migrations and encryption-format changes require an explicit recovery plan. Restoring a pre-update database can discard punches accepted afterward: retain and reconcile durable events; do not automatically restore old data just because a UI health check fails. Stop on an uncertain migration rather than making the installation look healthy.

PostgreSQL major upgrades are separate maintenance releases with supported dump/restore or pg_upgrade procedures. Never treat a major-version replacement as an ordinary application-file patch. [PostgreSQL upgrade guidance](https://www.postgresql.org/docs/17/upgrading.html). PostgreSQL supports a native Windows service lifecycle. [pg_ctl](https://www.postgresql.org/docs/current/app-pg-ctl.html).

## Desktop security and ordinary operation

The shell opens only the approved local application origin or configured trusted LAN host. External links open in the default browser. Restrict navigation, downloads, file access and any native bridge; normal Laravel authentication and CSRF still apply. Never put database credentials, gateway management tokens or arbitrary shell execution behind a JavaScript bridge. Use a private per-user WebView2 profile. [Microsoft WebView2 security guidance](https://learn.microsoft.com/en-us/microsoft-edge/webview2/concepts/security).

Closing the window leaves payroll services running. The launcher reports “Starting services”, “Ready” or a specific failed dependency, with a scoped diagnostic export that redacts biometric payloads, passwords and webhook URLs. Provide Backup, Restore, Check for updates, Import update and Open logs through administrator controls. Uninstall removes the product's services/rules/shortcuts and retains customer data by default; deletion requires a separate explicit action.

The local installation remains the company write authority. Local-only mode has no cloud data sync; semi-offline mode may upload bounded, opt-in summaries/status as defined in the comparison. Do not point the same company at two active payroll systems or imply full cloud synchronization. Hosting its own ADMS endpoint does not automatically migrate devices still pointed at the cloud.

## Implementation order and acceptance

| Stage | Deliverable | Exit check |
| --- | --- | --- |
| Desktop application boundary | No master routes; one-company bootstrap; tenant maintenance | Direct master URLs unavailable, administrator boundaries enforced, hosted mode unchanged |
| Windows runtime | Service-aware Go gateway, host, PHP/FastCGI, PostgreSQL | Fresh reboot without user login processes a real punch through tenant attendance |
| Desktop EXE | Existing workspace in WebView2 | Login, biometric photo/camera permissions, PDF/CSV downloads and printing work |
| Installer and portable backup | Full offline setup and restore | Clean PC installation and restore to a second PC preserve identities and encrypted data |
| Signed updates | Online/offline patch import, migration journal, rollback rules | Upgrade interrupted at each checkpoint resumes or fails safely without lost accepted punches |
| Pilot | Real firmware and ordinary payroll workloads | Overnight attribution, duplicate/replayed uploads, worker hangs, disk-full, network loss, service crash and power interruption verified |

Windows-specific gates include worker-deadline enforcement before lease expiry, file locks during updates, ACLs/service accounts, paths with spaces/non-ASCII characters, port conflicts, offline WebView2 provisioning and no secret exposure. Restore testing must include encrypted photos/templates and queued or outcome-unknown commands. Run a real-device pilot before declaring parity with the Linux deployment.

Keep PostgreSQL for this edition. Replacing it with SQLite would change both the Go storage implementation and tenant/concurrency behavior; it is not a smaller packaging change. The existing local helper scripts also hardcode Linux paths and development database names and cannot serve as the Windows installer.
