# Device times and command status

Choose **Organisation Management → Organisation settings → Organisation timezone**. Master administrators can also select it on the organisation form. Existing organisations without a saved choice use the attendance timezone (Asia/Kolkata by default). New device registrations inherit this choice as their proposed device timezone.

The setting controls device connection/sync timestamps and command requested/sent/completed times. Those instants remain stored in UTC. Existing attendance punch times and payroll calendar dates are not shifted or rewritten. Keep each physical device's timezone correct for interpreting the local timestamps it sends.

A queued command expires after 15 minutes if it cannot be delivered. Data-reading commands (user queries, template/photo queries and device information) must receive matching data within 60 seconds of delivery. The recovery scan runs every 30 seconds. A timed-out read becomes Failed with a clear no-data message and releases the next queued command; it is not automatically resent.

Commands that change a device keep the existing ambiguous-outcome safeguard. Missing confirmation after five minutes becomes Needs review and does not automatically retry the mutation. A transport acknowledgement without matching requested data is not a successful fetch. Some firmware can accept uploaded face photos but reject photo-download commands; those rejections remain failures and never replace the saved photo with an empty value.
