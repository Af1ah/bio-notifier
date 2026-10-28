# Developer guides

Client-facing configuration and operations are in [Client guides](../client/README.md). Keep SQL, migration commands, source references and test evidence in developer documentation.

| Document | Scope |
| --- | --- |
| [Leave system design and rollout](leave-system.md) | Approved plan, schema, credit/reward evidence, tenant settings, migration and verification |
| [Production stamps rollout](production-stamps-rollout-2026-10-20.md) | Backup, migrations, data hashes, service verification and device-test limits |
| [ADMS stamps, historical uploads and device clearing](adms-stamps-history-and-clear-2026-10-20.md) | Protocol comparison, 8,000-record imports, bounded queues and attendance-only deletion |
| [Magnum validity investigation](magnum-validity-investigation-2026-10-24.md) | Physical reset failures, command audit, disabled draft and recovery checks |
| [Device direction, automatic time and access rules](device-direction-time-and-access-rules-2026-10-24.md) | Implemented direction classification and clock negotiation; access-validity gaps and firmware verification |
| [Device time sync and attendance replay](device-time-sync-and-attendance-replay-2026-10-20.md) | Shared corrections, immutable raw evidence, safe resend reconciliation and per-device clock sync |
| [Attendance and payroll design](../attendance-payroll-design.md) | Shift occurrences, corrections, approvals, proration and export architecture |
| [Tenant isolation fixes](../tenant-isolation-fixes.md) | Tenant boundaries and eBio isolation |
| [Web master-panel cleanup](web-master-cleanup-plan.md) | Remove obsolete web features, duplicate queries and legacy flows without breaking registration or recovery |
| [Beta verification and recovery](../beta-verification.md) | Development checks, deployment and recovery |
| [Windows desktop edition](windows-desktop-plan.md) | Local services, desktop EXE, no master panel, offline installer and signed updates |
| [Semi-offline vs fully offline](local-first-options.md) | Reduce cloud workload, compare customer operation and choose the local-first product mode |

Older engineering documents remain in the parent docs directory. New client guides belong under docs/client; new engineering guides belong here.

- [Master panel and desktop scope](master-panel-desktop-scope.md): current duties, automated device registration, and removing master/eBio support from the desktop edition.
