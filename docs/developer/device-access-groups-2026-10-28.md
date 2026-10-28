# Per-device access groups and user sync

Implemented and deployed on 28 October 2026 from Laravel work commit 88eff52 and gateway main commit 0480004. Physical device qualification remains incomplete. This supplements the device-user readback change; employee joining/end dates remain employment/payroll fields.

## Operator flow

Each compatible device has separate Access groups and Daily periods tabs. Read access settings requests both native inventories. Only complete supported records are displayed; acknowledgement without data is not inventory. Each tab supports adding a confirmed unused slot, editing its draft and explicitly applying it to that device. Local names identify records in the app; they are not native group names.

Each Device users row has Edit access with Save and sync. It selects the device-local group and either group periods or one to three personal period references. Sync queues the access-only user update and a dependent PIN-specific user read. The read is dispatched only after acknowledgement. Displayed device-user settings remain the last actual readback, rather than being optimistically replaced by requested values. Expiration rule/dates and remaining entries are shown separately; this editor does not write them, identity, privileges, credentials or employee payroll fields.

Save and sync uses one stable request UUID and a derived read UUID; duplicate submission returns the same commands. Changing parameters under the same UUID is rejected. Failed/expired prerequisites cancel dependent work through the existing gateway dependency mechanism.

## Native settings and editing boundaries

The candidate PUSH forms are DATA QUERY/UPDATE AccGroup, DATA QUERY/UPDATE AccTimeZone, and an access-only DATA UPDATE USERINFO containing PIN, Grp and TZ. Native group readback must contain number, Verify, ValidHoliday and all three TZ references. A period must contain its number and all seven weekday start/end pairs. Missing/unknown fields, duplicate keys and unrecognized formats are quarantined; they are never filled with defaults. Only the documented group-period mode with one to three nonzero period references is currently accepted. Empty-reference groups and other native formats are not editable through this implementation. No absence of a returned record is treated as proof that a slot is free.

Existing groups preserve their verification and holiday settings while period references are edited. Fresh device-origin readback is required within ten minutes for editing/applying. Revisions reject stale form submissions. Binding ID/version and device ownership are checked for all edits, applications, period references and user assignments. Pending/ambiguous applications block edits. Applying a group requires every referenced period to be known and already read or acknowledged on that device. Editing a period or group is a shared access change; confirmation is required and other terminal users may also be affected.

The native schedule format is minute precision with a start/end pair for each weekday. Times are sent as entered in device-local wall time. Cross-midnight, closed-day and endpoint behavior must be qualified on the chosen firmware; no general overnight interpretation is provided. The user detail dialog resolves last reported hours through reported group/personal references; saved drafts are never presented as effective terminal hours.

## Persistence and compatibility

Tenant device_access_settings stores encrypted desired/reported configuration, source time, device/kind/number uniqueness, binding generation, revision and stable command identity. Readback cannot replace a newer source snapshot; it does not overwrite an operator draft. Rebinding invalidates old command identities and resets records to device-origin evidence. No full-inventory completeness or automatic deletion reconciliation is claimed.

The gateway persists raw/normalized access records through the existing encrypted event/outbox boundary. New read operations wait for usable data and use the existing 60-second read timeout rather than mutation outcome-unknown blocking. Firmware/profile authorization is rechecked through normal dispatch policy. No raw-command endpoint or maintenance bypass is added.

## Qualification and rollout

Writes default off with DEVICE_ACCESS_GROUPS_ENABLED=false. The only source profile currently admitted is the investigated Magnum x 2008 / ZMM510_TFT / ZMM510-NF24VB-Ver2.2.1 combination. This limits dialect exposure; it does not certify group support. The earlier Magnum AccTimeZone query returned no usable inventory, so actual existing-group editing still depends on a successful supported readback. Unsupported devices must not be silently enabled.

Before enabling writes, verify complete native group and period readback, preserve a known configuration, use approved unused test slots and a designated test user, then independently verify group inheritance, personal override, weekday boundary behavior, expiry/quota preservation, rejection, timeout and reread. The user subsequently authorized the coordinated source deployment on the named branches. Deployment did not enable the access-write gate or send terminal access-setting writes.

Follow Deploy.md with backups. Deploy Laravel central capability migration, both tenant migrations, code and affected workers before replacing the Go sender; the old Laravel API rejects new event kinds. Coordinate runtime versions before exposing new reads. Refresh current device settings after deployment. Preserve all encryption keys. Enable the write flag only for the qualified installation and refresh configuration.

Source: ZKTeco Attendance PUSH Communication Protocol, sections 12.1.1.1, 12.1.1.14 and 12.1.1.15, manufacturer-authored manual mirrored at https://studylib.net/doc/27718586/ilide.info-attendance-push-communication-protocol-2020032.... The document describes command forms; it is not Magnum firmware acceptance evidence.


## Deployment verification

The coordinated rollout completed with the central capability migration and both tenant migrations applied to both installed organisations. The 35 runtime source files matched the reviewed release hashes. Both private environment files were preserved unchanged. PHP-FPM, the Bio-Notifier workers/scheduler and the rebuilt gateway container were running; the authenticated private health endpoint reported two delivery workers. Database backups were verified by restoration into isolated temporary databases before rollout. Existing parked receipts were not replayed.

Access writes remain disabled pending actual firmware readback and hardware qualification. Service health is not physical-device acceptance. No browser or interactive UI checks were performed.


## Supported-device enablement and command status update

The earlier global default-off decision is superseded at the user’s direction. Access controls now default on only through the existing exact model/platform/firmware profile, enabled synchronized company/device binding and operation capabilities, enforced in Laravel and Go. Unsupported or unsynchronized devices do not display the access tabs/user action; backend requests remain rejected. DEVICE_ACCESS_GROUPS_ENABLED=false remains an emergency override. This does not enable expiry-reset or quota writes.

Device Commands and each device’s action list include Sync status. A user access write shows Synced only after its dependent targeted readback is saved and its reported group and group/personal-period encoding match the requested values. Acknowledgement alone is labeled Acknowledged or Waiting for readback. Failed/expired reads and mismatching or incomplete access fields remain visible as failure/review states. The parent write retains the confirming receipt identifiers; list relationships are eager-loaded to avoid per-row status queries. Group/period writes retain Acknowledged until separate readback; no physical enforcement claim is inferred.
