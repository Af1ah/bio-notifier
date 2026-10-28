# Magnum validity investigation

24 October 2026. Device TDBD262900666, model x 2008, platform ZMM510_TFT, firmware ZMM510-NF24VB-Ver2.2.1, PUSH Ver 3.0.6S-20240829. Registered timezone Asia/Kolkata.

## Current status

**Not release-ready.** No application/gateway runtime replacement, Git push, production migration or device-wide configuration command was performed. Tests used the existing encrypted gateway command queue through a maintenance probe restricted to this serial and temporary PIN 990024. The temporary user has been deleted from the terminal with Return=0, and a subsequent complete inventory contains only the two original PINs. Its auto-imported web employee and stale inventory row were removed after checking for pending imports and attendance evidence; raw receipts and command records were retained.

The operator reported that existing users could not punch after testing, while administrator settings remained accessible; user 1002 punching was subsequently confirmed restored. Causality is not established. The command audit contains no writes to those existing users and no global setting commands. Do not assume that proves the firmware had no wider effect.

The operator subsequently clarified that expiry and daily time periods are separate, and confirmed no other eBio/eTimeTrackLite controller is connected. A readback after manual changes showed user 1002 with expiry 20261023–20261025, Grp=1 and TZ=0001000000000000: personal-period mode with no personal time-slot references. After discussing the separate expiry and daily-period controls, the operator reported a successful attendance punch. The gateway received PIN 1002 at 17:36:06 IST with status 255 and verification 1 (fingerprint). A final USERINFO readback still shows TZ=0001000000000000 and expiry 20261023–20261025. It would therefore be incorrect to claim that a period-1 assignment caused recovery or that zero personal references are proven to deny access on this firmware. The exact cause of the earlier rejection remains unisolated. Do not treat prior rejections as isolated proof that an expiry-reset field failed either.

User 1002 punching has been reported restored. This does not establish that every original user has been restored, nor prove expiry-disable/count semantics. Further writes remain paused until both controls are tested independently.

## Protocol evidence

| Test on temporary PIN | Device acknowledgement | Readback / physical result |
| --- | --- | --- |
| Modern DATA UPDATE USERINFO create | Return=0 | Temporary user created |
| Expires=1 with 25–26 October dates | Return=0 | Returned 20261025 / 20261026; operator reported invalid-period rejection before the start date |
| Access-only date update, no Name/Pri/Card | Return=0 | Name and normal privilege retained in readback; dates retained |
| Legacy DATA USER with the same dates | Return=0 | Syntax accepted on Magnum; does not establish compatibility with a separate older device |
| Expires=0 only | Return=0 | Old dates remained; operator still reported invalid-period rejection |
| Expires=0 plus both date fields set to 0 | Return=0 | Readback returned zero dates; operator still reported invalid-period rejection |
| eSSL broad-default range, 2000-01-01 to 2037-12-31 | Return=0 | Operator then reported rejection for both original users; not a verified reset |
| Delete temporary PIN | Return=0 | Subsequent full inventory contained only original PINs 1 and 1002 |

All dates sent were calendar dates, not time-of-day expiry. No credentials or administrator privileges of original users were overwritten by the probe.

## Existing-user observations

Before test writes, original user 1 returned start/end 20261024; original user 1002 returned 0/0. After temporary-user deletion, both original users returned 20261024/20261024. That difference needs explanation through operator edits, other controllers or device behavior. Do not silently overwrite the originals from an assumed default.

The USERINFO responses contain PIN, Name, Pri, Passwd, Card, Grp, TZ, Verify, ViceCard, StartDatetime and EndDatetime. They do not contain Expires or a remaining-entry counter. Sensitive card/password values were excluded from diagnostics.

Recent attempts for user 1002 were received with raw status 254 during the reported rejections. Raw evidence was preserved. Its attendance/payroll interpretation requires a separate confirmed event mapping; a received record is not proof of an accepted punch.

## Why the implementation remains disabled

The standalone SDK describes four expiry modes: no limit, dates, usage count, and dates plus usage count. Its SetUserValidDate interface is a native SDK API, not proof of equivalent ADMS wire fields. The supplied eBio command code sends Expires and date fields, but that alone did not produce a physically verified reset on this firmware. Do not add guessed count fields or assume date-only updates clear all native restrictions.

Local draft source adds a separate access-only operation and employee action, strict date/parameter validation, encrypted queued delivery, firmware gating and an optional legacy user-write dialect. It does not add per-punch synchronization. Config device_gateway.user_validity_enabled is false. Unrestricted/reset modes are rejected in PHP and Go after the failed hardware tests. Automatic access-rule reassertion, daily access slots and entry-count control are not implemented.

## Acceptance still required

Restore the original users' punching first. Capture the device's native no-limit/date/count/both setting and available readback without changing original credentials. Then test a new isolated user across before-start, within-range and after-end conditions, removal of each restriction, offline operation and restart. Confirm that ordinary user pushes and other controllers do not rewrite validity. Only then enable the feature for the tested firmware profile.

## Separate controls required

Expiry dates define the overall period in which an employee may authenticate. Daily access schedules define permitted times by weekday and are referenced through user/group access configuration. Usage limits are a further independent control where supported. Neither payroll shifts nor geographic device timezone is an access schedule.

The [manufacturer PUSH manual](https://studylib.net/doc/27718586/ilide.info-attendance-push-communication-protocol-2020032...) describes the 16-character user TZ as a group/personal selector followed by three period references, and AccTimeZone as weekday start/end definitions. Its examples distinguish group inheritance from personal time references. However, Magnum accepted a punch while reporting personal references of zero, so the generic encoding must not be promoted into an unverified deny/unrestricted rule for this firmware.

Future UI must offer overall expiry separately from a daily access schedule, preserving both when only one is changed. The firmware-specific meaning of an empty personal schedule must be verified before defining it as no access, unrestricted access or inheritance. Unrestricted hours must use a physically verified representation; do not guess a zero-reference policy. Native expiry mode/count controls need their own supported-command evidence.

## Verification and remaining boundary question

Local checks after disabling the draft and rejecting reset commands: 41 Laravel gateway workflow tests passed (406 assertions); 58 Go tests passed including isolated PostgreSQL tests; Go vet/build and whitespace checks passed. These prove source behavior, not completed hardware acceptance.

The operator described an expiry ending on 26 October, while the final raw response reports EndDatetime=20261025. Confirm the native displayed value and whether the endpoint is exclusive, date-only, or affected by firmware conversion before promising inclusive end-date semantics. The UI draft now uses End date rather than Valid through. No automatic correction to the operator's live dates was made.

## Daily access-hours test, later on 24 October

The operator explicitly authorized using period 50. A generic DATA QUERY AccTimeZone command returned Return=0 but no usable inventory and timed out; do not claim that this verifies all device schedules or proves the slot was previously unused.

The maintenance probe defined AccTimeZone UID=50 with weekday start 0000 and end 1700 for all seven days. Definition command ddd6d6c6-ecbc-4978-851d-228876a57532 (wire 112) and dependent user-assignment command 1d418b22-646c-45a7-81f4-b3b4caf5bad9 (wire 113) were acknowledged with Return=0. Assignment sent only DATA UPDATE USERINFO PIN=1002 and TZ=0001003200000000; hex 0032 identifies period 50.

Readback event 824bd18e-392f-4b70-8a4e-d6dd1c2a5f0e confirmed this reference. Pri=14, Grp=1, StartDatetime=20261023 and EndDatetime=20261025 were retained. No card, biometric, privilege or expiry fields were included in the schedule assignment. After 17:00 IST, the operator physically tried the user and confirmed invalid-time-period rejection. This is positive enforcement evidence for an explicitly assigned, defined daily period. It does not establish expiry reset/count semantics.

The operator then requested moving the cutoff to 18:00. Command 9211501d-80a7-40ef-9fe4-3ed946922c3a (wire 115) was queued to change only period 50's seven daily end times to 1800. Magnum acknowledged the 18:00 definition with Return=0. A physical punch after this change is still required to confirm access is allowed before 18:00.

Future integration must distinguish overall expiry from weekday access hours, preserve administrator privilege, and manage schedule-slot ownership. Do not overwrite shared/native slots by assuming an arbitrary number is free. No runtime source release or database migration was deployed for this maintenance test.

## Explicit past-expiry test

The operator next requested expiring their existing PIN 1002 yesterday to block authentication. On 24 October 2026 IST, a fresh readback first confirmed StartDatetime=20261023, EndDatetime=20261025, Pri=14 and TZ=0001003200000000 (period 50, previously extended to 18:00).

Scoped command 06a68c35-2de5-461b-8fef-ff78c21d0a23 (wire 117) was queued with exactly DATA UPDATE USERINFO PIN=1002, Expires=1 and EndDatetime=2026-10-23. No start date, daily-period reference, group, privilege, card or biometric field was sent. The device acknowledged wire 117 with Return=0. Readback event 50dd57ab-a09c-45c8-aed7-b9a950595912 returned EndDatetime=20261023, StartDatetime=20261023, Pri=14, Grp=1 and TZ=0001003200000000. The operator confirmed successful rejection of the punch while still before the 18:00 daily cutoff, establishing separate past-expiry enforcement for this tested combination. This test is intended to distinguish past expiry from the daily 18:00 cutoff while current device-local time is still before 18:00.

## Tomorrow expiry with a three-use quota probe

After confirming the expired user was blocked, the operator requested expiry on 25 October and three permitted uses. Command 56c04e7b-5924-45e3-8d08-d55c253daf16 (wire 119) was queued for PIN 1002 with Expires=3, EndDatetime=2026-10-25 and VaildCount=3, preserving start date, daily schedule, group, privilege and credentials.

This is a controlled compatibility probe, not a verified ADMS quota implementation. The manufacturer's standalone SDK documents combined mode 3 and a ValidCount argument; its native PULL data dictionary spells the user-table field VaildCount. Neither document alone proves the same field is honored through this firmware's PUSH USERINFO update. Device acknowledgement, native displayed remaining count, three accepted uses and fourth-use rejection must be recorded independently. The operator was told to wait before punching to avoid consuming an unverified quota.

## Quota observation: more than three punches

Wire 119 was delivered at 12:24:00.924178 UTC and acknowledged at 12:24:01.202910 UTC (17:54:01 IST). No subsequent quota/user-write command appears in the scoped command ledger; wire 120 was a read-only USERINFO query. Its response preserves the requested end date and daily period but omits the native validity mode and remaining-count fields.

Immutable attendance receipts for PIN 1002 contain normal INOUT status 255 at 17:54:43, 17:54:51, 17:54:54, 17:54:59, 17:55:04 (fingerprint) and 17:55:12 (face). Status 254 follows at 17:55:13 (face), 17:56:57 and 17:57:02 (fingerprint). The operator reports more than three accepted attempts, a displayed count of zero afterward, and delayed invalid-user rejection. These events precede the configured 18:00 cutoff. Status 255 describes an unspecified punch direction, not an independent door-open receipt; the physical acceptance report comes from the operator.

The command was acknowledged about 42 seconds before the first recorded attempt. Therefore server delivery latency does not explain the extra acceptances. Asynchronous device-side quota enforcement, repeat-verification accounting or partially supported PUSH parameters remain hypotheses. The native count changing to zero and later rejection do not establish an exact three-use guarantee. Do not add arbitrary sleeps or per-punch quota writes as a workaround. Keep entry-count controls unavailable until a controlled test records the initial native mode/count, spaced same-method attempts and remaining count after each attempt. Run that test within a known permitted daily window, independently from expiry, before testing combined date/count mode. Do not silently replenish the current quota or alter the operator's 18:00 schedule.
