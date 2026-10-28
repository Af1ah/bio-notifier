# PostgreSQL admission, tenant connections and Redis evaluation

28 October 2026. Evaluation only; production configuration and source were not changed by this evaluation.

## Recommendation

First test and add the admission indexes, then scope Redis locks by tenant before piloting Redis cache. Keep gateway receipts, command state and payroll durable in PostgreSQL. Do not put all traffic behind PgBouncer yet. Its value for this application is controlling connection churn and concurrency; this experiment did not demonstrate faster database work.

## Test environment and boundaries

Tests ran locally, using disposable PostgreSQL 17, Redis 7.4.11 and PgBouncer 1.25.2 containers. PgBouncer was installed inside a temporary container; it was not installed on the production host. Services listened only on local test ports. Fifty synthetic tenant databases each contained an identifying marker. Forty concurrent PHP processes performed 25 new PDO connections/transactions each while rotating across those databases. Each transaction checked the correct database marker with native prepared queries and included a deliberate 5 ms database sleep. This models connection switching, not the complete application.

Cache tests used the installed Laravel cache drivers with a temporary Predis client, 40 concurrent PHP processes and 8,000 total put/get/forget cycles, each with a 1 KiB value. Production has PhpRedis; its performance and failure behavior were not benchmarked here. File-cache data was on /tmp, which is memory-backed on this laptop. That gives file cache an unusually favorable comparison and says nothing about production disk latency.

These tests did not simulate 40 authenticated browser sessions, full Livewire requests, 20 concurrent manual punches or end-to-end payroll processing. They did not benchmark the production machine, full resource consumption, encrypted/network PostgreSQL connections, or a long-running mixed peak. The earlier gateway burst test likewise covered durable gateway intake, not complete tenant application.

## Admission queries

The current gateway takes a global transaction-level advisory lock, checks duplicate fingerprints, counts undelivered work globally, and counts it again for the organisation via a binding join. The lock protects exact backlog limits across concurrent processes. Separate Laravel tenant databases do not remove this contention: all terminals first enter the shared gateway database.

A disposable table reproduced the relevant gateway columns, current pending index, 200 bindings across 50 organisation identifiers, 200,000 delivered events and 3,000 pending events. Ten EXPLAIN ANALYZE executions were measured for each query before and after candidate indexes, with ANALYZE run. Values below are warm local median execution times, rounded.

| Admission query | Current index | With candidate indexes |
| --- | ---: | ---: |
| Global outstanding count | 9.832 ms | 0.300 ms |
| One organisation's outstanding count | 9.613 ms | 0.093 ms |

Candidate indexes, not applied to production:

```sql
CREATE INDEX gateway_events_outstanding_serial
ON gateway_events (serial) WHERE state <> 'delivered';
CREATE INDEX gateway_bindings_organisation_serial
ON gateway_bindings (organisation_id, serial);
```

The partial event index excludes delivered history, reducing the data scanned as history grows. PostgreSQL planner choices and index-only visibility depend on real data and maintenance. This test used simplified synthetic rows rather than encrypted production payloads. Index storage and write overhead were not quantified. These results justify a staging test, not an unconditional production speed claim.

Indexes shorten the admission critical section but retain its global serialization. A follow-up counter design could avoid repeated counting, but must update counters atomically on insertion, delivery and state transitions, preserve duplicate handling and whole-upload rollback, and reconcile after interruption. Moving that limit into Redis alone would split its correctness boundary from the committed PostgreSQL receipts. Do not simply remove the global lock.

## Cache results

| Driver | 8,000 cycles: wall time including PHP startup | Median cycle | 95th percentile cycle | Read/write errors |
| --- | ---: | ---: | ---: | ---: |
| File, memory-backed /tmp | 1.280 s | 0.087 ms | 0.142 ms | 0 |
| PostgreSQL | 3.467 s | 9.629 ms | 16.223 ms | 0 |
| Redis, Predis over local TCP | 1.525 s | 0.721 ms | 2.484 ms | 0 |

Redis was substantially quicker than database cache in this specific exercise. It was slower than memory-backed file cache. The production application currently uses database cache, not file cache. There is no basis here for a blanket statement that Redis is always faster than file cache or that these microbenchmark times translate directly into page response times.

The application's real TenantCacheManager was also exercised using explicit synthetic tenant identities:

- Redis values for the same key remained separate between tenant A and B.
- Flushing tenant A preserved tenant B's value and the central value.
- Tenant A acquiring a lock prevented tenant B from acquiring the same lock name. This is an unwanted shared lock namespace for ordinary tenant-local work; cache tags do not automatically isolate that lock operation.
- File cache raised `This cache store does not support tagging.` through the existing tenant cache manager. Merely setting CACHE_STORE=file would break this tenant cache path.

Before Redis cache is enabled, introduce explicit tenant-scoped lock keys and tests for acquire, release, restoreLock, ownership and expiry, while keeping intentionally global scheduler/recovery locks global. Test the installed PhpRedis client, full tenancy bootstrap/end cycles and long-running workers. The synthetic tests set the tenant identity directly and do not replace middleware/session tests.

Redis should hold rebuildable cached data initially. Define TTLs, invalidation and bounded memory. Cache keys must include tenant scope. A cache eviction policy is inappropriate for durable queued work or required lock state without separate safeguards. Redis logical databases do not create separate process-wide memory/eviction policies. Separate instances or deliberately bounded roles are preferable if cache and sessions/queues need different policies.

Redis adds a service to monitor, network calls, memory limits and outage modes. An unavailable Redis server can break cache-dependent requests if the application has no tested fallback. Empty-cache restarts can produce a database stampede. Do not put raw attendance, biometrics, paid payslips or admission correctness solely in Redis. Sessions in Redis can disappear on loss/eviction, logging users out; queue migration requires its own durability/replay tests. Neither is included in this recommendation's first rollout.

## PgBouncer results

The installed tenancy database manager purges the tenant connection when switching to a tenant and when returning to central context. Connection establishment can therefore recur frequently in mixed-organisation workers. Each tenant database remains distinct. PgBouncer cannot turn all 50 databases into one interchangeable backend connection: pools are scoped by database/user.

First trial: transaction pooling, pool size 2, total user backend cap 12. The rotating 50-database workload stalled and exceeded the harness's 100-second timeout. Pooler logs reported client-login timeouts and repeated backend eviction. This is evidence that the tested cap/configuration was unsuitable, not a measured sustainable throughput figure or proof that PostgreSQL was down.

Adjusted trial: transaction pooling, pool size 1, total user backend cap 60, idle timeout 1 second and max_prepared_statements=100. All 1,000 native-prepared tenant-marker checks completed without wrong-database results or query errors.

| Path | Total time | Median connect/transaction | 95th percentile | Successful transactions |
| --- | ---: | ---: | ---: | ---: |
| Direct PostgreSQL | 1.287 s | 41.76 ms | 62.10 ms | 1,000/1,000 |
| Adjusted PgBouncer | 2.165 s | 62.30 ms | 119.30 ms | 1,000/1,000 |

This pool configuration was slower. It reduces per-database backend concurrency, adds a hop and has to manage many small pools. A different working set, latency, query mix or pool sizing may produce a different result. No production pool sizing is certified by this test. The 60-backend test limit was isolated; do not copy it to a production server with a 100-connection ceiling and other applications without a complete connection budget.

Transaction pooling can break assumptions about session SET state, session advisory locks, temporary tables and prepared statements. Current Laravel's PostgreSQL connector sets search_path and can set timezone on connection creation. The gateway already has a bounded pgx pool and uses transaction-level advisory locks; another pooler is unlikely to be the first useful gateway optimization. Its startup migration and command transactions still need explicit compatibility checks if routed through PgBouncer.

For Laravel, a session-pooling pilot is more conservative for session-state compatibility, though it cannot release a backend while a client remains connected. If transaction pooling is chosen, prove the application's fixed search_path/timezone and native prepared-query behavior under forced backend reuse. Use direct connections for migrations/provisioning until their compatibility is demonstrated. Set per-database, per-user and total deployment budgets deliberately. Defaults multiplied across 50 databases can consume many backends.

## Rollout order and acceptance checks

1. Run a staging mixed-load baseline with 50 full tenant databases, realistic records, queues and authenticated traffic. Track connection count, connection creation rate, lock wait, oldest receipt age, application p95/p99, errors and queue drain time.
2. Add admission indexes in staging and rerun the same workload. Plan production index creation with the real table size and an appropriate deployment method; avoid holding a blocking schema change through the peak.
3. Fix Redis tenant lock scoping; test cache invalidation and lifecycle. Pilot rebuildable cache using the installed production client. Keep sessions and custom durable queues on their current stores initially.
4. Split notifications from attendance calculation workers, then tune worker counts against database/CPU limits. Cache changes do not eliminate per-punch tenant queries or calculation cost.
5. Pilot PgBouncer only if connection churn/concurrency is material. Compare direct, session and transaction modes with a complete shared-server connection budget. Validate provisioning, migrations, restarts, failover, prepared statements and tenant switching.
6. Complete the originally requested 200-device/2,500-employee/50-organisation scenario with 40 real authenticated sessions and 20 concurrent manual-entry submissions. Verify raw preservation, approval creation, no cross-tenant access, accurate acknowledgements, duplicate replay, process crash recovery and unchanged paid slips.

Additional tests: full cache memory, Redis outage/restart, cache flush during worker activity, lock expiry while a worker is delayed, PostgreSQL outage/restart, pool saturation, long transactions, queue fairness across organisations, large historical uploads and storage growth. Ensure no false success response precedes durable commit. Bound retries rather than creating retry storms.

## Official references

## Implementation follow-up

The two admission indexes are now included in the local gateway startup schema, with an upgrade regression test. The PostgreSQL gateway suite passed with race detection, and the gateway build passed. Repeated migration preserves existing encrypted receipt bytes and replay IDs. This is not deployed to production. Normal index creation occurs during startup migration and may block writes on existing tables; schedule the rollout or create the equivalent indexes concurrently in a reviewed production operation before restarting. No per-tenant Laravel database schema change is needed for these gateway indexes. The global admission lock remains in place.

Redis recommendation: begin with shared cache infrastructure and mandatory tenant namespaces for both values and locks, with intentional global locks kept separately scoped. Expire cached values with TTLs rather than starting/stopping Redis per tenant. Separate logical Redis database numbers do not provide independent resource limits or failure boundaries. Separate processes are appropriate where a tenant needs dedicated resource/security operations, but add connection, deployment, monitoring and recovery overhead. Do not stop a Redis process based only on user inactivity: workers, sessions or locks may still depend on it.

- [PgBouncer pooling compatibility](https://www.pgbouncer.org/features.html)
- [PgBouncer configuration and pool limits](https://www.pgbouncer.org/config.html)
- [Redis eviction behavior](https://redis.io/docs/latest/develop/reference/eviction/)

The test configuration used simple test-only credentials over local TCP. It is not a production authentication/TLS template. No production secrets or employee data were copied. Temporary test services were stopped after the evaluation; their disposable data and harness remain under /tmp for inspection, not as a supported application installer.
