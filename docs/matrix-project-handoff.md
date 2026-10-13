# Matrix biometric bridge — project handoff

Updated: 2026-10-13, Asia/Kolkata.

This file is the starting reference for continuing the Matrix/ESP32/Biomatrix work without the original chat. Source/configuration was inspected when writing this handoff. Live hardware, deployments and network addresses were **not rechecked on 2026-10-13**. Runtime results below are historical observations from the 2026-10-08/09 conversation, not guarantees of current availability.

## Product and settled decisions

The product connects Matrix COSEC attendance/access devices at remote sites to the Laravel Biomatrix server. One ESP32-S3 per site joins the site's router network and forwards requests to multiple local devices over an outbound secure internet connection. The target is at least ten devices per site; the backend currently permits 64 records per bridge and eight simultaneous forwarding streams. Ten-device capacity was tested with simulated devices, not ten physical units.

- Native **ESP-IDF 6.1**, not Arduino. The Matrix firmware replaced the experimental light-control firmware; the original light source still exists at this workspace root.
- User requested no firmware backup for this experimental board. Do not erase NVS just to flash an update.
- Setup web login stays **admin / locally configured password**, setup AP password **locally configured setup password**, per explicit user preference. These are shared test credentials; cloud authentication uses a separate per-device cryptographic identity. Matrix credentials are independent.
- Router DHCP is the default. Optional static IP/gateway must share a /24 subnet. The PC should not need manual IP changes to access devices through the ESP.
- Local scanner checks only **192.168.10.1–50 and 192.168.50.1–50**. Default selected local Matrix target is **192.168.50.1**.
- Matrix defaults often collide: connect one unconfigured unit, assign a unique permanent IP, verify it, then connect the next. Avoid the DHCP pool or use appropriate reservations.
- Internet-status probes/NAT sharing were explicitly disabled. Remote TLS still requires internet, DNS and SNTP time synchronization.
- User manages Cloudflare Tunnel routes. The default Matrix browser interface now reuses **bridge.aflahdev.in**, rather than requiring one DNS hostname per device.
- An ESP submits a connection request; the organisation administrator must approve it. A pairing code alone does not authorize forwarding.

## Source locations

| Location | Purpose |
| --- | --- |
| `/home/aflah/projects/esp32-color-picker/firmware/matrix_bridge` | Native ESP firmware working source and previously used build directory |
| `/home/aflah/projects/esp32-color-picker/integration/biomatrix/bridge-gateway` | Go gateway working source and tests |
| `/home/aflah/projects/esp32-color-picker/integration/biomatrix/README.md` | Detailed integration/setup guide |
| `/home/aflah/projects/biomatrix` | Actual Laravel/Filament application checkout |
| `/home/aflah/projects/biomatrix/firmware/MatrixBridge` | Delivered native firmware; use this for the documented flash command |
| `/home/aflah/projects/biomatrix/bridge-gateway` | Installed Go source, private environment file and executable |
| `/home/aflah/projects/biomatrix/docs/matrix-remote-bridge.md` | Delivered setup guide; includes chronological verification notes |
| `/home/aflah/projects/biomatrix/Matrix COSEC Device API Manual.md` | Local Matrix Device API reference |
| `/home/aflah/projects/biomatrix/docs/matrix-project-handoff.md` | Copy of this handoff for application-side discovery |

The two firmware/gateway source locations are copies, not an automatic synchronization mechanism. Check which copy is being edited/built and synchronize intentionally. **Do not run root `idf.py flash` in esp32-color-picker when intending to flash Matrix Bridge**: that root project is the older color-picker application.

The biomatrix checkout contains existing user changes and uncommitted implementation files. Preserve them; do not reset or clean the checkout. In this workspace, edits outside writable roots require an approved tool escalation. No Git commit/PR was requested or created during this work.

## Hardware and pin configuration

Previously identified board: ESP32-S3 revision v0.2, MAC `7c:4f:ad:1c:57:0c`, FTDI USB-UART adapter identifier `A5069RR4`. Physical board/silkscreen wiring should be checked before changing hardware.

| Function | Configuration | Evidence / caution |
| --- | --- | --- |
| Onboard addressable RGB LED | **GPIO48**, RMT driver, GRB pixel order | `main/rgb_led.h` and `main/rgb_led.c` |
| Serial console | UART0, **115200 baud** | Native sdkconfig selects default UART0 |
| UART0 TX | **GPIO43** | ESP-IDF ESP32-S3 `soc/uart_pins.h` default |
| UART0 RX | **GPIO44** | ESP-IDF ESP32-S3 `soc/uart_pins.h` default |
| USB serial device | Previously **/dev/ttyUSB0** | Dynamic host port; identify it again before flashing |
| CPU | Dual-core S3, configured **160 MHz** | Do not confuse chip maximum 240 MHz with configured clock |
| Flash | **16 MB**, DIO, **80 MHz** | Current native defaults |
| PSRAM | **8 MB octal**, **80 MHz**, boot memory test enabled | Previously detected and tested successfully on hardware |
| PSRAM internal CLK / CS | GPIO30 / GPIO26 in sdkconfig | Reserved memory signals, not available peripheral wiring recommendations |
| Matrix communication | Wi-Fi/IP, HTTP port 80 | No biometric reader is wired directly to ESP UART/GPIO |

Partition layout: NVS `0x9000 / 0x6000`, PHY `0xf000 / 0x1000`, factory application `0x10000 / 0x300000`. Preserve these offsets when updating the existing board. Firmware explicitly allocates relay pools in PSRAM and reserves 32 KiB internal memory. PSRAM increases working-buffer capacity; it does not itself increase Wi-Fi bandwidth.

## Network addresses and ports

| Endpoint | Purpose |
| --- | --- |
| `https://noti.aflahdev.in/secumax/admin` | Biomatrix organisation dashboard |
| `https://noti.aflahdev.in/api/bridges/pair` | HTTPS connection-request submission |
| `wss://bridge.aflahdev.in/agent` | ESP outbound authenticated WebSocket |
| `https://bridge.aflahdev.in/health` | Gateway process health; does not prove an ESP/device is online |
| `https://bridge.aflahdev.in/launch/<token>` | Short-lived authorized Matrix browser launch |
| `http://127.0.0.1:8000` | PHP app origin behind noti tunnel route |
| `http://127.0.0.1:8071` | Gateway public origin behind bridge tunnel route |
| `http://127.0.0.1:8072` | **Private** gateway control; never tunnel it |
| `http://192.168.4.1` | ESP setup dashboard on its own AP |
| ESP IP, port 8080 | Local Matrix HTTP relay, protected by Matrix's own login |
| Matrix IP, port 80 | Device CGI API and website |

Default Matrix-subnet aliases on ESP station interface: **192.168.10.20/24** and **192.168.50.20/24**. They must be unused; reactive alias-conflict detection stops forwarding/scanning. Aliases are omitted when the primary router address already belongs to that subnet.

Previous router: gateway **192.168.29.1**; ESP DHCP address **192.168.29.216**. These are historical, not permanent defaults. Matrix Wi-Fi **192.168.10.1** was reachable through the ESP; Matrix LAN **192.168.50.1** was not reachable in that test topology. A device cable connected only to a PC is not on the ESP's Wi-Fi broadcast domain. Connect Matrix LAN to the shared router/switch, or put both on a reachable shared network. Guest/client isolation can prevent access even on the same SSID.

A mobile hotspot can provide the ESP's outbound internet connection, but moving only the ESP to a hotspot does not make Matrix devices on the old LAN reachable.

## Architecture and authentication

Flow: Biomatrix tenant device action → private Go gateway endpoint → signed WSS session → ESP local TCP socket → Matrix HTTP/Digest API. PHP owns organisation/device records, credentials and command history; ESP forwards bytes. There is no current offline attendance spool in the ESP.

- Laravel 12 / Filament 4 application with Stancl tenancy and PostgreSQL. Exact installed versions should be rechecked before version-sensitive work.
- `SiteBridge` and `BridgePairingRequest` use the **central** database. `Device`, attendance and commands live in the **tenant** database. Never query `devices` on central `attendence`.
- `ProcessMatrixCommand` may execute synchronously from Livewire. Preserve and restore the previous tenant context; do not indiscriminately call `tenancy()->end()` after a nested operation.
- ESP creates a P-256 private key in NVS. Pairing submits its public key/hardware identity. The gateway challenges each connection with a random nonce; the ESP signs `biomatrix-bridge-v1\n<bridge UUID>\n<nonce>` using ECDSA/SHA-256.
- PHP accepts raw 64-byte `r||s` ESP signatures and DER signatures. Approved bridge identity binds the authenticated socket to its organisation.
- TLS verifies the server with the full ESP certificate bundle. **Cross-signed verification is enabled** for the Cloudflare/GTS Root R4 chain. Do not disable certificate checks to fix connectivity.
- This is verified TLS plus application-level signed authentication, **not mutual TLS**.
- A private `BRIDGE_GATEWAY_KEY` connects PHP/internal registry APIs and Go control APIs. Secrets live in private `.env` files; do not copy gateway keys, Matrix passwords, pairing codes or private keys into this handoff.
- Targets are organisation-owned Matrix devices at approved `192.168.x.x` IPv4 addresses, HTTP port 80. Internal listeners remain loopback-only.
- Multiplexing: 8 streams, 4096-byte chunks, 32768-byte credit window per direction. Bounded queues, deadlines and backpressure prevent unlimited buffering.
- Protocol binary frame: 1-byte kind + 4-byte big-endian stream ID + payload. Kinds: data=1, close=2, half-close=3, credit=4. Normal closure must drain delivered bytes; fixes were made for truncated final responses and unreleased stream slots.

## Pairing and administration flow

1. Join ESP setup Wi-Fi `MatrixBridge-<MAC suffix>` with `locally configured setup password`; this board previously used `MatrixBridge-1c570c`.
2. Open setup dashboard; log in `admin / locally configured password` and connect the ESP to router Wi-Fi using DHCP by default.
3. In Biomatrix **Device Management → Site bridges**, create/manage a site and generate its pairing code.
4. Code is **24 hex characters**, valid for 15 minutes, stored encrypted in the central database. Both site cards and manage/edit page show the same active code. Refresh/polling/repeated generation calls do not rotate it or extend expiry. Expiry permits a new code. Pre-fix hash-only codes could not be recovered.
5. Enter server `https://noti.aflahdev.in` and code in ESP **Remote bridge → Request connection**. Older UI called the button Pair ESP32.
6. The same site card shows an **ESP connection request**, refreshed every five seconds. Review identity/fingerprint and approve or reject. Pending requests expire after 30 minutes and cannot forward traffic.
7. Approval lets the ESP connect automatically. Rejection/revocation denies access. Request history remains visible. Actions enforce organisation ownership and admin/privilege-14 authorization server-side.
8. Configure each Matrix device: vendor Matrix, connection **Remote site bridge**, select the approved site, actual Matrix IP, HTTP/80 and the device's own credentials. Do not enter ESP relay IP/port as the remote target.

Historical test identities, useful for locating previous records but requiring fresh validation:

- Organisation secumax: `0c93ecb4-b72a-4528-b12d-659dc44693c0`.
- Site bridge: `01a11fdc-4015-7166-a484-03d971131ef1`.
- Device ID **5**, display name **VEGA**, target **192.168.10.1:80**. The device UI identified **Matrix PATH V2 Door**; the display name does not prove Vega hardware. This record was changed from Direct network to the approved remote bridge and returned a successful read-only connection check.

## Matrix browser forwarding

Open web interface authenticates the Biomatrix user, resolves the tenant device, creates a one-use launch token and redirects to **bridge.aflahdev.in**. The token expires after 60 seconds; launch establishes a Secure/HttpOnly/host-only session cookie for 15 minutes. Gateway forwards root pages, relative files, CGI requests, Matrix cookies and Digest headers without changing page bodies. Matrix login credentials are still required.

One Matrix device is active per shared-host browser session. A new launch for another device while one is active returns a conflict instead of silently switching existing tabs. Use a separate private browser session for another device; close old Matrix tabs before changing devices after expiry. Legacy individually routed `matrix-<hash>.aflahdev.in` hosts are still supported by Go, but are not required for new default launches.

A redirect-loop bug was fixed: the tenant catch-all previously matched slash-containing launch paths and repeatedly added `/admin`. The launch handler was also corrected for tenancy middleware removing the `tenant` route parameter. Guest launches now go to the correct tenant login; non-admin launches are forbidden. Do not reintroduce a greedy `.*` tenant route.

## Reader configuration

The observed PATH V2 device configured internal card reader as EM Prox (`reader1=1`) while detecting HID iCLASS-U. API manual maps **reader1=4** to HID iCLASS-U. Internal fingerprint was configured (`reader2=1`) but detected as None; selecting a type cannot establish that missing hardware exists.

Added **Devices → three dots → Configure Internal Reader** for Matrix administrators. The modal reads current type, lets the user choose 0–5, writes only `reader1`, then reads it back before reporting success. It preserves fingerprint, external reader and door access policy settings. This is a type correction, not a documented universal `reader-active` switch. Reader inactivity after matching the type still requires diagnosis.

Endpoints: `/device.cgi/reader-config?action=get&format=xml` and `action=set&reader1=4&format=xml`. Expected successful write: `Response-Code=0`. Do not issue a hardware write merely to inspect status. Existing **Configure External / Exit Reader** action remains separate.

## Punch logs — latest requested topic and unfinished work

The last user question was how to retrieve punch logs based on the API manual. Documentation was checked; automatic attendance ingestion was **not implemented** in this conversation.

1. Get current cursor: `/device.cgi/command?action=geteventcount&format=xml`.
2. Fetch events: `/device.cgi/events?action=getevent&roll-over-count=0&seq-number=1&no-of-events=5&format=xml`.
3. Use HTTP Digest authentication; approved remote records route the same request through the ESP gateway.
4. Response contains rollover/sequence, date, time, event ID and detail fields. For user events, `detail-1` is user ID; `detail-3` contains packed flags with bit 0 indicating Entry=0 / Exit=1. Alarm/denied/REX events must not all become employee attendance punches.
5. Manual limits **Direct Door V2 / PATH to 5 events per request**, other supported direct doors up to 100. Current `getEventLogs()` defaults to **20**, and both event-log viewers request 20. **This still needs correcting/model-aware batching.**
6. Existing **View Device Event Logs** is a recent-events viewer, not a durable attendance import. PHP parsing currently preserves only part of the event fields; add rollover and complete raw receipt retention when implementing sync.
7. Proposed reliable sync: per-device durable cursor, bounded batches, immutable raw receipt storage, uniqueness by device + rollover + sequence, tenant-safe attendance mapping, cursor advance only after successful persistence, safe rollover/gap handling, and idempotent retries. Document local device timezone rather than assuming every timestamp is UTC.

Do not claim attendance sync exists just because the device event viewer works. Do not clear device logs to test retrieval. Reboot and clear-log commands currently report unsupported in the Matrix HTTP command implementation.

## Recovery, LED and operational commands

Network reset clears router settings but keeps bridge identity/setup access. Factory reset clears settings and identity; revoke the old cloud identity first. Private keys currently use ordinary NVS: secure boot and flash encryption have **not** been enabled, and no eFuses were burned. A last working authenticated binding is saved; reconnect has bounded backoff. Long outage/power-cut/factory-reset production validation remains incomplete.

An interrupted remote write can have an unknown outcome. Commands marked `outcome_unknown` must not be blindly resent; read/reconcile device state first. New internal-reader action also checks saved state after acknowledgement and reports uncertainty rather than inventing success.

| GPIO48 LED | Meaning |
| --- | --- |
| Steady blue | Setup available, no router configuration |
| Flashing blue | Connecting/reconnecting Wi-Fi |
| Steady green | Router Wi-Fi connected; **not proof of internet/cloud connection** |
| Flashing purple | Local device scan |
| Flashing cyan | Local relay traffic/sessions |
| Flashing red | Matrix alias interface unavailable |
| Fast flashing red | Address conflict |

LED status currently tracks local Wi-Fi/scan/relay, not a dedicated remote-WSS connected indicator.

```bash
# Identify serial hardware and close competing monitors first.
ls /dev/serial/by-id/
fuser -v /dev/ttyUSB0

# Activate the installed native IDF. If activation emits an eim error,
# check the environment rather than chaining blindly with &&.
source /home/aflah/.espressif/tools/activate_idf_v6.1.sh
idf.py -C /home/aflah/projects/biomatrix/firmware/MatrixBridge build
idf.py -C /home/aflah/projects/biomatrix/firmware/MatrixBridge -p /dev/ttyUSB0 flash monitor
# Logs only; exit with Ctrl+]. A running monitor locks the flash port.
idf.py -C /home/aflah/projects/biomatrix/firmware/MatrixBridge -p /dev/ttyUSB0 monitor

# Gateway user service; requires logged-in user session unless lingering/system deployment is configured.
systemctl --user status biomatrix-bridge.service
systemctl --user restart biomatrix-bridge.service
journalctl --user -u biomatrix-bridge.service -f
curl https://bridge.aflahdev.in/health

# Source-level checks in the appropriate checkout.
cd /home/aflah/projects/biomatrix
php artisan test
cd /home/aflah/projects/esp32-color-picker/integration/biomatrix/bridge-gateway
GOCACHE=/tmp/biomatrix-go-cache go test -race -buildvcs=false ./...
```

Installed user service: `/home/aflah/.config/systemd/user/biomatrix-bridge.service`, binary `/home/aflah/projects/biomatrix/bridge-gateway/bin/bridge-gateway`, environment `.env` in that gateway directory. The PHP app, database and cloudflared connector must also run; gateway process health alone is insufficient. An automatic service restart was tested after forced termination.

## Past failures and verification boundaries

- Busy serial port: an old IDF monitor held `/dev/ttyUSB0`. Stop the identified monitor gracefully, flash, then verify boot logs; do not indiscriminately kill unrelated processes.
- Initial static-network trap: old validation rejected DHCP/blank fields and mismatched router subnet. DHCP now supplies gateway/DNS; Matrix aliases are independent.
- Slow/partial Matrix page: local relay was paced at 1 KiB per 10 ms pass. Readiness-driven relay with PSRAM buffering improved observed asset timings; public login completed without manual refresh. Every logged-in page is not verified.
- TLS missing root: Cloudflare cross-signed GTS Root R4 chain needed certificate-bundle cross-signed verification. Both HTTPS and WSS require the setting in defaults **and an existing sdkconfig**, not just defaults.
- WebSocket EOF before approval: a server close can be an expected pending/rejected handshake outcome. Confirm Remote bridge connected state and authenticated heartbeat, not just “websocket client started”.
- Mobile hotspot: initial DNS failure followed by HTTP 530. Public health and WebSocket upgrade subsequently passed, including explicit IPv4; phone health also returned ready, but the ESP was offline at the final check. **The hotspot-specific failure was not conclusively resolved.** HTTP 530 can include a Cloudflare 1xxx error in its body; missing Sec-WebSocket-Accept is a consequence of receiving an HTTP error, not an upgrade. Do not assume CGNAT prevents outbound WSS or disable TLS as a workaround.
- Direct-vs-remote mistake: VEGA still had Direct network despite IP change. Assigning the approved site bridge made the read-only Matrix connection test succeed.
- Code refresh/copy issues: fixed encrypted persistent codes, cross-page reuse and Alpine clipboard scope/fallback; browser clipboard permission behavior remains environment-dependent.
- Tests passed at several stages: full PHP suite reached **26 tests / 92 assertions** before shared-domain switch; later focused PHP checks and Go race tests passed. Counts are historical and will change. Native builds/flash hashes/8 MB PSRAM boot test passed. Public signed pairing, approval flow and read-only real Matrix login through shared domain were verified.
- Not proven: ten physical units, automatic attendance ingestion, every authenticated web page/redirect, all destructive Matrix commands, unattended boot persistence of the complete stack, production secure boot/encrypted-key storage, comprehensive hardware outage/reset recovery.

## Where to continue in code

- Firmware: `main/main.c`, `remote_bridge.c`, `relay.c`, `matrix_alias.c`, `discovery.c`, `wifi_scan.c`, `indicators.c`, `rgb_led.c`, `index.html`.
- Gateway: `bridge-gateway/main.go`, `main_test.go`, optional `live_test.go`. Live test needs a disposable registry fixture; do not run it against user records without understanding setup/cleanup.
- PHP: `app/Services/Bridge/BridgeGateway.php`, `SiteBridgePairing.php`; models `SiteBridge`, `BridgePairingRequest`, `Device`, `DeviceCommand`; `Api/SiteBridgeController`; `ProcessMatrixCommand`; `MatrixDeviceService`.
- UI: `SiteBridgeResource` pages and `resources/views/filament/tenant/site-bridges`; `DeviceResource` and `Actions/ConfigureInternalReader.php`; `ViewDevice` event viewer.
- Migrations: central site bridges, pairing requests and encrypted pairing code; tenant remote-device assignment and unknown-command outcome.

Before new work, read this handoff, current source and applicable AGENTS.md. Recheck current port/IP/service state only when relevant. Keep tenant boundaries, vendor-specific behavior and user changes intact. Do not perform live browser/UI/deployed-site checks unless the user asks. Update this summary only when asked or when an authorized documentation task includes it; global memory updates require explicit user request.
