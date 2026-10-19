# ADMS gateway adoption guide

## Purpose

This guide expands device compatibility without replacing the current payroll gateway with a temporary in-memory ADMS server. It uses the upstream [`s0x90/zkteco-adms`](https://github.com/s0x90/zkteco-adms) project as the protocol reference and test source.

The current gateway remains the production boundary. It owns device-to-company binding, source-network approval, encrypted raw receipts, durable command state, retries, command acknowledgements, and delivery to Laravel. The upstream library is useful for its protocol coverage, not as the database or multi-tenant system.

## Decision

Use a **compatible adapter** approach.

1. Keep the Go gateway listener, PostgreSQL store, Laravel internal API, and tenant binding model.
2. Port only tested ADMS parsing and command formats from upstream into the gateway.
3. Add a device profile after a real device has shown its model, firmware, and supported responses.
4. Release each capability only to profiles that passed the hardware test.

Do not replace the gateway with `zkteco-adms.NewADMSServer()` directly. Its devices, command queues, pending command results, and online state are in process memory. A restart loses that state. It does not own organisation binding, payroll evidence, or a durable tenant-safe command result.

## Compatibility scope

Both projects use the HTTP ADMS/iClock protocol. This covers a terminal which calls one or more of these routes:

| Device behaviour | Required gateway behaviour | Current direction |
| --- | --- | --- |
| `GET/POST /iclock/cdata` | Handshake, device information, attendance and user data | Supported |
| `GET /iclock/getrequest` | Receive the next queued command | Supported |
| `POST /iclock/devicecmd` | Correlate command result by the wire command ID | Supported |
| `GET/POST /iclock/registry` | Read a registration/capability payload | Accept and record safely |
| `ATTLOG` | Parse PIN, local time, state, verify mode, work code | Supported; extend parser test corpus |
| `USERINFO` and compatible `OPERLOG` user rows | Store device-user projection and complete user queries | Supported; expand variants after device tests |
| Device information push | Store model, firmware, platform, device name and counters | Supported; normalize profile fields |

This does **not** add support for the ZKTeco binary TCP SDK, eBio SOAP, a proprietary cloud-only protocol, or a device that cannot send HTTP ADMS/iClock requests. A model is compatible only after it passes the hardware pilot below.

## Tenant workflow: equal to eBio, without per-device master setup

The tenant user should see one simple Devices flow:

1. Choose **Add device**.
2. Enter device name, serial number, location, and optional LAN IP for reference.
3. On the first device from an office, enter the office public IP or approved CIDR once. Save it on the organisation and reuse it for every later device in that office.
4. The application automatically creates the central binding, creates or reuses the tenant device record, queues binding sync, and shows the gateway address to enter on the device.
5. The device polls the gateway. A verified handshake changes its state from **Waiting for connection** to **Connected**.
6. The device’s three-dot menu and **Run command** page show only its approved commands. The command history shows queued, delivered, acknowledged, failed, expired, or needs review.

The master panel owns only the shared gateway endpoint, service credentials, health, and recovery. It must not require an administrator to create forty separate device bindings.

### Existing eBio-style screens

Keep these product behaviours identical between eBio and gateway companies:

| Screen | eBio behaviour | Gateway equivalent |
| --- | --- | --- |
| Devices | Add device, list connection state, three-dot actions | Add device automatically binds it; same list and actions |
| Run command | Pick device and a permitted command | Same dialog, with gateway-approved commands only |
| Device actions | Show queued and completed work | Same records, with durable Go result correlation |
| Attendance logs | Show received activity | Show applied gateway receipts and source device |
| Users on device | Read device roster | Query users and show the projected roster |

## Current employee biometric workflow

See [Employee biometrics](employee-biometrics.md) for the implemented registration, combined push and automatic once-only fetch behavior. Device verification formats control available transfer checkboxes. Card is the only default selection.

## Upstream capabilities to adopt

The upstream project supplies tested command formats for the following. Adopt them through named operations, never a free-text command box.

| Operation | ADMS wire format | Tenant exposure | Pilot requirement |
| --- | --- | --- | --- |
| Device information | `INFO` | Read-only | Confirm data push or command-result payload |
| Connection check | `CHECK` | Read-only | Confirm `Return=0` |
| Read all users | `DATA QUERY USERINFO` | Read-only | Confirm `USERINFO` response table |
| Read device option | `GET OPTION FROM <key>` | Read-only, option allow-list | Confirm each firmware’s response format |
| Add/update user | `DATA UPDATE USERINFO ...` | Employee/device-user action | Confirm user, card, and privilege handling |
| Delete user | `DATA DELETE USERINFO PIN=<pin>` | Restricted destructive action | Confirm the correct user was removed |
| Request logs | `LOG` | Support/admin action only until data response is verified | Confirm whether the terminal sends usable log data |

Do not adopt upstream `QueueCommand` as a tenant-facing raw command API. Do not expose upstream `Shell <command>`: it can execute operating-system commands on some terminals and can damage a device.

## Required code changes

### 1. Finish automatic binding safely

The source address seen by a cloud gateway is the office public address, not a terminal’s private `192.168.x.x` address. Store `gateway_source_cidr` on the organisation. The first tenant device registration captures it; later registrations reuse it.

`TenantDeviceRegistration` must:

- reject a serial owned by another organisation;
- create or refresh the tenant device and central binding in the correct tenant context;
- use the saved organisation source CIDR, never the optional LAN IP;
- queue `SyncGatewayBinding` after the central transaction commits;
- tolerate a retry after a failed binding sync;
- never disclose management tokens or the shared gateway API address.

Existing devices need an explicit, audited one-time capability upgrade and binding re-sync. Do not enable new commands globally as part of a silent migration.

### 2. Add a protocol compatibility adapter

Keep `internal/gateway/server.go` as the HTTP entry point. Extract device-specific parsing into a small adapter package with tests copied or adapted from upstream:

```text
internal/gateway/
  protocol.go          route and handshake rules
  parser.go            bounded ATTLOG, USERINFO, OPERLOG and info parsing
  compatibility.go     device-profile response variations
  commands.go          named command builders and result rules
  storage.go            durable receipts and command lifecycle
```

The adapter must accept both plain and `.aspx` endpoint variants. It must preserve the current bounded body size, strict serial validation, source-CIDR check, encrypted receipt persistence, and response only after durable acceptance.

### 3. Use typed command parameters

Replace fixed command strings with a command definition:

```text
operation: get_option | upsert_user | delete_user | query_users | info | check | log
parameters: validated JSON object
wire command: generated only by the Go gateway
```

Rules:

- allow only named operations present in the device binding capability list;
- allow only expected keys for each operation;
- reject carriage returns, line feeds, tabs, overlong values, and unknown keys;
- use a PIN, name, card and approved privilege value for user updates;
- store the immutable parameter hash with the command request;
- keep the same command UUID through Laravel outbox, central submission, Go storage, device wire ID, and result event;
- never send a second command when outcome is ambiguous. Mark it **Needs review** instead.

### 4. Add profiles instead of claiming universal compatibility

Create a central `gateway_device_profiles` record with:

- profile name;
- model/platform/firmware match rules;
- supported request routes and table variants;
- verified command capabilities;
- expected command acknowledgement style;
- pilot date, tester, and evidence reference.

When a device first connects, store its observed model, platform, firmware, `pushver`, and route/table behaviour. Suggest a profile, but do not auto-enable unsafe capabilities. A master administrator approves the profile after a real-device test.

### 5. Keep security and tenancy boundaries

- Serial number is unique globally and bound to one organisation.
- Device PIN is never a global identity. The same PIN can exist at multiple companies because every receipt resolves through the device binding first.
- A reverse proxy may provide `X-Forwarded-For` only when its own source address is trusted and it overwrites that header.
- Device traffic cannot reach management routes.
- The tenant UI cannot choose a gateway connection, another organisation, raw wire command, or management credential.
- Raw device payloads remain encrypted evidence and never appear in normal tenant tables.

## Rollout order

1. Back up central, tenant, and Go PostgreSQL databases. Record current binding versions.
2. Deploy the automatic registration/source-CIDR change with no new destructive commands.
3. Pilot handshake, attendance, online state, and command acknowledgement on each device family.
4. Add read-only operations: `INFO`, `CHECK`, user query, and selected option reads.
5. Add user update/delete only for a profile that proves correct confirmation and roster refresh.
6. Add log requests only if the terminal returns a documented, parseable result.
7. Enable a capability per profile and company; re-sync only those selected bindings.
8. Monitor command outcomes, retry rates, rejected source addresses, unknown protocol tables, and receipt-to-Laravel delivery age for one payroll cycle.

## Hardware acceptance checklist

For each model and firmware, collect an anonymized protocol capture and prove:

- first connection and every route used by the terminal;
- correct Asia/Kolkata timestamp parsing;
- each verification mode observed, including face/card/fingerprint where applicable;
- duplicate punch handling;
- device offline/online transition;
- result acknowledgement for each approved command;
- user query response and user update/delete results;
- gateway restart while a receipt and a command are pending;
- tenant separation using the same employee PIN on two companies;
- source-IP rejection from an unapproved network.

Only mark a profile compatible after all applicable checks pass.

## Definition of done

The gateway is ready for a device profile when a tenant can add a device once, configure the published gateway address on the terminal, see it connect, use the same command/history workflow as eBio, and recover safely from restarts or network failure without losing attendance or sending a command twice.
