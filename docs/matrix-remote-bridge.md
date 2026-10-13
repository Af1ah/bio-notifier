# Biomatrix remote site bridge

One ESP32-S3 at each site maintains an outbound, certificate-verified WSS connection to the gateway. Pairing requires administrator approval before binding its P-256 public key to one organisation. Each connection signs a fresh server challenge; the private key stays in ESP32 NVS. Laravel approves the tenant, bridge, device address and HTTP port before obtaining a private forwarding endpoint. Existing Matrix HTTP/Digest operations use that endpoint.

## Server routes

| Purpose | Public destination | Tunnel service |
| --- | --- | --- |
| Biomatrix admin and pairing | noti.aflahdev.in | http://127.0.0.1:8000 |
| ESP agent and Matrix browser sessions | bridge.aflahdev.in | http://127.0.0.1:8071 |

The existing bridge.aflahdev.in route serves both the ESP WebSocket agent and Matrix browser files. No per-device DNS or extra tunnel route is needed. Biomatrix creates a short-lived `/launch/<token>` path; the gateway sets a host-only secure session cookie and redirects to `/`. Matrix pages, scripts, images and CGI requests then use their original paths on the same domain, through the selected site's ESP. Opening the bare domain without a session asks you to open a device from Biomatrix.

The shared hostname supports one active Matrix device per browser session. Launching the same device renews its session. Launching another device while one is active returns a clear conflict instead of silently changing the device behind existing tabs. Use a separate private browser window for another device, and close old Matrix tabs before changing devices after session expiry. Existing individually routed matrix hostnames remain supported by the gateway, but new launches use the shared domain.

Private control listens at 127.0.0.1:8072. Keep this port and dynamically allocated loopback device endpoints out of the tunnel. The gateway and Laravel must run on the same host/network namespace with access to loopback. The private gateway secret must match the app `.env`; the installer configured both without displaying it.

## Set up a site

1. Flash `firmware/MatrixBridge` with ESP-IDF. No Arduino is involved.
2. Join the ESP setup Wi-Fi `MatrixBridge-<MAC suffix>` using `locally configured setup password`. Open http://192.168.4.1 and log in with `admin` / `123`, as configured for this test deployment. These shared local credentials should be changed before deploying to an untrusted site; neither the dashboard nor port 8080 belongs in the public tunnel.
3. Open **Wi-Fi settings**, scan, select the site's 2.4 GHz router network and connect. Use **DHCP** unless there is a reserved static address. The router's gateway and DNS are supplied by DHCP; its default subnet need not be 192.168.10.x or 192.168.50.x.
4. At https://noti.aflahdev.in/secumax/admin, use an administrator account. Open **Device Management → Site bridges**, create a site, then open its site card and select **Generate pairing code**. The code is shown with a copy button and expires in 15 minutes.
5. On the ESP dashboard, open **Remote bridge**, use server `https://noti.aflahdev.in` and enter that code and select **Request connection**. On the Biomatrix site card, review the incoming ESP identity and select **Approve ESP** or **Reject request**. The page refreshes requests every five seconds. A request expires after 30 minutes; pending or rejected ESPs cannot forward traffic. After approval, wait for **Gateway connected**. Internet access, DNS and clock synchronization are required; the ESP uses UDP 123 to pool.ntp.org for its TLS clock and TCP 443 to the public app/gateway.
6. Add or edit each Matrix device in biomatrix: vendor Matrix, connection **Remote site bridge**, select the paired site, enter the device's real local IP, HTTP port 80 and its own Matrix username/password. Up to 64 records are permitted per bridge; eight TCP transfers can run at once. Start with one device and a read-only connection check before testing changes.
7. Existing Matrix actions now travel through the site ESP. **Open web interface** creates an authorized browser session on bridge.aflahdev.in using the existing tunnel route. The browser still needs the Matrix device's own login.

## Commission devices one at a time

Do not connect several unconfigured devices that share 192.168.50.1 or 192.168.10.1. Connect one to the shared router/switch, discover/open it, assign an unused permanent IP, verify it, and then connect the next. Use static addresses outside the router DHCP pool or reservations that prevent another client taking them. Add each final address to biomatrix.

The ESP's aliases are 192.168.10.20 and 192.168.50.20 by default. Reserve or change them if already occupied. Local discovery stays limited to 192.168.10.1–50 and 192.168.50.1–50. Final addresses in the router's different 192.168.x.x subnet are entered directly in biomatrix rather than discovered by that limited scan.

The ESP and Matrix devices need a shared broadcast domain. A Matrix cable connected only to a laptop is outside the ESP's Wi-Fi path. Guest isolation and separated VLANs also block this arrangement. Matrix LAN 192.168.50.1 was unreachable in the earlier hardware test; its Wi-Fi 192.168.10.1 was reachable.

## Recovery and stored data

The ESP saves router settings, its pairing identity and gateway binding in NVS. The server database owns device records and command history. Matrix usernames/passwords are used by the existing backend client, not sent as ESP configuration. The ESP keeps no offline attendance spool; collecting device logs after an outage uses the existing device/backend workflow.

After Wi-Fi or gateway loss, active forwards close and the ESP reconnects with bounded backoff. The setup AP stays available. A last successfully authenticated gateway binding is stored and can be restored after repeated connection failures. This does not bypass cloud revocation or provide a second internet connection.

**Reset Wi-Fi** clears saved router settings but keeps the bridge identity and setup credentials. Reconnect using the setup AP. **Factory reset** clears settings and identity; revoke the old bridge in biomatrix first, then create and pair a new bridge. Cloud revocation immediately closes the gateway connection when control is reachable; registry heartbeat also enforces it.

An interrupted remote write can have an unknown result even if the server reports a failure. Such commands are marked `outcome_unknown` and cannot be blindly retried. Read the device's actual state and reconcile first. Unsupported commands report failure; they are not simulated as successful. Reboot/clear-log support remains limited by the existing Matrix API implementation.

Private-key storage is currently ordinary NVS. Secure boot and flash encryption have not been enabled, and no irreversible eFuse changes were made. This is a prepared pilot, not completed physical-device production hardening.

## Operate the gateway

The installed user service is enabled and uses `Restart=on-failure`. It starts with the user's systemd session. Boot operation before user login requires an approved lingering user session or a system-level deployment; the PHP app, database and tunnel must also run.

```bash
systemctl --user status biomatrix-bridge
journalctl --user -u biomatrix-bridge -f
systemctl --user restart biomatrix-bridge
```

The installed gateway is `/home/aflah/projects/biomatrix/bridge-gateway`; its private `.env` has mode 0600. Health is https://bridge.aflahdev.in/health. A health response means the gateway runs; it does not prove a site ESP or Matrix device is online.

## ESP flash and serial logs

The connected board uses `/dev/ttyUSB0`, at 115200 baud. Its old IDF monitor was closed before flashing; always exit a running monitor with Ctrl+] before another flash.

```bash
ls /dev/serial/by-id/
source /home/aflah/.espressif/tools/activate_idf_v6.1.sh
idf.py -C /home/aflah/projects/biomatrix/firmware/MatrixBridge -p /dev/ttyUSB0 flash monitor
```

For logs without flashing:

```bash
idf.py -C /home/aflah/projects/biomatrix/firmware/MatrixBridge -p /dev/ttyUSB0 monitor
```

Exit with Ctrl+]. Close other serial monitors before flashing. Do not erase flash when preserving Wi-Fi and pairing state.

## Verification on 2026-10-09

- Native ESP-IDF build passes, including remote agent, PSRAM buffers, UI and reset endpoints. Dashboard JavaScript syntax check passes.
- Laravel: the complete PHP suite passes with 18 tests and 70 assertions, covering existing Matrix API behavior, pairing retries/expiry, signed authentication, revocation and blocked retries for uncertain writes, request approval/rejection/expiry, organisation ownership, admin permissions and rendered card/manage pages.
- Go gateway: race detector passes for rejected proofs, cross-tenant denial, target validation, ten managed device endpoints, eight simultaneous responses exceeding the 32 KiB flow-control window, host-bound sessions, single-use launch tokens, expiration, secure cookies, preserved Matrix login cookies/Digest headers, and revocation.
- Live Cloudflare routes: HTTPS pairing through noti.aflahdev.in and WSS authentication through bridge.aflahdev.in pass using the same raw P-256 signature format as the ESP. Simulated HTTP traffic passes through that public connection and the private endpoint. The public test also confirmed the request stays pending until approved and forwards only afterward. The temporary database fixture was deleted afterward.
- Gateway user service is enabled and running. A forced process termination triggered automatic restart; public health recovered.
- Remote firmware was subsequently flashed on the connected ESP32, with its flash hash verified. Startup confirmed 8 MB PSRAM and a passing memory test, router IP 192.168.29.216, and the authenticated Remote bridge API responding with clock_ready=true and paired=false. Actual ESP cloud pairing, authenticated Matrix commands, browser sessions through the additional device-host route, outage recovery on hardware and reset behavior remain unverified. Ten real Matrix units were not connected; capacity tests used simulated devices.

## Approval UI update

Site bridges now use responsive status cards and a manage page with code generation, copy, incoming requests, approve/reject actions, recent request history and revocation. Previously paired bridges keep their existing access. Newly submitted ESP requests require administrator approval before authentication succeeds. The updated ESP firmware adds Request connection, waiting-for-approval status, and automatic local binding clearing after rejection/revocation so a new code can be submitted without erasing Wi-Fi or its private key. This newer firmware was built but not flashed in this update because the board is no longer on this PC's USB port. The earlier Pair ESP32 button can still submit requests; its waiting/rejection display remains less specific until updated.

## Pairing stability and TLS update

The active pairing code is now encrypted in the central database. Both site cards and the manage page reveal the same unexpired code to their authorised administrator. Refreshing or polling does not replace it or extend its expiry. Repeated generation requests reuse the existing code; a new code is created only after expiry. Approval hides and erases the revealable code while retaining its hash for bounded retry recovery. Rejection and revocation clear it. Codes created before this change cannot be recovered from their hashes; generate once after refreshing to establish the new persistent code.

The copy control now has its own Alpine scope, a selectable read-only input, clipboard fallback and manual-copy feedback. Source tests cover clipboard success, denied clipboard fallback, and blocked-copy feedback; a browser clipboard click has not been verified.

The public tunnel presents a cross-signed GTS Root R4 chain. The full ESP-IDF certificate bundle contains the same trusted root key, but cross-signed verification was disabled. CONFIG_MBEDTLS_CERTIFICATE_BUNDLE_CROSS_SIGNED_VERIFY is now enabled in the shipped defaults and existing native build configuration. Both native builds pass; certificate verification remains enabled. The updated trust configuration still needs flashing and a real ESP HTTPS/WSS handshake check, since no USB serial board is currently present on this PC. An HTTPS handshake failure occurs before the pairing POST is sent, so that failed attempt creates no approval request. Successful submissions appear on the same site card under ESP connection request.

## Shared-domain browser verification

The default browser hostname now reuses bridge.aflahdev.in. Gateway race tests pass for shared-host launches, cookie/asset forwarding, same-device session renewal and rejection of silent cross-device switching. The application launch-route tests pass. A real VEGA launch through the public domain returned HTTP 200 with Matrix login HTML over the ESP. This read-only check does not verify every authenticated Matrix settings page.
