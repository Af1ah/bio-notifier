# Device-user access readback

Local implementation, 28 October 2026. Not deployed. The device-specific access screen belongs under Devices → Device users. The employee-page access-period action has been removed.

## Reported settings

The Go USERINFO/OPERLOG parser forwards only the access fields Expires, StartDatetime, EndDatetime, ValidCount, VaildCount, Grp and TZ when actually reported. It never forwards arbitrary fields or passwords. Laravel validates this allowlist and saves an encrypted snapshot on the device/PIN roster entry with its source receipt time. Replaying an older receipt cannot replace a newer access snapshot. Newer snapshots clear fields that are no longer reported rather than carrying stale values forward as current.

The table and Access settings dialog show expiration mode, dates, remaining entries and daily-period references. Missing fields say Not reported. Zero dates say Unset on device; neither establishes unrestricted access. Mode/count values remain explicitly unverified because the native SDK numbering and counter behavior have not been qualified for PUSH readback. Conflicting counter spellings do not become an asserted remaining balance. No local attendance-based quota estimate is made.

TZ references are decoded separately from expiration. Group inheritance, personal slot references and unknown encoding remain distinct. Referenced period numbers are not weekday start/end times. The application does not have a verified schedule-definition readback or slot-ownership scheme, so it does not invent hours or modify shared periods.

The existing expiration-date write remains behind its configuration flag, exact firmware policy and synchronized binding capability. Its action is now scoped directly to a device roster entry and does not require employee-page settings. It uses the existing command service and encrypted outbox. Unrestricted/reset, quota writes and schedule writes remain unavailable; the October 24 physical acceptance gaps still apply.

## Production diagnosis

A scoped read-only inspection found no saved device-user snapshot for the affected account. Existing acknowledged user updates contained no expiration, quota, group or daily-period fields, and no matching access readback was found in the bounded recent evidence. These facts do not identify the active terminal rejection cause. A fresh targeted user read is required; do not clear access rules or replenish quota based on an assumption. Account identifiers and command audit details belong in private operational records.

## Coordinated rollout

Back up and follow Deploy.md. Apply Laravel code and the tenant migration 2026_10_28_130000_add_access_settings_to_device_users first, refresh/restart its affected workers, then replace the Go runtime. An old Laravel API rejects the new access object, so do not deploy the new sender first. Preserve application/gateway keys. Refresh device users after both versions are active; old normalized receipts did not retain access fields and cannot populate this snapshot without original-source evidence.

Software checks cover parser allowlisting, absent and zero fields, encrypted persistence, stale replay protection, device scoping and moving the action off the employee page. They do not establish hardware enforcement or resolve Ashkar’s rejection. No production source update or access-setting write is part of this implementation.

The subsequent [access-group implementation](device-access-groups-2026-10-28.md) adds separate per-device group/period tabs and per-user Save and sync. It supersedes the earlier statement that no schedule-write implementation exists; hardware qualification and the default-off write gate remain.
