# Web application: master-panel cleanup and removal plan

Date: 20 October 2026. Status: deployed on 20 October 2026 as part of the full application and gateway upgrade. Scope: the existing hosted web application, not the Windows desktop edition. The user explicitly retained administrator management, magic links and eBio. No company database or historical evidence is deleted by this cleanup.

## Intended result

Reduce the master panel to two purposes: company lifecycle administration and essential platform health/recovery. Remove duplicated company screens, unnecessary polling, legacy administration and routine server configuration forms. Company administrators use their existing workspace for devices, employees and payroll.

Keep the underlying ownership, credential, queue, receipt and recovery services. A master screen is not the service it controls. Removing unused interface features should not interrupt device communication or erase accepted evidence.

Do not remove the entire master panel yet: the web product still needs company provisioning, first-administrator setup and controlled platform recovery. Removing those functions requires replacement workflows, not just deleting their navigation.

## Findings before cleanup

- SystemOverview opens every company's database and counts devices/employees. SystemHealth does the same for pending/failed commands. Both poll every 30 seconds while their widgets are active.
- Those two widgets alone perform four tenant count queries per company per refresh, excluding connection changes and central queries. This grows with company count and open operator sessions. No production throughput benchmark was performed for this plan.
- RecentOrganisations and TenantGrowth also poll every 30 seconds despite showing relatively slow-changing information.
- DeviceGateway's queue board has `wire:poll.30s` even though the section is collapsible. Its getViewData loads bindings, receipts, submissions, grouped queue counts, company names and runtime states on each render; collapsing a section does not make those server queries lazy.
- Master organisation detail pages duplicate company Devices and Users lists.
- Ordinary Add device already calls TenantDeviceRegistration, creates the ownership record and queues synchronization. There is no required second master device-registration approval in the current source.
- Quick login is generated through an action URL closure that invokes QuickLoginService::issue. Filament evaluates that closure when resolving the action URL, and issue writes a login grant. Consequently link rendering can create grants before a click; the current flow is not a simple read-only link.
- The older AdmsPayload administration, parser and processing job remain alongside the newer Go ingress/receipt path. The inspected application route files expose the Go internal-event endpoint and eBio webhook, not a corresponding old payload-ingress controller. This is a retirement candidate, not proof that production has no pending old jobs or externally registered routes.

## Implemented UI and load cleanup

| Feature | Cleanup action | Replacement / constraint |
| --- | --- | --- |
| Tenant growth chart | Remove widget and its polling | No operational replacement needed |
| Recent organisations dashboard widget | Remove duplicate widget | Companies list already supports finding organisations |
| Global employee/device count widget | Remove cross-tenant scans | Keep a cheap company count only if useful; company pages own employee/device counts |
| Global pending/failed command scan widget | Remove tenant-by-tenant scan | Use existing central health/receipt/submission summaries for the platform; company command pages show company outcomes |
| Master organisation Devices/Users tabs | Remove duplicate operational listings | Ordinary company workspace remains authoritative; a plain Open company link does not impersonate its administrator |
| Registered-device/binding table in master Attendance service | Remove routine inventory and manual retry UI | Company Devices shows local registration state and scoped repair; internal registry remains |
| Live polling of collapsed delivery board | Remove automatic polling and unconditional detail datasets | Show central health summary on demand; load paginated error details only after choosing a filter |
| Organisation bulk delete and routine Delete actions | Remove destructive shortcuts | Separate audited decommission procedure with backup and retained-data checks; do not drop company databases as UI cleanup |
| Manual Create/Edit of legacy raw ADMS payloads | Unregister resource and deny direct resource access | Retain legacy job readers and evidence until the retirement gate below |

The first four widget removals are direct source-confirmed opportunities to reduce administrative database work. They do not eliminate the much larger work of receiving punches, calculating attendance and producing payroll.

## Replacement duties and retained exceptions

| Feature | Replacement required first | Removal boundary |
| --- | --- | --- |
| Master gateway URL/token editor | Deployment-managed, validated configuration with a controlled operator command | Remove ordinary configuration form; retain encrypted GatewayConnection data/runtime access. Do not create conflicting environment/DB configuration sources |
| Master registration retry | Scoped company repair plus existing bounded recovery jobs | Remove its master action only after failed provisioning can be recovered elsewhere |
| Receipt/event/submission/calculation replay scattered across one large page | Small operator-only recovery workflow; company-specific actions separately authorized | Consolidate UI and shared recovery services; do not delete accepted receipts, ledger or retry checks |
| Master Manage Admins | Retained by explicit user decision | Existing administrator management remains available |
| Master company profile editing | Retained alongside company Settings | No schema or ownership changes in this cleanup |
| Integration selector and eBio settings | Retained by explicit user decision | eBio remains supported; migration is a later task |

Service secret changes remain explicit, coordinated operations. Removing the editor must not rotate keys, replace webhook URLs or reset vendor/device stamps. The recent Secumax interruption demonstrates why an authentication change needs a matching external configuration step.

## Administrator management and magic links stay

Manage Admins, signed support login and the existing magic-login route remain. The company-list login action now submits an authenticated POST with CSRF protection. Only that explicit action issues a short-lived login grant; rendering the company list does not create grants. Existing issuance and consumption checks remain in QuickLoginService.

## Current implementation boundary

The master panel explicitly registers only the company resource. Its landing page redirects to Companies, with no polling dashboard widgets. Old widgets remain on disk but are not discovered or registered. Old duplicate employee/device Livewire components reject direct invocation, and the legacy raw-payload resource denies access. Their files are retained for a later dependency cleanup; this is not a claim that all historical runtime code was deleted.

System status uses central summaries on explicit refresh. Receipt details load only on request, are filtered to the selected gateway and paginate 25 rows. It retains restricted receipt/event/submission/calculation recovery without exposing raw biometric payloads or service secrets. There is no automatic diagnostic polling.

Company Devices offers Repair connection for an enabled registration with an error or no synchronization timestamp. Repair verifies the company, device ID and serial, audits the request and queues the existing synchronization job. It does not reassign ownership, increment the binding version or reset attendance stamps.

## Operator gateway configuration

The ordinary gateway URL/token editor has been removed. The encrypted GatewayConnection record remains the runtime authority. An operator can update it using:

```sh
php artisan gateway:configure /secure/gateway.json --connection=1
```

The private JSON file contains `name`, `management_url`, `device_server_url` and `enabled`. `management_url` must match the operator-owned `DEVICE_GATEWAY_ALLOWED_ORIGINS` allowlist. For an existing connection, omit `management_token` and `events_token` to retain saved credentials. A new connection requires both tokens. Keep the input file private and outside the repository.

Changing an existing token requires the explicit `--rotate-secrets` flag and coordinated gateway configuration. The command does not print secrets; its audit records changed field names only. This cleanup does not rotate any service key, APP_KEY or eBio webhook credential.

When this local batch is eventually deployed, clear stale Filament discovery with `php artisan filament:clear-cached-components` and rebuild the project's normal route/view/config caches. Review the complete release batch first; this document is not authorization to deploy it.

## Legacy PHP ADMS removal gate

Treat AdmsPayloadResource, ProcessAdmsPayload and their parser/response/command helpers separately from Go receipts and eBio webhooks.

Before deleting legacy runtime code:

1. Inventory actual deployed routes, web-server forwarding, producers and queued/failed jobs by class. Inspect counts and dates without dumping raw biometric payloads.
2. Confirm no active device is still routed through the old PHP path. Redirect and validate through Go where necessary.
3. Preserve encrypted backups and inspect pending/failed legacy payloads. Reconcile accepted punches into the correct company without blind duplicate replay; quarantine unresolved evidence.
4. Remove the legacy UI, route registration and producer first, then drain or explicitly retire old serialized jobs before removing their classes.
5. Delete unused parser/helper code and any exclusive package dependency only after a reference audit. Keep shared code still used by another transport.
6. Retain old database records until the agreed evidence-retention period and recovery checks are satisfied. A table drop is a later, separately reviewed migration.

Do not remove the current Go GatewayIngressReceipt, GatewayCommandSubmission, gateway queue connector, ApplyGatewayReceipt, ApplyDomainEffect or gateway:recover under the label “old ADMS”. They are the active architecture.

## Device registration stays automatic

Remove the visible engineering term “binding” and the duplicate master management flow. Keep this ordinary company workflow:

**Add device → validate serial ownership → create local Device/registry records → synchronize gateway automatically → show Ready or Needs attention.**

The registry is still required for company/device routing, timezone interpretation, enabled state, binding versions and delayed command/reply ownership. The gateway serves multiple companies on the web. Deleting that registry or accepting arbitrary serials would break the contract and isolation.

No public-IP entry or manual master approval is needed. If a device moves between companies, it remains an explicit audited reassignment with in-flight work handled first; it must not silently attach to the last company that entered the serial.

## eBio is a later removal, not part of immediate cleanup

For the web application, retain eBio for companies still using it. Its integration selection, setup forms, SOAP actions and webhook endpoint remain available. Removing eBio is explicitly outside this cleanup.

Removal requires all of the following:

- Identify every active eBio company and migrate its actual device endpoint/configuration to Go deliberately.
- Verify real punches, employee mapping, required user/template operations and recovery of the missing interval before changing its provider flag.
- Drain or reconcile eBio webhook receipts and queued/failed eBio jobs. Do not lose old accepted work or replay ambiguous commands through another provider.
- Move current employee/photo/attendance evidence where needed without deleting historical payroll data.
- Remove eBio routes, scheduler tasks, UI actions, transport/provider branches and credentials only after no company depends on them.
- Replace nullable/missing-provider fallback-to-eBio with an explicit supported configuration after data migration. Do not reinterpret old null records as Go during an unrelated release.

Keep Go FP/FACE/BIODATA/BIOPHOTO/USERPIC support: implementing it using knowledge from eBio does not make it an eBio dependency.

## What the smaller web master panel keeps

**Companies:** a paginated company list, deliberate provisioning, stable ID/shortname, first-admin/recovery controls and platform-owned lifecycle settings. Administrator management, magic login and existing company profile editing remain available.

**System status:** central worker/scheduler heartbeats, oldest unapplied work, bounded error counts and explicit filtered recovery. No all-company database scan every 30 seconds and no automatic download of every detail table.

Gateway addresses/secrets become deployment concerns. Devices, employees, attendance and payroll stay in their company workspace. Preserve operator authentication, least privilege, audit records, company ownership and backups.

The current `status` field must not be assumed to suspend every access path: the inspected shortname tenancy middleware resolves a company without checking it. Define and enforce any future suspension/decommission semantics separately; a cosmetic status value is not a replacement for authorization or safe deletion.

## Implementation order

| Phase | Scope | Exit evidence |
| --- | --- | --- |
| Immediate UI/load cleanup | Remove dashboard duplicates/scans, repeated company lists and automatic diagnostic polling; remove destructive delete shortcuts | No tenant-wide queries on master dashboard; normal company actions and ingestion unchanged |
| Replace essential administration | Operator configuration command and scoped company connection repair | Configuration retains saved tokens; company ownership is enforced |
| Slim master | Remove duplicate binding controls; consolidate recovery; retain administrator management and magic login | Removed UI inaccessible directly; active Go registration and operator recovery covered by regression tests |
| Retire old PHP ADMS | Route/producer/job/evidence gate, then code cleanup | No abandoned queued work, missing accepted punches or device still using retired path |
| Retire eBio later | Per-company tested migration and job/receipt reconciliation | No remaining eBio consumers; complete recovery evidence before endpoint shutdown |

For each implementation phase, separate removal of UI/routes from destructive schema changes. Review the diff, run affected authorization/tenancy/ingestion/recovery tests, back up the affected state and deploy one coherent phase. Do not combine this cleanup with automatic secret rotation, schema flattening or wholesale device reassignment.

## Source basis and limits

Reviewed current local MasterPanelProvider, master resources/widgets, DeviceGateway and its Blade view; routes/web.php and routes/api.php; QuickLoginService; TenantDeviceRegistration; ProvisionGatewayBinding; GatewayEventController; ProcessAdmsPayload and associated services; current provider resolver and scheduler.

Observed code establishes dependencies and query patterns. It does not establish actual production traffic, abandoned-record counts or customer reliance on support login. Those checks are explicit gates before deletion, not claims that every candidate is already unused.

## Local verification

After implementation on 20 October 2026, the complete Laravel suite passed: **263 tests, 1,558 assertions**. The focused authentication, company-boundary, gateway recovery and eBio regression run passed **70 tests, 452 assertions** before the full run. New coverage checks grant issuance only on explicit POST, inaccessible retired screens, lazy filtered receipt pagination, credential-preserving operator configuration and company-scoped registration repair without a binding-version change. PHP syntax and `git diff --check` also passed.

The local test run did not include browser or physical-device verification. The subsequent production rollout is recorded in [20 October deployment](production-rollout-2026-10-20.md). No Git commit/push, company deletion or historical-table removal was performed.
