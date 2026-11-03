# Serial nightly attendance

The scheduler checks every minute. After `ATTENDANCE_NIGHTLY_TIME` (default `02:00`) in each organisation's timezone, it queues the preceding three work dates. The central unique organisation/work-date key prevents repeat nightly requests. A busy tenant is deferred until its current run finishes.

Manual recalculations use the same persistent FIFO queue. One tenant finishes before the next starts; submitting another run for the same tenant returns a validation message. Work is snapshotted by employee ID, and results remain in the tenant database.

The runner uses a central database lock, independent of tenant cache prefixes. Each job processes at most ten employee-days or twenty seconds, with a sixty-second worker timeout and a renewable 120-second lock. Contention releases the job without consuming an exception retry. Repeated calculation errors, or a worker timeout, mark the run failed so the following tenant can proceed. A failed run can be submitted again manually.

A minute recovery task restores lost queue signals. Tenant results commit before the central progress cursor, so a crash can replay a result without recalculating it. Existing calculation, manual-decision and approval protections remain in place.

Deploy the central migration before restarting workers. The existing default application queue and scheduler must be running. No new worker or queue connection is required. Legacy queued chunks share the execution lock and wait while serial requests are pending.

Commands:

- `php artisan attendance:recalculate-nightly --due`
- `php artisan attendance:recalculate-nightly --date=2026-11-01 --tenant=sec`
- `php artisan attendance:recover-runs`
