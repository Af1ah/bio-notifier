# eSSL SDK comparison and product extension report

Review date: 19 October 2026
Audience: product owner and engineering team
Scope: supplied DeviceCommunication and K990 SDKs compared with the current local Go gateway and Laravel application. This report lists remaining work after the local P1 source fixes. Resolved findings have been removed. Software verification and deployment instructions are recorded separately in [P1 implementation notes](p1-remediation-2026-10-19.md). These changes are not a fresh hardware certification or a production deployment.

## Main conclusion

Keep the current Go gateway and Laravel application. Their durable receipt, command queue and company ownership boundaries are useful foundations. Improve their device compatibility model before adding more loosely translated commands.

Add an optional local SDK connector for functions that a device exposes through the direct SDK but cannot perform through its ADMS firmware. It should feed the same cloud services and employee screens. Do not replace the gateway with the supplied desktop demos or assume a COM method name is an ADMS command.

The remaining work is firmware qualification, authoritative enrollment inventory, event-driven reconciliation and optional direct SDK coverage. Useful business extensions include photo evidence, health/capacity monitoring, reliable backfill, local enrollment, offboarding reconciliation and access-control functions.

## Sources and what they actually establish

| Reference | Material reviewed | What it establishes | Important limitation |
|---|---|---|---|
| SDK-TFT | DeviceCommunication/TFT_SDK_Manual.pdf; especially real-time events, fingerprint methods, attendance photos, device status and online enrollment | Documented COM API contracts and parameter meanings for the described family | July 2012 manual, based on SDK 6.2.4.1. Not a current MAGNUM/AI-face compatibility certificate |
| SDK-IFACE | DeviceCommunication/IFACE_SDK_Manual.pdf; especially sections 4, 5.2.5 and 5.2.6 | Legacy iFace templates, SDK privilege values, fingerprint flags and events | Legacy face SDK uses face index 50 for the complete face template set; that is not a universal AI-face template slot |
| SDK-BW | DeviceCommunication/B&W_SDK_Manual.pdf; event/data/communication and short-message workflows | Older terminal capabilities and non-SSR methods | Older numeric-ID and SDK variants need their own adapter |
| DEMO-USER | UserInfoMainTFT.cs, UserInfoMainIFACE.cs, UserInfoMainBW.cs and project configuration | Actual caller sequences, batching and version-dependent method selection | Desktop examples, including old .NET/x86 COM configuration, not a hosted server architecture |
| DEMO-K990 | DownLoadPictureAndAttlog/WindowsFormsApplication2/Photo.cs | Direct LAN connection, attendance-photo listing/download/count/clear and date-bounded attendance reads | The photo example retrieves attendance snapshots, including failed verification photos; it does not establish enrollment/profile-photo export |
| DEMO-NEWLOG | DownLoadNewattlog/TimeAttLog/Form1.cs | ReadNewGLogData followed by SSR_GetGeneralLogData | The SDK's internal new-record state is not our durable receipt watermark |
| APP / GO | Current files listed in the evidence index below | What our application currently implements | Source-level conclusions; optional direct SDK methods have not been executed during this review |

The K990 package contains native DLLs and C# examples. I did not find a separate PDF method handbook inside that folder. Its behavior was checked against the samples and the TFT handbook. The folder name alone does not prove every included method works on every K990 firmware.

## Communication paths we should distinguish

| Path | Connection initiator | Where it runs | Current position |
|---|---|---|---|
| Go ADMS/PUSH | Device calls our listener and polls for commands | Hosted Go service | Main supported cloud device path |
| eBio | Existing provider and its APIs/webhooks | Provider plus Laravel integration | Retained provider path |
| Vendor COM SDK | Connector opens a connection to device IP/port, then invokes SDK methods | Compatible Windows host near the devices, or with private network reachability | Not integrated as a supported transport |
| Existing DirectDeviceService | PHP opens a ZKTeco UDP connection on port 4370 | Wherever that PHP service executes | Source exists, but no current app/routes call sites were found in this review; it is not selected by DeviceTransportResolver |

A Linux cloud server cannot reach an office private IP simply because it knows the address. Copying zkemkeeper.dll into the Go image does not create a usable Linux transport. A local connector with outbound cloud communication avoids customer public-IP entry and inbound office port forwarding. A private network/VPN is another deployment choice, not something to require ordinary tenant users to configure.

## What our system already gets right

- Serial bindings map devices to companies; tenant PINs can overlap without needing globally unique employee numbers.
- The Go service commits encrypted inbound evidence before acknowledging accepted uploads, with replay and quarantine support.
- Laravel accepts a durable central receipt before applying it to the correct company; stable IDs and transaction markers prevent repeated effects.
- Typed commands, parameter validation, expiry and per-device sequencing are much safer than an arbitrary command text box.
- Attendance records preserve status 255 rather than guessing check-in/out direction.
- The latest parser accepts legacy FP/FACE and unified BIODATA records. Unified face algorithm fields are now retained.
- Private photo storage and authorized profile rendering avoid a public biometric-photo URL.
- Cancel queued work and Stop waiting are distinct from undoing a device action. This distinction should remain.
- Once-only missing-template discovery avoids querying the device on every punch.

These are design strengths, not evidence that all models, routes or credential formats are supported.

## Confirmed problems and incomplete implementations

The eight original P1 source findings have been removed following implementation and local regression verification. The remaining findings below are product and compatibility work; local test results do not establish physical recognition or universal firmware support.

### P2 — Photo retrieval and photo identity are not the same feature

The K990 methods GetPhotoNamesByTime, GetPhotoByName and GetPhotoCount retrieve attendance photos. The first returns separate groups for successful and failed verification photos. This cannot be relabelled “get current enrollment photo.” GetUserFaceStr returns a template, not a photograph.

The employee profile displays the saved JPEG separately from face templates. MAGNUM BIODATA reads return recognition templates; they do not establish current enrollment-photo export. The authenticated eBio photo method and received BIOPHOTO/USERPIC images remain supported.

**Change:** maintain independent photo assets with purpose and provenance: uploaded enrollment JPEG, device-exported enrollment image where verified, attendance success snapshot and failed verification snapshot. Store source/time/remote filename and last confirmed content hash. Show unsupported photo export honestly. The TFT SendFile documentation separately names user pictures as `<user-id>.jpg` and refers to ReadFile, but that reference is not a documented read signature or proof of enrollment-photo export. Investigate that path with the vendor SDK rather than guessing device file paths. Add a separate request for enrollment-photo export only after that model and route are verified.

**Evidence:** DEMO-K990 Photo.cs lines 153–239; SDK-TFT section 5.2.8, PDF pages 61–63; APP-PHOTO, APP-DOCS.

### P2 — A hardware label is being confused with an employee enrollment state

No fingerprints enrolled is different from no fingerprint sensor, unsupported read command, offline device and read timeout. Our command/result model mainly has generic failure and timeout states. A once-only discovery marker prevents repeated punch fetches but does not describe these different outcomes.

**Change:** retain fingerprint support on an empty device. Add enrollment/read outcomes: enrolled, confirmed empty, unknown, unsupported dialect, rejected parameters, temporarily unreachable and incomplete read. Offer manual refresh and controlled recovery. Never clear saved credentials simply because a fetch returned nothing.

**Evidence:** APP-BIO, APP-APPLY, APP-EFFECT; SDK fingerprint read methods return success/data separately from capability detection.

### P2 — Verification method labels and automatic discovery cover too few modes

AttendanceLog labels only 0, 1, 2 and 15; other values show Unknown. SDK-IFACE describes simple verification codes and a separate combined-mode table, including face plus fingerprint/card/password. The automatic missing-template fetch only handles verification values 1 and 15. It also checks `face` device support, so an AI-face-only configuration may miss discovery.

**Change:** use a profile-specific raw-code dictionary with preserved raw values and normalized modalities. Do not globally change code 2 from Card to PIN merely because a different SDK mode uses that meaning. Show “Unmapped method (code N)” when uncertain. An observed combined modality can trigger a bounded inventory fetch only where the source profile confirms the interpretation.

**Evidence:** APP-LOG, APP-APPLY; SDK-IFACE section 4.2.5, PDF pages 18–19.

### P2 — Displaying INOUT does not make every shift calculation support it

The log label for 255 is correct. AttendanceCalculationService's `device` pairing mode, however, accepts only status 0 as entry and 1 as exit. Status 255 is ignored in that mode. The other pairing branch uses first/last punches; it is not a complete alternating-punch algorithm with multiple breaks.

**Change:** make the employee/shift pairing policy explicit: device directions, first/last, or a separately implemented alternating sequence. Warn when an unspecified-direction device feeds a direction-required shift. Define handling for break and overtime states 2–5. Preserve raw punch direction and avoid rewriting 255 into guessed check-in/out values.

**Evidence:** APP-LOG; APP-CALC::pair, line 288 onward. This is conditional on shift pairing configuration, not proof that all MAGNUM attendance is miscalculated.

### P2 — User identity and permissions need a protocol adapter

The SDK manuals use different privilege values from our tested ADMS `Pri=14` administrator path; SDK-IFACE documents 3 for administrator. Our Go user projection only contains PIN/name/card and does not return device enabled state or privilege. Push sends name/PIN/privilege and optionally card, but no typed per-device enabled-state reconciliation.

**Change:** keep web access role, employee active state, device user enabled state and device administrator privilege separate. Normalize user/admin roles internally, then map them per transport/profile. Preserve raw readback and show a difference before applying changes. Add enable/disable/expiry where the device genuinely supports it. Do not globally replace 14 with 3.

**Evidence:** GO-USER, GO-WIRE, APP-BIO, APP-APPLY; SDK-IFACE sections 5.2.3.7–5.2.3.8, PDF pages 30–32.

### P2 — Querydata is only a small compatibility slice

Our endpoint accepts tabledata for biophoto only. It does not provide a general Security PUSH query result router for user, biodata, counts or options. The current typed AI-face fetch uses the attendance-style BIODATA query instead, so there is no selectable general modern table query encoder in the current operation set.

**Change:** add a typed, bounded query/result abstraction only for verified dialects. Validate command ownership and requested table/PIN before applying a query response; validate all packets, row counts, empty results and duplicate responses. Supporting the route name alone is not complete Security PUSH support. The supplied COM manuals do not establish those HTTP wire contracts; obtain the matching PUSH specification for implementation.

**Evidence:** GO-QUERY, GO-WIRE. This finding comes from source review, not from translating a COM method into HTTP.

### P2 — Imported biometrics from older direct paths use another schema

DirectDeviceService stores fingerprint payload under `template`; EmployeeBiometrics expects `tmp`. The current transport resolver selects only eBio and Go; the direct service is not a supported SDK connector just because its source exists.

**Change:** audit whether any historical employee rows use that representation, provide a tested schema migration/adapter if they do, and route all future providers through one canonical credential model. Avoid reviving the direct service as a parallel untracked write path.

**Evidence:** APP-DIRECT, APP-BIO, APP-RESOLVER. Existing stored production rows were not inspected in this report.

### P2 — Event-driven changes and recovery are incomplete

The SDK exposes OnEnrollFingerEx, OnNewUser and OnDeleteTemplate, plus door/alarm events. The main Go OPERLOG classifier currently tries user rows, biometric rows or photos; it is not a general enrollment/deletion/audit event decoder. A one-time fetch after a punch cannot detect every later finger replacement or deletion.

**Change:** normalize supported enrollment/deletion/privilege events. Invalidate a credential inventory on an event and request a bounded delta/snapshot. Keep once-only punch discovery as a fallback; do not introduce a database request or device fetch for every punch. A connector should subscribe to supported events, coalesce bursts and reconcile periodically after reconnect.

**Evidence:** GO-PROTOCOL; SDK-IFACE sections 4.1–4.2. The older separate AdmsRequestParser contains some OPLOG parsing but is not the main Go path and must not be assumed to cover it.

### P2 — Performance, deduplication and payload limits need deliberate reconciliation

Go admission uses a global advisory transaction lock and counts undelivered/quarantined work for admission limits. This is simple and conservative but can become a shared throughput bottleneck. A persistently parked tenant can consume its intake budget. No new peak-rate benchmark was run.

Go accepts template content up to roughly 1 MiB, but the application/gateway transfer path limits individual raw templates to 8192 characters. An accepted read can therefore produce an asset that cannot be transferred through the UI. Body limits must also account for Base64 and JSON overhead.

**Change:** benchmark before replacing the admission scheme; use indexed/accounted budgets and bounded quarantine retention. Define one validated size contract per representation, and display read-only/transferable status when limits differ. Test outage recovery and noisy-device fairness as well as normal steady-state traffic.

**Evidence:** GO-ACCEPT, GO-PROTOCOL, GO-WIRE, APP-BIO, APP-EVENTS.

## Features the SDKs reveal that are useful for business

These are documented opportunities, not claims of universal ADMS support. “Connector” means a device-compatible direct SDK implementation and an actual pilot.

| Feature | SDK evidence | Business value | Recommended priority / path |
|---|---|---|---|
| Device capacity and health | GetDeviceStatus: users, templates, attendance logs, remaining capacity, face counts; GetFirmwareVersion/GetDeviceTime | Warn before logs fill or enrollment stops; reduce branch support calls | First tranche; verified ADMS metadata or connector |
| Bounded attendance backfill | K990 ReadTimeGLogData; ReadNewGLogData + buffered iteration | Recover missing days after outages without replaying the whole device | First tranche; existing receipts/duplicates retained |
| Enrollment readiness report | ReadAllUserID, template reads, user enabled state | HR can see who lacks enrollment on each required device | First tranche; no per-punch polling |
| Credential change reconciliation | OnEnrollFingerEx / OnDeleteTemplate / OnNewUser | Refresh changed fingers and remove stale enrollment inventory | First tranche where events are available |
| User/device drift report | User/card/privilege read APIs | Identify missing users, wrong privilege, stale card and inconsistent device enrollment | First tranche; preview before apply |
| Controlled offboarding | User disable/delete functions; individual template delete methods | Remove access consistently across branches, including offline devices on reconnect | First tranche; track desired versus observed state |
| Attendance-photo evidence | GetPhotoNamesByTime/GetPhotoByName/GetPhotoCount | Help resolve attendance disputes and suspected proxy punching | Optional; strict permission, retention and clear purpose |
| Failed-verification diagnostics | K990 separate failed-photo list; SDK verification events | Help staff with repeated failed recognition without confusing attempts with successful attendance | Optional; restricted support workflow |
| Remote assisted enrollment | StartEnrollEx, OnEnrollFingerEx, CancelOperation, StartIdentify | HR starts enrollment for the right person/slot without navigating every terminal | Later pilot; local connector where supported |
| Commissioning/restore | Read roster/templates, batch writes and RefreshData | Replace a failed terminal or onboard a new branch faster | After credential compatibility and recovery are reliable |
| Batch provisioning | BeginBatchUpdate/BatchUpdate | Faster large employee onboarding over direct SDK | Later connector optimization; progress/readback required |
| Door and alarm monitoring | OnDoor / OnAlarm; SDK event codes distinguish door held, unexpected opening, tamper, duress and anti-passback | Useful for offices, warehouses and security services | Separate access-event stream; not payroll attendance |
| Access schedules and groups | Get/SetTZInfo, user groups, group time slots, unlock combinations | Restrict access by site/time and support multi-person access rules | Optional access-control module; separate from shift/payroll rules |
| Work codes / project allocation | Work code in attendance event and SDK work-code functions | Job/project cost allocation for service, contracting and factory clients | Store raw now; add reviewed costing rules later |
| Terminal messages | SetSMS/SetUserSMS in older family manuals | Deliver branch notices or employee-specific reminders | Lower priority; terminal text limits and support vary |
| USB/assisted fingerprint enrollment | Biokey conversion guidance and fingerprint read/write APIs | Enroll once at HR, then distribute only to algorithm-compatible terminals | Later reader-specific connector; raw finger images are not templates |
| Clock-drift monitoring | GetDeviceTime, SetDeviceTime/SetDeviceTime2, DST APIs | Diagnose wrong punch times and support overseas customers | Read/alert first; controlled write and audit later |

A notification that a device disconnected is not a safety system. Door/duress functions need business-specific handling and device acceptance tests before being advertised for security operations.

## Recommended architecture

```text
Tenant user -> existing employee/device screens -> typed operation service
                                              -> desired-state/sync job
                                                  | ADMS adapter -> Go queue -> device polls
                                                  | eBio adapter -> existing provider
                                                  | SDK connector -> local SDK session -> device
All inputs -> authenticated durable receipt -> normalized event -> tenant application
                                              -> attendance/payroll or access/enrollment domain
```

One product workflow can support multiple transports. It does not require one protocol encoder for every terminal.

### Local SDK connector

Use a dedicated compatible Windows service for the vendor COM libraries, matching the deployed SDK architecture. Let it initiate authenticated outbound communication, receive only jobs for its approved company/device bindings, and keep a local durable outbox for events and read results. Use per-device serialized sessions and bounded jobs; recover interrupted operations without blindly resending mutations.

Subscribe to selected SDK events, and use periodic reconciliation/backfill after reconnection. Do not assume callbacks alone are a durable attendance source. A connector should never create tenants, choose arbitrary company IDs, change payroll, or accept arbitrary DLL method invocation from a tenant form. SDK-TFT section 6.7 warns that setting user information with a null password can invalidate password verification. Read/merge fields correctly in that adapter; do not assume every SDK setter has ADMS-style partial-update behavior. Keep any device verification secret separate from the web login password.

The bundled demos disable a device during some reads and do not use comprehensive finally-based recovery. A production adapter must keep disruption bounded and always attempt to re-enable a device after an operation that disabled it. Test SDK calls in an isolated process where a hung vendor call can be recovered without stopping every device. Respect the vendor SDK redistribution/license terms; the supplied folders do not establish those rights.

### Canonical data boundaries

- **Device profile:** identity, platform, firmware, transport, protocol dialect, supported operations and evidence-backed capabilities.
- **Device credential:** employee mapping, origin, type/slot/index/version/flags, encrypted payload and content hash.
- **Credential observation:** which device returned which credential, when, under which request, and whether the inventory was complete.
- **Photo asset:** purpose, source, capture/export time, remote filename, content hash, permissions and retention.
- **Device user state:** desired and observed PIN/name/card/role/enabled/expiry per device.
- **Device operation:** parent job plus typed child commands and distinct device, data and application outcomes.
- **Event:** attendance success, failed verification, enrollment, deletion, administration, door or alarm. Keep access events separate from payroll punches.

An employee PIN may repeat across companies. Enforce company ownership at the connector and gateway as well as the Laravel boundary. Within one company, a shared PIN is treated as the same employee; if customers reuse a PIN for different people on different devices, introduce an explicit device-user mapping instead of silently merging them.

## Security and operational boundaries to preserve

The user-requested removal of public-IP checks should stay. Requiring each office's public IP would recreate the setup problem. However, serial ownership is a routing rule, not cryptographic proof of device identity. Plain HTTP ADMS devices need a deliberate risk model; compatible local connectors can provide an authenticated protected uplink without requiring the terminal itself to speak modern HTTPS.

Keep management private, separate service credentials, restrictive per-tenant permissions and rate/size limits. Do not place device administrative passwords in ordinary UI responses. Add operation audit actor, reason, source, requested credentials and terminal outcome without raw biometric material. Record who cancels/stops a command and preserve its later physical outcome if observed. “Stop waiting” remains a local lifecycle action; SDK CancelOperation specifically ends an enrollment state and is a different typed device operation.

Before enabling attendance photos or failed-attempt photo collection, define customer purpose, access roles and retention. Avoid indefinite storage of images of failed or unknown users. This is a product/storage requirement, not a jurisdiction-specific legal conclusion.

## Simplified user experience

Keep Employee → Device access as the single place to inspect and manage a person's device credentials.

1. Select the device. Show verified capabilities, its last observation and any unknown support.
2. Show separate rows for Card, Fingerprints, Conventional face, AI-face template and Saved photo. For each: local availability, device enrollment, compatibility and last successful read/write.
3. Offer Read from device, Register, Push selected and Remove from device. Do not create another generic sync button with unclear direction.
4. Default Card as requested; leave biometric assets unselected until explicitly chosen. Show only compatible assets, not just compatible sensor labels.
5. After a read, show “3 templates saved”, “No fingerprints enrolled” only when confirmed, “Read incomplete”, or “Photo export unavailable on this firmware.”
6. Group a push into one job with a small result panel. The dependency guard already prevents template dispatch after a failed user creation. Add a consolidated result panel and recovery of only the missing steps.
7. Distinguish Cancel queued, Stop waiting and Cancel enrollment. Explain what remains possible on an offline or already-executing terminal.

Device → Health should show connection, intake freshness, oldest unapplied receipt, clock drift, counts/capacity, firmware/profile and unresolved operations. “Online” must not imply attendance has been applied to payroll or that enrollment/photo reads are supported.

## Rollout order

| Stage | Work | Exit evidence |
|---|---|---|
| Deploy local P1 corrections | Follow the separate migration and key-retention notes | Verified backups, staged migrations, supervised workers and post-deployment receipt checks |
| Qualify device profiles | Firmware-specific acceptance evidence for the enforced families; supported vs enrolled; raw/normalized role and verify codes; authoritative empty reads | F22/K30/MAGNUM/iFace acceptance matrix with firmware, route and readback evidence |
| Pilot local connector | One approved SDK/OS/architecture, one office, bounded roster/template/attendance reads and outbox | Reconnect/restart/offline replay without duplicate payroll effects; company isolation proven |
| Add recovery and business value | Backfill, inventory/drift, offboarding, replacement restore, health alerts | Per-device desired/observed reconciliation and resumable jobs |
| Optional advanced modules | Attendance photos, assisted enrollment, access schedules/events, work-code costing | Customer purpose and support scope defined; physical behavior and failure cases tested |

Do not advertise universal support after a single model succeeds. Keep firmware-specific differences behind adapters rather than adding another special case to every employee form.

## Acceptance tests before calling this complete

| Area | Required cases |
|---|---|
| Identity | Two companies with the same PIN; forged/mismatched binding; leading-zero and maximum supported PIN; duplicate serial reassignment |
| Credentials | No enrollment; one/multiple fingers; same No with different Index; changed/deleted finger; invalid/duress flag; v9/v10 mismatch; conventional face vs type 9; missing algorithm metadata |
| Photos | Enrollment JPEG vs captured attendance photo; pass/fail groups; exact returned PhotoLength; oversize/truncated/bad JPEG; unavailable photo export; private access and retention |
| Commands | Rejected query vs empty inventory; positive ACK but no data; multi-part response out of order; last packet without earlier packet; duplicate data; downstream parked receipt; late result after Stop waiting |
| Workflow | User upsert fails before template transfer; device offline halfway through a batch; cancellation before submission and after send; connector restart during SDK call |
| Attendance | 0/1 directions, breaks/OT, 255 under each pairing mode, combined verify modes, cross-device same-second punches, overnight shifts, timezone/day/month boundary |
| Reliability | Device reconnect/backfill, lost ACK, gateway and connector restart, cloud outage, noisy device, backlog recovery and shared admission contention |
| Legacy | Existing eBio users/punches; older FP/FACE replies; F22 FINGERTMP; employee records using historical `template` rather than `tmp` |

For device tests, record serial/model/firmware, exact typed request, raw return code, receipt ID, application state and a redacted content hash/readback. A green queue job alone is insufficient.

## Practices from the demos that we should not copy

- Do not follow Note.txt as a production deployment recipe by copying all DLLs into a shared system directory. Package only the required vendor runtime and controlled registration for the supported connector environment.
- Do not use the K990 sample's fixed 20,480-byte image buffer as a universal size guarantee. Honor PhotoLength, validate content and apply an explicit safe cap.
- Do not assume the last filename always ends with a tab/newline. The sample loops append a filename only at delimiters and can miss a final undelimited item.
- Do not concatenate a device-provided filename into a local path. Validate the name and store through a controlled private asset path.
- Do not copy enable/disable sequences without finally-based recovery or run destructive clear/delete methods as normal reads.
- Do not conflate the SDK's false return, GetLastError result, no-data case and a cloud timeout. Preserve raw errors and normalize by operation/profile.
- Do not use desktop display strings such as “time zone” in the K990 date-range form as a timezone configuration contract; those fields are start/end timestamps.
- Do not read the handbook's wording mechanically where headings/signatures disagree. For example, the StartEnrollEx heading shows a StartEnroll signature, and parts of GetUserFace describe deletion under a read function. Confirm with the bundled interop definition and a device pilot before implementing a destructive or privilege-changing call.

## Evidence index

Paths below identify the exact inspected local sources. PDF page numbers above are physical PDF pages, not a promise that every printed page number matches.

**SDK files**

- SDK-TFT: `/home/aflah/Downloads/DeviceCommunication/TFT_SDK_Manual.pdf`
- SDK-IFACE: `/home/aflah/Downloads/DeviceCommunication/IFACE_SDK_Manual.pdf`
- SDK-BW: `/home/aflah/Downloads/DeviceCommunication/B&W_SDK_Manual.pdf`
- DEMO-USER: `/home/aflah/Downloads/DeviceCommunication/UserInfo/UserInfoMainTFT.cs`, `UserInfoMainIFACE.cs`, `UserInfoMainBW.cs`, and `UserInfo.csproj` in that directory.
- DEMO-K990: `/home/aflah/Downloads/k990-device-to-get-photos(sdk)/k990 device to get photos/DownLoadPictureAndAttlog/WindowsFormsApplication2/Photo.cs`
- DEMO-NEWLOG: `/home/aflah/Downloads/k990-device-to-get-photos(sdk)/k990 device to get photos/DownLoadNewattlog/TimeAttLog/Form1.cs`

**Go implementation** — root `/home/aflah/projects/bio-ebio/device-gateway`

- GO-WIRE / GO-USER / GO-TEMPLATE: `internal/gateway/types.go`, WireCommand and typed structures.
- GO-PROTOCOL / GO-INFO: `internal/gateway/protocol.go`, ParseAttendance, ParseUsers, ParseBiometrics, biometricReplyMatches and ParseDeviceInfo.
- GO-QUERY: `internal/gateway/server.go`, Protocol, particularly querydata validation at lines 129 onward.
- GO-ACCEPT / GO-SUBMIT: `internal/gateway/storage.go`, insertEvent, Accept, Submit, Result, Sweep and Resolve.
- GO-PROFILE: `internal/gateway/profile_policy.go`, `compatibility.go` and profile listing in `storage.go`. The built-in family policy is enforced; the editable profile registry is not a general firmware certification system.
- GO-SCHEMA: `internal/gateway/schema.sql`, especially command storage at lines 15–21.

**Application** — root `/home/aflah/projects/bio-ebio/bio-notifier`

- APP-BIO: `app/Services/Devices/EmployeeBiometrics.php`, formats, push, fetch and registration methods.
- APP-APPLY: `app/Services/Devices/Gateway/ReceiptApplier.php`, attendance, automatic discovery, biometric and command-result application.
- APP-EVENTS: `app/Http/Controllers/GatewayEventController.php`, receipt envelope allowlists.
- APP-REG: `app/Services/Devices/TenantDeviceRegistration.php`, binding capabilities and ownership.
- APP-COMMANDS: `app/Services/Devices/Gateway/GatewayCommands.php` and `app/Jobs/Gateway/SubmitGatewayCommand.php`.
- APP-EFFECT: `app/Jobs/Gateway/ApplyDomainEffect.php`, biometric discovery effect.
- APP-USER: `app/Models/User.php` and `app/Casts/ProtectedArray.php`, encrypted stored biometric fields.
- APP-LOG: `app/Models/AttendanceLog.php`, status and verify label accessors.
- APP-CALC: `app/Services/Attendance/AttendanceCalculationService.php`, pair at line 288.
- APP-TIME: `app/Services/Support/TenantTimezone.php` and `config/attendance.php`.
- APP-DIRECT: `app/Services/Attendance/DirectDeviceService.php`, fingerprint import schema.
- APP-RESOLVER: `app/Services/Devices/DeviceTransportResolver.php`.
- APP-PHOTO: `resources/views/filament/infolists/employee-face-photo.blade.php`.
- APP-DOCS: `docs/employee-biometrics.md`, `docs/magnum-face-fetch.md`, `docs/device-time-and-command-status.md`, and the Go README.

## Decision recommended

Deploy the locally verified P1 corrections with their migrations, then qualify the actual firmware families. Pilot one optional SDK connector through the existing durable operation and receipt boundaries. Prioritize health, backfill, enrollment inventory and offboarding before access-control expansion. This gives the software broader device coverage and business value without making normal tenant setup more complicated.
