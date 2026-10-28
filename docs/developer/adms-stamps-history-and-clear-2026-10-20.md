# ADMS stamps, historical uploads and clearing terminal logs

Implementation and protocol review, 20 October 2026. Deployed with the paired Laravel/gateway release bio-stamps-20261020-171423. No command was sent to a physical terminal during deployment. See [rollout evidence](production-stamps-rollout-2026-10-20.md).

## What the commands mean

| Action | Meaning | Data replay/deletion |
| --- | --- | --- |
| Attendance/transaction stamp (`ATTLOGStamp`, legacy `Stamp`) reset | Ask the terminal to upload its stored attendance again | Replays records still on the terminal; does not change punch timestamps or delete server records |
| Operation stamp (`OPERLOGStamp`, legacy `OpStamp`) reset | Ask for operation/enrollment information again | Can resend users/templates and operation records; it is not the attendance history cursor |
| Device reboot | Restart the terminal and its connection | Normally reconnects using upload state; it is not a clear-attendance command |
| `CLEAR LOG` | Manufacturer-documented attendance deletion | Deletes attendance from the terminal only; cannot recover records already removed there |
| `DATA DELETE ATTLOG` | Explicit compatibility dialect found in another ADMS implementation | Requires qualification on the actual model/firmware; do not infer support from device age |
| `CLEAR DATA` | Manufacturer-documented deletion of all client data | May remove enrolled users and biometrics as well as logs; intentionally not exposed by the attendance-clear action |

The checked s0x90 example labels CLEAR DATA as attendance deletion and CLEAR LOG as operation deletion. Those comments conflict with the manufacturer's protocol manual. They are not a safe command contract. The manufacturer manual takes priority; CLEAR DATA is never an automatic fallback.

The manual explains that stamp zero requests reupload, and that newer firmware can send stamps for compatibility without using them as an incremental cutoff. Therefore, no application filter trusts a device cursor or a maximum punch date to decide whether an individual raw record should be stored.

## Issues found and changes

Previously, a single ATTLOG body above 1,000 records was rejected and retained as an invalid batch. A terminal repeatedly uploading one 8,000-record frame could stall. The handshake also always sent stamp zero, requesting unnecessary historical reuploads. New punches generated a separate calculation effect per punch.

Now an ATTLOG upload can contain up to 10,000 records within the existing 1 MiB body limit. The whole body is validated before application data is accepted. Larger valid uploads are split into at most 500 punches per durable gateway event; one 8,000-record frame becomes 16 events. Exact original raw bytes are retained in the encrypted chunk receipts, including line endings and extension columns.

All chunks, the last-seen update and the uploaded stamp commit in one transaction before `OK: 8000` is returned. Invalid input is quarantined without partial attendance acceptance. Admission-capacity failure rolls back all new chunks and the cursor, returns HTTP 503 and leaves the terminal to retry. No acknowledgement is issued merely because parsing or queue insertion started.

Production defaults admit eight simultaneous POST uploads, with configurable bounds of one to 64. Full admission returns 503 and Retry-After: 60 before consuming the body. GET polling remains available. Existing safeguards remain: ten PostgreSQL connections, five-second protocol contexts, two delivery workers, durable queue signals, fenced leases, 10,000 pending gateway events globally and 3,000 per company. These are bounded resource settings, not a production throughput guarantee.

The gateway saves the opaque ATTLOG/OPERLOG cursor only with accepted evidence and returns it on subsequent handshakes using both modern and legacy option names. Explicit stamp-reset dispatch resets the matching saved cursor to zero. Invalid/quarantined bodies do not advance the cursor. This uses the terminal's supplied mark; it does not calculate a new mark from the newest punch time. Firmware that omits the mark or always sends zero can still replay history; record-level deduplication remains necessary.

Laravel receives bounded events through its existing authenticated receipt API. Evidence rows are inserted in batches. A receipt produces one calculation effect per employee/local date, instead of one per new punch, while preserving per-punch raw evidence. Repeated invalidation of the same calculated day within a receipt is avoided. Old punch notifications are suppressed when the source punch is more than 15 minutes before receipt time or over five minutes in the future; historical logs are still stored. Ordinary recent-punch notifications remain enabled.

## First connection with 8,000 existing records

1. Serial ownership and tenant binding are checked. The gateway requests history on an initial cursor of zero.
2. The terminal may send one body or several smaller bodies. Each accepted upload follows the same byte, row, concurrency and backlog limits.
3. The gateway durably accepts the evidence and then acknowledges it. Laravel applies the resulting events asynchronously; device ACK does not mean payroll calculation is finished.
4. Known employee PINs receive grouped attendance calculations. Unknown PINs still retain raw punches; creating or importing the employee is a separate workflow.
5. A duplicate body adds no gateway event. A different packet layout can add another evidence receipt, but existing raw rows are not overwritten.
6. Older missing punches are accepted even when newer records already exist. There is no last-time-only comparison.

The existing tenant raw-row identity is employee PIN plus tenant-local timestamp. This preserves compatibility with eBio and current payroll. If two devices report the same employee at exactly the same stored second, they share one legacy raw row; their separate ADMS receipt/provenance records retain the source differences. This change does not silently migrate that historical identity. Original offset, UTC time and encrypted source evidence remain available.

## Raw records, manual decisions and payroll

Clearing or resetting a terminal never runs a delete/update against server attendance_logs. Raw-model and database guards from the preceding attendance change remain active. Manual intervals and corrections remain in their own tables. A raw resend does not replace a manual day, reset an approval or silently retotal a processed payslip. Newly repaired unprotected attendance can require payroll review through the existing freshness workflow.

Only records already received by the server can be preserved there. Attendance deleted from the device before a successful upload cannot be recovered by resetting stamps.

## Clear action and qualification

Devices offers **Clear device attendance**, with Standard (`CLEAR LOG`) and Compatibility (`DATA DELETE ATTLOG`) choices and explicit confirmation that uploads are saved. The dialect is stored with the queued command. There is no automatic dialect retry or fallback, including after timeout or negative acknowledgement. Existing unknown-outcome protection remains in effect.

The PHP boundary blocks a new clear request when that device has known unapplied attendance receipts. The Go dispatch boundary waits while that serial has undelivered or quarantined gateway evidence. These checks protect known backlogs; they do not prove that all records still on the terminal have been uploaded. Check the saved raw history and device count before approving a clear.

When qualifying an actual device, first import and verify the complete attendance history. Record serial, model, firmware, dialect and pre-clear device count. On a controlled unit, issue only the selected attendance-clear command, retain its correlated return code, then refresh device counts and confirm users/templates remain present. Verify server raw count and manual/payroll state remain unchanged. A return code alone is not physical verification. Do not switch to CLEAR DATA when an attendance dialect is rejected.

## Verification

The PostgreSQL race tests cover an actual 8,000-record HTTP request becoming 16 encrypted receipts, identical replay, capacity rollback without cursor advancement, saved-cursor handshake, invalid final rows, exact raw-byte reconstruction and clear-command dispatch/acknowledgement. Laravel integration tests import all 8,000 records through sixteen real receipt/API jobs, re-packet duplicates with an older missing punch, verify reduced calculation effects, suppress historical notifications, retain recent notifications and test clear confirmation/backlog checks and raw preservation after acknowledgement.

Verification: 54 Go tests passed with the race detector and isolated PostgreSQL schemas; Go vet/build passed. The full Laravel run passed 277 tests and found one new test assertion incorrectly expecting the display label completed instead of the stored acknowledged state. That assertion was corrected; its complete clear-action/backlog/raw-retention test then passed with 24 assertions. The 8,000-record history test, including an older missing punch and recent-notification checks, passed in the full run. Physical device dialect compatibility and production peak-load capacity remain unverified. Previously quarantined oversized ATTLOG frames can also be recovered through the same bounded acceptance path without another device upload; the original encrypted receipt is retained.

## Release

A coordinated Go gateway update adds its accepted_stamps column at startup. Central Laravel migration `2026_10_20_180000_enable_gateway_attendance_clear` extends existing management-capable bindings without changing ownership/version. Retain the preceding tenant raw-immutability migration. The user authorized deployment of both services. Central and company migrations completed and the gateway and supervised workers restarted successfully. No physical-device command or recognition test was performed.

## References

- [ZKTeco Attendance PUSH protocol, mirrored manufacturer manual](https://studylib.net/doc/27718586/ilide.info-attendance-push-communication-protocol-2020032...): initialization stamps and section 12.2 CLEAR commands.
- [s0x90 upstream command examples](https://github.com/s0x90/zkteco-adms/blob/master/examples/commands/main.go): inspected for comparison; clear-command comments conflict with the manufacturer manual.
- [Alternative ADMS implementation wire-format notes](https://github.com/Vibhav-Aggarwal/zkteco-adms-server/blob/main/docs/wire-format.md): DATA DELETE ATTLOG compatibility example; not a manufacturer guarantee.
