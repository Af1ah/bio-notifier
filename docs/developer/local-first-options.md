# Local-first plans: semi-offline and fully offline

Date: 20 October 2026. Status: architecture comparison and proposed scope, not an implemented release or operating-cost guarantee.

## Main objective

Run attendance ingestion, employee/biometric operations, leave, calculations, approvals, payroll, reports and exports on the customer's host. Keep normal company activity away from our cloud workers and database. Both plans use the same Windows services, local PostgreSQL and existing company interface. Neither desktop plan exposes the master panel or supports eBio. Both use only our Go ADMS gateway; eBio remains temporarily supported by the hosted product.

“Almost free” here means a very small or absent recurring cloud workload. It does not remove the cost of the customer's always-on PC, electricity, backups, support, update distribution or any chosen product license. Existing hardware may be enough, but minimum specifications and device counts need a pilot.

## Plan A — Semi-offline, with a small cloud connection

The local installation is the authoritative company system. Devices communicate with the local Go gateway. Local PostgreSQL stores attendance and credentials; local Laravel workers calculate payroll and produce PDFs/CSV. The EXE and authorized LAN users open that local application.

```text
Devices -> local Go gateway -> local PostgreSQL -> local payroll and reports
                                      |
                                      +-> optional bounded sync outbox
                                               |
                                               v
                                    small cloud summary/status service
```

Internet is optional for normal work. When available, it supports update checks, optional service health and small company summaries. Losing Internet must not block login to existing local accounts, device attendance, payroll or exports. Setup must support an offline installation path too.

Default cloud data, only when the customer enables it:

- One installation's software/schema version and coarse health, such as last successful intake and unapplied-work count.
- A bounded company/day or company/month summary, such as employee count, attendance totals or payroll-run readiness. Salary totals are a separate explicit choice, not necessary for health monitoring.
- Update-feed metadata and an acknowledged sync cursor.

No raw punch stream, biometrics, employee photos, complete employee roster, payslip attachments or duplicate payroll computation goes to our cloud by default. Full cloud backup and full cloud payroll pages are separate features with separate storage/privacy/operating costs; do not silently enable them.

Upload on a schedule with jitter, for example hourly for summaries and daily for update checks, not on every punch or screen view. Coalesce replaceable health snapshots. Preserve business summaries in a bounded local outbox until cloud acceptance; use installation/company identity, stable IDs, schema version and idempotent acknowledgement. Bound batch size, backoff and local disk consumption and show sync problems without interrupting payroll. Never discard the authoritative local records because the cloud is unavailable.

The initial remote cloud view is **read-only summaries**, not the full company application. Live remote payroll can use a separately configured secure connection to the local host while it is online; it is not magically available when that host is off. Cloud-to-local employee/payroll editing and conflict resolution are outside this plan. Keep one write authority to reduce code, support work and reconciliation errors.

Updates can be downloaded when online or imported from a signed offline patch. No customer must remain online just to continue ordinary payroll. Cloud endpoints authenticate and scope the installation to its company; a claimed tenant ID alone is insufficient. Replacing a host requires an explicit cutover, not two active installations sharing one identity.

### Server-load effect

Our servers no longer receive device polling, individual attendance application, template transfers or payroll generation for that company. Cloud work is limited to small summary/status writes, summary reads and update distribution. Store prepared snapshots instead of recalculating attendance on dashboard requests.

Illustration, not a benchmark: 1,000 companies uploading once per hour produce 24,000 batches per day, averaging about 0.28 batches/second. With an assumed 10 KB application payload per batch, that is approximately 240 MB/day before protocol overhead. Traffic bursts, retention and dashboard reads still matter. Distribute large signed update files through static artifact hosting/cache rather than Laravel workers; release downloads can outweigh summary traffic.

### Customer trade-off

Customers get full local payroll plus optional remote visibility and easier updates. They still need their local host and backups. A cloud summary is not a recoverable backup of their company database.

## Plan B — Fully offline company installation

The same local runtime performs all company work. There is no automatic business-data sync, online activation requirement, update polling, telemetry or dependency on our cloud service.

```text
Devices -> local Go gateway -> local PostgreSQL -> local payroll and reports
                                                    |
                                                    +-> encrypted local/offsite backup
                                                    +-> manually imported signed update
```

A complete offline setup includes the required application runtimes. After installation, supported attendance and payroll workflows work without Internet. Features that inherently need an external provider, such as WhatsApp delivery, remain unavailable or queue safely until enabled connectivity; do not describe those as fully offline capabilities. LAN devices and authorized LAN workstations still use networking inside the office.

An administrator obtains a signed patch on another machine and imports it from a file or USB drive. The same updater validates signatures, compatibility and backups and coordinates migration; an offline patch does not bypass safeguards. Importing a patch needs no developer tools, terminal commands or manual database editing.

Backups must include all local databases, private biometric/photo storage and recoverable encryption keys. Offer automatic backups to a customer-controlled destination and an encrypted export for relocation. A backup only on the same disk is insufficient for disk failure. The customer owns the backup schedule and an operator must periodically prove restoration on another host.

No cloud summary or online remote access is included. A client EXE on another office PC can connect to the host over the configured private LAN. A desktop viewer is not a second server/database. Exported reports can be shared manually.

### Server-load effect

Normal company operations generate zero requests to our cloud. Our infrastructure is used only when an operator chooses to download an installer/patch or request support. We still maintain and distribute releases; this is not zero product maintenance cost.

### Customer trade-off

This gives the lowest recurring cloud demand and the most local control. Customers must take responsibility for host availability, backup copies and applying software/runtime security updates. An offline PC with outdated software is not automatically safer.

## Comparison

| Question | Semi-offline | Fully offline |
| --- | --- | --- |
| Where do attendance and payroll run? | Customer's host | Customer's host |
| Is a desktop master panel needed? | No | No |
| eBio dependency/support | None; our Go gateway only | None; our Go gateway only |
| Internet needed for normal payroll? | No | No |
| Normal demand on our cloud | Small optional scheduled summaries and health | None |
| Cloud biometric/raw-punch storage | None by default | None |
| Use the existing full company UI? | Yes, locally or on the configured LAN | Yes, locally or on the configured LAN |
| Remote cloud view | Optional read-only summaries | Not included |
| Updates | Online check/download or signed file import | Signed file import |
| Recover after local disk failure | Customer backup; optional separately funded remote backup | Customer backup |
| PC off or asleep | No local ingestion; depends on device buffering/replay | Same limitation |
| Move to another PC | Restore validated backup and cut over devices | Same process |
| Customer maintenance work | Lower for updates; backups still required | More manual update and support coordination |
| Engineering scope | Local runtime plus a limited sync/status service | Local runtime only |

Neither option promises cloud recovery of data that was never backed up, nor live remote access to a powered-off host. The browser shell does not change these boundaries.

## Recommendation

Build **one local runtime first**, using the fully offline behavior as its foundation. Offer two installation settings on top of it:

- **Local only:** no cloud communication; signed manual updates.
- **Local with cloud summaries:** the same local payroll with opt-in scheduled summaries/health and update checks.

Make the second option available without forcing cloud registration for local-only installations. Keep configuration, protocol, local databases, backups and patch format identical between the two. Switching the summary connection off must not create a separate product fork or require moving company data.

For most customers, recommend local payroll with optional cloud summaries for convenience. For customers whose priority is no recurring cloud dependency, select Local only. Do not rebuild the full hosted application as a continuously synchronized second payroll system: that would restore much of the cost and complexity we are trying to remove.

## Delivery order

1. Finish the shared Windows service runtime, no-master setup, company administration, device setup and local payroll flow.
2. Prove offline fresh installation, real-device ingestion, overnight calculations, PDF/CSV exports, restart recovery and backup/restore.
3. Deliver signed manual patch import with migration checkpoints and safe recovery.
4. Add optional static update checks/downloads.
5. Add the small authenticated summary/status outbox and read-only cloud view only after measuring actual business need.

Measure server requests, stored bytes, update-download traffic, local queue age and restore success in a pilot before advertising a low-cost service tier. No specific cloud price or unlimited-device capacity is claimed by this plan.

The supporting [Windows packaging plan](windows-desktop-plan.md) covers the build-script contract, services, worker supervision, PostgreSQL, signatures and upgrade sequence. This comparison adds the product modes; it does not change the device protocol.
