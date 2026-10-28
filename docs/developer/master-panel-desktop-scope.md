# Master-panel duties and desktop removal scope

Date: 20 October 2026. Status: source-based assessment and desktop implementation scope. No live feature, data, provider or production route was removed by this document.

## Decisions

The Windows desktop edition has no master panel and supports only our Go ADMS gateway. It needs no eBio URL, SOAP credentials, webhook setup, eBio sync jobs or integration-provider selector. The existing hosted product retains eBio until its users have been migrated deliberately; desktop scope does not authorize breaking current eBio production ingestion.

One company is installed per desktop host. Company settings, employees, payroll and device management remain available. The installer replaces central configuration work; a scoped Maintenance page replaces essential recovery work.

Removing the master panel alone will not materially remove payroll processing from the cloud. Its cross-company dashboards query data when opened, but receipt application, calculations and scheduled recovery are separate services. The local-first runtime is what moves that workload off our servers.

## Current responsibilities and their desktop destination

| Current duty | Confirmed implementation | Desktop treatment |
| --- | --- | --- |
| Company creation, editing and status | Master OrganisationResource and form/table | Remove central company list/CRUD. Installer creates the one company; its admin edits ordinary company details locally |
| Company logos, contact details and timezone | OrganisationForm | Keep relevant fields in company settings. Preserve timezone/history safeguards |
| Tenant administrator management | ManageOrganisationAdmins | First admin created by setup. Provide company-scoped admin-account management and a controlled local recovery procedure |
| Master-to-company quick login | OrganisationsTable with QuickLoginService | Exclude from desktop. Normal company login remains |
| Cross-company overview, growth and health widgets | SystemOverview, TenantGrowth, RecentOrganisations, SystemHealth | Exclude from desktop. Show only the installed company's useful health |
| Shared gateway address and management/event credentials | DeviceGateway connection form | Installer/bootstrap generates and configures one local connection. Keep credentials private; do not ask customers to fill a gateway form |
| Binding synchronization retry | DeviceGateway::retryBinding | No binding setup screen. Automatic registration/recovery remains; Maintenance offers a plain “Repair device connection” action if needed |
| Accepted Laravel receipt replay | DeviceGateway::replayReceipt | Keep company-scoped retry in Maintenance; immutable receipts remain authoritative |
| Gateway event replay | DeviceGateway::replayGatewayEvent | Keep controlled recovery without exposing raw credentials or arbitrary cross-company IDs |
| Uncertain device-command resolution | DeviceGateway::resolveCommand | Keep a scoped review action. Never equate “Stop waiting” with undoing an already executed device command |
| Failed command-submission retry | DeviceGateway::retrySubmission | Keep same-ID, bounded recovery with ownership, expiry and ambiguity checks |
| Parked attendance-calculation recovery | DeviceGateway::inspectCalculations / replayCalculation | Move current-company failures/retries into Maintenance |
| Legacy direct ADMS payload CRUD/retry | AdmsPayloadResource | Exclude old payload screens from desktop. They are not the new Go receipt pipeline; retain the Go diagnostics/recovery path |
| eBio configuration and actions | OrganisationForm, SOAP service, webhook route, eBio actions/jobs | Exclude from desktop runtime/UI. Retain hosted support until its planned retirement |
| Audit records | Gateway configuration/recovery methods | Preserve actor/reason/outcome records. Removing a screen must not remove the audit trail |

This inventory does not establish billing or licensing duties: those were not found as master-panel responsibilities in the inspected files.

## Device binding: remove the manual work, retain the routing record

TenantDeviceRegistration already creates the binding during Add device, selects the shared connection and queues SyncGatewayBinding. A separate master approval is not part of the ordinary registration flow. DeviceGateway currently exposes retry/diagnostics rather than a required second device-creation step.

The internal binding is still actively used:

- Serial number maps to the installed company and local Device record.
- Go intake checks that the serial is registered/enabled and uses the recorded timezone and binding version.
- GatewayEventController checks connection, registered ownership and activated binding version before accepting data.
- Commands and delayed replies retain their device/version context; provisioning prevents silent reassignment while operations are active.
- Provisioning failures are durable recovery work, rather than an invisible loss between the application and gateway.

For desktop, Add device should automatically publish its local registration to the local gateway. Call it **device registration** in user-facing copy. No public-IP entry, tenant selection, master approval or separate binding form is needed. A registered serial remains required; being on the LAN is not permission to inject attendance.

The mapping does not require a cloud call in the desktop design. Deleting its tables/services today would break the current Go/Laravel contract. Replacing it with a smaller single-company registry would be a deliberate later protocol/storage refactor, not a safe UI cleanup and not a priority for reducing cloud load.

## Master-panel removal boundary

Implement a desktop deployment policy that does not register MasterPanelProvider, master login, quick-login endpoints or cross-company resources. Block equivalent direct routes and actions server-side and rebuild route/configuration caches. No hidden master account is created.

Before that mode can ship, move required duties to their replacements: one-time company/admin bootstrap, local gateway setup, ordinary company settings and scoped Maintenance. Keep tenant IDs, the internal central database, device registrations, queues, receipts and historical data. Their names are implementation details, not a reason to expose a master panel.

Do not delete the shared hosted master provider globally merely to produce the desktop edition. Hosted multi-company administration still uses it. This assessment authorizes no production deployment.

## Desktop has no eBio support

In desktop mode:

- Force the supported transport to the local Go gateway at the server boundary. Missing configuration must fail clearly, not fall back to the current eBio default in DeviceTransportResolver.
- Remove the provider choice and all eBio setup/photo-refresh/device-sync/enrollment controls. Desktop company creation does not generate an unused eBio webhook credential.
- Do not register `/api/ebio/webhook/{token}` or expose its setup URL. Do not run `ebio:replay-receipts`, eBio token rotation or eBio SOAP jobs.
- Do not require an eBio installation, URL, administrative password, encryption password or vendor eBio runtime.
- Keep the product's Go protocol/photo/template behavior. BIOPHOTO/USERPIC parsing is an ADMS capability; its provenance from eBio investigation is not a reason to delete working Go compatibility code.
- When importing historical eBio data, preserve punches, employee identities and payroll evidence. Require an explicit device cutover and account for old queued jobs; do not silently replay them through a different transport.

Shared screens currently reference eBio action classes and the transport resolver. Isolate optional integration registration before physically excluding its files from desktop artifacts; simply deleting them can cause autoload failures even when a button would have been hidden. Keep a modular eBio provider in the shared repository for hosted users until their later removal is approved and completed.

## Minimal desktop administration

Keep one company-admin Settings area with company details, authorised accounts, timezone, backups, restore and updates. Keep one Maintenance area with local service health, pending/failed work, last attendance received and constrained recovery actions. Devices stay in the existing Devices page; employees and payroll stay in the existing company workspace.

Ordinary users see neither database credentials nor gateway service tokens. The desktop shell is not an alternative authority for privileged API operations: Laravel permissions still apply.

## Implementation sequence

1. Introduce and test the deployment/feature policy; hosted behavior remains intact.
2. Build installer bootstrap for company, first admin and the sole local gateway connection.
3. Provide the company-scoped account/settings/recovery actions that replace indispensable master functions.
4. Disable master and eBio routes, providers, jobs and UI in desktop mode; remove fallback-to-eBio behavior there.
5. Qualify automatic Add device registration, unknown serial rejection, delayed replies, receipt replay, restart and restore on Windows.
6. Produce the desktop artifact with only the enabled integrations after shared references are modularized. No database/credential-history deletion is necessary for the first release.

Acceptance must demonstrate that master/eBio URLs cannot be reached in desktop mode, cloud eBio still works, no provider secrets leak into the EXE, and a fresh offline install can add a device and process attendance without master intervention.

## Source references

- `bootstrap/providers.php` and `app/Providers/Filament/MasterPanelProvider.php`
- `app/Filament/Master/Resources/Organisations/` and `app/Filament/Master/Widgets/`
- `app/Filament/Master/Pages/DeviceGateway.php`
- `app/Filament/Master/Resources/AdmsPayloads/`
- `app/Services/Devices/TenantDeviceRegistration.php`
- `app/Services/Devices/Gateway/ProvisionGatewayBinding.php`
- `app/Http/Controllers/GatewayEventController.php`
- `app/Services/Devices/DeviceTransportResolver.php`, `routes/api.php`, `routes/console.php`
