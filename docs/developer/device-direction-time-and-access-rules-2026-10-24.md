# Device direction, automatic time and access rules

Source review and local implementation: 24 October 2026. Source changes have not been deployed. Subsequent Magnum protocol probes and operator observations are recorded in [Magnum validity investigation](magnum-validity-investigation-2026-10-24.md).

## Agreed ownership

The server should manage device time and access rules. Automatic clock synchronization must not rewrite employee administrator privileges. Explicit employee pushes currently resend the saved privilege; that existing behavior is separate from clock synchronization.

## Implemented locally

### Device direction

ADMS registration and editing offer terminal punch status, entry only, exit only and unspecified IN/OUT. The selected setting is captured with each new raw punch as classification metadata. Attendance pairing uses that captured direction; the original device status remains unchanged and visible. Changing the device setting does not reinterpret historical punches. Existing corrections and payroll safeguards remain in place.

Unspecified IN/OUT does not invent alternating entry/exit states. A shift using first/last pairing can handle such punches; device-status pairing requires actual or configured entry/exit direction.

This configures attendance classification in our software. A universal command that changes the terminal's own punch-state setting is not verified and has not been invented. The supplied eBio UpdateDeviceById implementation writes DeviceDirection to its database; its attendance parser applies that direction when interpreting punches. That path does not establish a firmware-setting command.

### Automatic clock negotiation

The gateway supplies an HTTP Date in UTC and the device's configured geographic TimeZone during PUSH options negotiation. Offsets are calculated for the current instant, including daylight-saving changes. Fractional offsets use the PUSH minute representation; ordinary whole-hour offsets use its hour representation. India is 330, Dubai 4 and Berlin winter time 1.

This supports firmware that synchronizes from PUSH negotiation. The manual time-sync command remains available. Hardware that does not honor these negotiation fields may require its supported explicit command; automatic correction on every firmware or after every terminal edit has not been demonstrated. Saved upload cursors are retained, and clock responses do not enqueue employee or privilege writes.

## Access enforcement is not release-ready

Ordinary employee push sends PIN, name, saved privilege and optionally card. It does not program validity dates, access groups or daily access slots. A separate local access-period action and typed gateway command are now drafted, but remain disabled by default because physical expiry/reset acceptance was not isolated from daily access schedules. Reset/unrestricted commands are explicitly rejected; acknowledgements must not be treated as enforcement proof. Employment dates and an application's enabled flag therefore do not prove that the terminal blocks an expired person.

The supplied eSSL/eTimeTrackLite code builds Expires, StartDatetime and EndDatetime fields. The inspected eTimeTrackLite implementation formats the latter two as dates. Daily access hours are separate access time-slot/group rules. SDK methods such as SetUserTZs refer to access schedules; they are not geographic timezone settings and cannot be treated as interchangeable PUSH commands.

A visitor valid from 10:00 to 14:00 needs supported date validity plus daily access hours, or a verified equivalent with that precision. Sending dates alone cannot guarantee that window. Payroll shifts must not silently become door-access rules.

## Required next implementation

- Define a small device-access policy for unrestricted, time-limited and disabled access, with explicit start/end boundaries and optional weekly hours.
- Map policies through verified firmware profiles. Reject unsupported precision or date ranges rather than silently widening access. Do not copy the vendor's fallback date clamping.
- Use the existing command queue with desired/observed policy versions, acknowledgements and readback where available. Coalesce changes and reconnect reconciliation; do not create a new command for every attendance punch.
- Keep access-only updates separate from privilege updates. Do not send a default Pri=0 while changing time slots or expiry.
- Deliver expiry rules to the terminal so they remain enforced offline. A scheduled server-side disable alone cannot protect an offline door.
- Define explicit removal of old validity restrictions when returning to unrestricted access, and verify rejection, timeout, retry and late acknowledgements.
- Reuse existing employee, branch and task-group assignment boundaries where appropriate; avoid another redundant eligibility-group setup.

Acceptance requires physical tests immediately before, at and after each boundary; daily overnight slots; reboot and offline operation; and checks that existing administrators retain their privilege. The present change does not claim that automatic access-rule reconciliation or visitor/gym expiry is implemented.

## Evidence and verification

Reviewed the supplied DeviceCommunication SDK manuals and demos, eBioServerLibrary device update/command code, eTimeTrackLite command construction and PUSH options code. The [eSSL manual](https://www.etimetracklite.com/downloads/eTimetracklite_Manual.pdf) provides the operator-facing device management context. Static inspection establishes command construction, not the exact timer that runs in a customer's eTimeTrackLite installation.

Local verification completed:

- Attendance and gateway workflows: 57 tests passed, 444 assertions.
- Follow-up registration, captured-direction and display tests: 9 passed, 130 assertions; these overlap the broader suite.
- Go gateway suite including isolated PostgreSQL checks: 56 tests passed.
- Go vet, Go build and whitespace checks passed.

No browser checks or production release deployment were performed. The earlier checks above preceded the subsequent physical validity investigation; see its separate evidence and limits.
