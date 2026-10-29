# Independent device-user expiration

28 October 2026. The operator authorized a controlled physical pilot on one named enrolled user. Its identifiers, baseline and command IDs remain in private operational records. This document describes source behavior and software checks; physical mode/count qualification is ongoing.

## Separate controls

Device users has two independent row actions: Daily time periods and Expiration rule. The daily-period action manages group/personal schedule references only. Expiration has four explicit modes: No expiration, Dates only, Entry limit only, and Dates and entry limit. Employee joining/end dates and payroll shifts remain separate application fields and are never written by these actions.

Dates require a start and end calendar date. Entry limits require a fresh explicit allowance of 1–65535; the form does not prefill an observed remaining count as a new allowance. No expiration requires neither. Non-date commands explicitly clear inactive expiration fields. The qualified date-only command omits the count field; clearing a pre-existing count restriction with that command is not yet qualified. No command sends group, period, identity, privilege, card or biometric fields. Limits are written once through the encrypted command outbox, never replenished per punch. Repeated submission with the same UUID remains idempotent.

## Protocol candidate and evidence boundaries

The manufacturer-authored standalone SDK manual documents Expires modes 0/1/2/3 for none/dates/count/both. This is a native SDK contract, not independent qualification of PUSH behavior. Dates only uses the vendor DATA USER form with Expires=1, StartDatetime and EndDatetime and no count field, after a physically confirmed before-start rejection. The other modes currently send Expires, StartDatetime, EndDatetime and the observed PULL spelling VaildCount in an access-only USERINFO update; their count/reset semantics still require physical qualification. Old queued mode=period requests remain accepted as date-only requests.

A known native No expiration setting can coexist with invalid-time-period rejection. Establish a permitted daily-period window and correct terminal clock before attributing a rejection to expiration. Do not change a shared group as a side effect of an individual expiration test. In particular, an empty group/period inventory response is not proof that a slot is unused.

A dependent PIN-specific read follows acknowledgement. Synced requires the returned mode and all active date/count fields to match; missing mode/count yields Verify on terminal, rather than treating stored dates or command acknowledgement as proof. The native device may omit mode/count in USERINFO. Requested allowances must not be displayed as reported remaining entries.

## Physical pilot sequence

Record the native mode, remaining count, dates, group/personal selection, daily hours and device clock. Obtain an accepted punch in a known permitted daily window first. Then isolate No expiration, date-only in-range and before-start behavior, switching back to None, count-only with one spaced same-method attempt and native remaining count after each attempt, and combined mode. Wait for operator evidence between scenarios; do not queue all scenarios at once.

Before exhausting an allowance or using a past end date, check the terminal’s expired-user handling policy. Some firmware offers retention, attendance clearing or user deletion. Keep original credentials and raw server punches intact; use an explicit test plan before a potentially destructive expiry policy is triggered. No arbitrary delay or automatic quota refill is an enforcement fix.

Source references: manufacturer-authored Standalone SDK manual, SetUserValidDate/GetUserValidDate; [SDK manual mirror](https://usermanual.wiki/Document/ManualSDKVersaoZkTeco.61245417.pdf). See the October 24 Magnum investigation for earlier date enforcement, separate daily-period evidence and the unqualified count probe. This source revision expands the earlier disabled draft for the explicitly authorized physical pilot; it does not turn those earlier observations into completed acceptance evidence.

## Rollout

Keep Laravel on work and gateway on main. Back up and coordinate both runtimes because the old gateway rejects the new modes. Expiration uses its own optional DEVICE_USER_EXPIRATION_ENABLED emergency switch and existing exact firmware/binding checks, independent from DEVICE_ACCESS_GROUPS_ENABLED. No schema migration is needed. Rebuild the gateway, update Laravel source/caches and restart affected workers before issuing a pilot command. Do not change production environment files or encryption keys as part of this update.

## eSSL compatibility investigation, 29 October

Physical testing of the modern update stored the requested future dates but left the terminal's native rule at None and allowed verification. A subsequent operator-selected date rule allowed verification, but fresh readback showed both dates were today; that attempt did not test rejection outside the date range. These results do not establish a firmware defect; terminal-wide expiration enforcement and expired-user handling must be inspected separately.

Static IL inspection of the supplied eBioServerLibrary.dll DeviceManagement.GetDeviceCommands and eTimeTrackLite App_global.asax.dll ExecuteDeviceCommands_AddUser confirms the vendor date-only command uses DATA USER, Expires=1, StartDatetime and EndDatetime, with no count field. The internal dialect=essl_dates comparison reproduced that access-only command. The terminal acknowledged it, readback confirmed the future dates and unchanged Grp/TZ, and the operator reported Invalid user before the start date. Dates-only now uses that vendor form by default; the explicit compatibility parameter remains accepted for durable command history. This qualifies before-start enforcement on the exact tested profile, but not inclusive end-date behavior or removal of a pre-existing count restriction. No identity, privilege, group, schedule or credential fields are included.

The operator reported the device-wide expired-user action as the first option, retaining the user and recording punches. It was not changed during the comparison. No delete-user policy was enabled. Identifiers and command evidence remain in a private operational record.
