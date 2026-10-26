# Device times and command status

Choose **Organisation Management → Organisation settings → Organisation timezone**. Master administrators can also select it on the organisation form. Existing organisations without a saved choice use the attendance timezone (Asia/Kolkata by default). New device registrations inherit this choice as their proposed device timezone.

The setting controls the organisation's attendance calendar, shift interpretation and displayed connection/command times. Audit and command instants remain UTC. New gateway punches retain their original offset, UTC instant and source calendar evidence while the existing attendance columns store tenant-local wall time. Keep each physical device's binding timezone correct for interpreting its own clock.

Choose the organisation timezone before recording attendance. Once attendance logs, calculated days or manual entries exist, changing it is blocked at the organisation model boundary. A historical timezone change needs a separately reviewed migration; existing attendance and processed payroll are not silently shifted. The legacy wall-time representation is not a complete solution for ambiguous DST hours.

A queued command expires after 15 minutes if it cannot be delivered. Data-reading commands (user queries, template/photo queries and device information) must receive matching data within 60 seconds of delivery. The recovery scan runs every 30 seconds. A timed-out read becomes Failed with a clear no-data message and releases the next queued command; it is not automatically resent.

Commands that change a device keep the existing ambiguous-outcome safeguard. Missing confirmation after five minutes becomes Needs review and does not automatically retry the mutation. A transport acknowledgement without matching requested data is not a successful fetch. Some firmware can accept uploaded face photos but reject photo-download commands; those rejections remain failures and never replace the saved photo with an empty value.


Device acknowledgement and employee storage are separate outcomes. Correlated data receipts must be applied before a read displays “Data saved; inventory completeness unconfirmed”. Incomplete packet sets cannot complete multipart queries; a last packet alone is insufficient. An empty legacy response is not proof that an employee has no enrollment.

A biometric push records its user-update prerequisite. The gateway sends dependent templates only after confirmed prerequisite success. Stop/cancel actions do not undo commands already executed on a terminal.


On Add device, choose **Device time zone** for the location of that terminal. **Sync time** sends the current time in that saved zone when the device receives the command. Check Device Commands for acknowledgement, then confirm the terminal clock. Historical attendance is preserved.

Resetting the transaction stamp asks the device to resend attendance. Existing raw punches are retained and duplicates are ignored. New punches can repair missing attendance, while manual entries and corrections keep their effective values and approval state. Processed salary amounts are not automatically recalculated.


**Reset Transaction Stamp** requests attendance history again. **Reset OP Stamp** requests operation/enrollment data again. Neither clears server logs. A device reboot is a separate action.

**Clear device attendance** deletes terminal attendance using the selected command dialect. Saved server raw logs, manual corrections and processed salary amounts remain intact. Confirm the upload is complete first: records that never reached the server cannot be recovered after device deletion. Compatibility mode needs verification on the actual firmware; the action never uses the clear-all-data command.
