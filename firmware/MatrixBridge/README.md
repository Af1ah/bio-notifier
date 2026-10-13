# Matrix Bridge — native ESP-IDF

ESP-IDF 6.1 firmware for the ESP32-S3. It joins a shared 2.4 GHz router network, discovers Matrix devices and forwards their HTTP interface through ESP32 port 8080. The laptop can stay on the router network or on the ESP32 setup Wi-Fi without adding PC addresses or routes.

Matrix and the ESP32 must share a broadcast domain. A Matrix LAN cable connected directly to a separate PC is not reachable through the ESP32 Wi-Fi. Connect that cable to the shared router/switch to use its LAN address. Wi-Fi client isolation and separate VLANs can also prevent access.

## Dashboard

The left navigation contains four pages:

- **Matrix devices:** Matrix target (default 192.168.50.1), Open Matrix and bounded device discovery.
- **Wi-Fi settings:** nearby networks, Wi-Fi connection, automatic DHCP or optional static address, and Matrix-subnet address settings.
- **ESP32 status:** uptime, CPU, task count, flash, internal heap, external PSRAM and LED legend.
- **Remote bridge:** cloud pairing, gateway connection state, network reset and factory reset.

Open http://192.168.4.1 from setup Wi-Fi or the ESP32 router-side address printed on serial. Login is always admin / locally configured password. Setup Wi-Fi is MatrixBridge-1c570c on this board, password locally configured setup password. Router credentials remain in the matrixbridge NVS namespace and are never returned by the status API.

## Wi-Fi

Use **Wi-Fi settings → Refresh networks**, select a network, enter its password and choose **Connect**. Automatic DHCP is the default; the router supplies IP, gateway and DNS. A blank password keeps the saved password for the same network. A newly named open network can use a blank password.

Static mode is available under Router address. Its ESP32 IP and gateway must share a /24 subnet, and 192.168.4.x is reserved for the setup AP. Reserve the chosen router-side static address outside the DHCP pool. Matrix-side defaults are separate: 192.168.10.20/24 and 192.168.50.20/24. Choose unused aliases. An alias is omitted if the router-side address already belongs to that Matrix subnet.

Legacy six-field configuration is accepted as static mode. New seven-field configuration includes dhcp/static. New configuration is saved as one versioned NVS blob. Legacy saved Wi-Fi credentials are preserved on upgrade and the old static-address setting is migrated to DHCP to remove the subnet trap. The old NVS blob is retained for compatibility. No flash erase is performed.

Wi-Fi scanning is asynchronous, returns up to 24 AP records, and temporarily pauses reconnect attempts if the station is disconnected. The settings page shows available network names, signal levels and open/secured state. Settings and resources endpoints require the web login.

## Matrix forwarding

Choose the actual device address and select **Open Matrix**. The control first verifies that Matrix port 80 responds. It reports an unreachable target before opening the device page. Only 192.168.10.1–50 or 192.168.50.1–50, port 80, may be selected; ESP32 interface addresses are excluded.

The Matrix web page opens at http://192.168.4.1:8080/ from setup Wi-Fi, or http://<ESP32-router-IP>:8080/ from router Wi-Fi. Use the Matrix device's own credentials. The default target after reboot is 192.168.50.1. Re-selecting the same target preserves active sessions; changing targets or losing router Wi-Fi closes them.

The relay supports eight simultaneous sessions, 16 KB PSRAM buffers per direction, bounded transfer bursts, socket-readiness wakeups, nonblocking transfers and connection establishment, half-close handling and a 30-second idle timeout. Extra connections remain in the listener backlog when all slots are occupied. Both sides use TCP_NODELAY; Wi-Fi modem sleep is disabled for latency. Socket and active TCP budgets are 32 to accommodate paired connections and dashboard requests. Upstream connections use the correct Matrix-subnet source address. It forwards bytes without rewriting bodies, cookies, redirects or URLs. Port 8080 relies on Matrix authentication and is intended for the shared trusted router network and protected setup AP. The selection API additionally requires the ESP32 admin login.

Login-page HTML and public scripts on the tested Matrix PATH V2 use relative URLs and work through the relay. Authenticated Matrix pages and every possible redirect have not been verified. Other firmware may contain absolute URLs that bypass forwarding.

## Device scan

Only 192.168.10.1–50 and 192.168.50.1–50 are checked (100 candidates, including skipped ESP32 source addresses). No host above .50 is scanned. A worker probes one host at a time with a 250 ms connection deadline and up to 500 ms for a bounded 4096-byte HTTP response. An empty scan takes about 26 seconds plus scheduling overhead. Up to 32 HTTP results are retained; truncation is reported. Disconnection cancels the scan and duplicate scan requests are rejected.

A Matrix label means the public response contains a Matrix Comsec, Matrix logo or COSEC marker; other HTTP services remain explicitly unidentified. This is a read-only web fingerprint, not authenticated hardware identity. HTTPS-only services, disabled port 80 and slow or marker-free responses may be missed.

The secondary interfaces use internal lwIP APIs pinned to ESP-IDF 6.1. Another MAC claiming an alias triggers a conflict flag; forwarding/discovery stop. This is reactive detection, not proof that an address is unused. Correct the addresses and reboot after a conflict. Matrix settings, users and attendance logs are not changed by discovery.

## Resources and LED

The 8 MB octal PSRAM is enabled at 80 MHz, memory-tested at boot and available to the heap allocator. Internal DMA memory is reserved separately. The resource page reports actual runtime memory totals/free space/largest blocks, internal minimum-free memory, PSRAM initialization, CPU/core count, tasks, flash and uptime.

| RGB LED on GPIO48 | Meaning |
| --- | --- |
| Blue steady | No router Wi-Fi configured; setup available |
| Blue flashing | Connecting/reconnecting to router Wi-Fi |
| Green steady | Router Wi-Fi connected |
| Purple flashing | Matrix discovery running |
| Cyan flashing | Matrix forwarding sessions active |
| Red flashing | Matrix subnet interface unavailable |
| Fast red flashing | Matrix alias address conflict |

Errors take priority, followed by scanning and relay traffic. Internet probes are disabled at the user's request; green indicates Wi-Fi connection and does not claim internet availability. The ESP32 AP does not share internet access with clients. Matrix forwarding is local and does not require internet.

## Build and flash

```bash
source /home/aflah/.espressif/tools/activate_idf_v6.1.sh
idf.py -C /home/aflah/projects/esp32-color-picker/firmware/matrix_bridge build
idf.py -C /home/aflah/projects/esp32-color-picker/firmware/matrix_bridge -p /dev/ttyUSB0 flash monitor
```

UART is /dev/ttyUSB0 at 115200 baud. Close other serial monitors before flashing. Exit IDF monitor with Ctrl+]. The 16 MB quad-flash board uses a custom 3 MB factory application partition at 0x10000, preserving the existing NVS at 0x9000 and PHY partition at 0xf000. The Matrix application replaces the former light-control firmware; its original source remains at the repository root. No backup or full erase is requested.

## Verified on 2026-10-09

- ESP32-S3 revision v0.2, MAC 7c:4f:ad:1c:57:0c; native IDF build and page JavaScript syntax checks passed. Flash hash verified.
- Boot detected 8 MB PSRAM at 80 MHz, passed its SRAM memory test, and added it to the allocator. Resource API confirmed 8,388,608 detected bytes and 16 MB flash.
- DHCP joined secumax1 at 192.168.29.216 with gateway 192.168.29.1. Wi-Fi scanning returned 16 nearby networks and found secumax1.
- The new DHCP configuration API accepted empty static IP/gateway fields, saved the existing Wi-Fi credentials without exposing them, rebooted and reconnected successfully by DHCP.
- Native ESP32 forwarding to 192.168.10.1 returned HTTP 200 for Matrix login HTML (5112 bytes), home.js (25360 bytes) and MxCommon.js (78522 bytes), through the secondary 192.168.10.20 interface.
- 192.168.50.1 port 80 was unreachable from the ESP32 in the current topology. Selection returned an explicit 503 explanation. Default target remains 192.168.50.1 after reboot; use the currently reachable Wi-Fi target 192.168.10.1 or connect Matrix LAN to the shared router.
- The final native device scan completed all 100 candidates and found 192.168.10.1 with a Matrix fingerprint. No address above .50 was scanned.
- Host-adapter tests of the same relay C source also passed byte-for-byte login/script comparisons, three concurrent transfers and a full HTTP/1.0 response after client half-close.

Authenticated Matrix settings pages, every redirect and visible physical LED colors still need user verification. Boot and runtime checks confirm the LED driver and status tasks start without errors; compilation alone would not establish these runtime results.

## Loading performance update on 2026-10-09

The old relay copied 1 KB per direction with a fixed 10 ms delay after every pass. The update removes that pacing and waits for socket readiness. Eight sessions each receive two 16 KB buffers explicitly allocated in PSRAM: 256 KiB total. Session metadata stays in internal RAM and the worker stack is reduced from 16 KB to 6 KB. Larger buffers reduce copy/send overhead while backpressure still preserves unsent bytes. PSRAM is external working memory, not flash storage; its capacity permits these buffers without consuming the small internal heap. It does not increase radio bandwidth by itself.

The updated actual relay C source passed 12 simultaneous/queued asset transfers with byte-identical content and a slow-reader/client-half-close test. The native firmware was flashed and hash-verified. On the real ESP32, successful uncached jQuery transfers measured approximately 2.103 seconds before and 0.608 seconds after; HTML measured 1.214 seconds before and 0.174 seconds after. Six concurrent login assets completed in 0.618 seconds after the update. These are individual observations on this network, not guaranteed timing across every load. Runtime resources reported 230,231 bytes internal free and 8,123,308 bytes PSRAM free, consistent with the external relay pool.

A fresh browser tab at the ESP32 relay URL displayed the complete Matrix login controls, reached document readyState complete, loaded its logo image, and reported no console errors without a manual refresh. The logged-in dashboard's initialization has not been verified by the agent; user confirmation is pending. No automatic reload script, page-body rewrite, or caching of authenticated responses was added.

## Remote site access

The current source includes an outbound authenticated WSS agent. See [the remote setup guide](../../docs/matrix-remote-bridge.md) for pairing, server routes, device provisioning, recovery and verification boundaries. Local admin / locally configured password and Wi-Fi / locally configured setup password remain configured for this test deployment. The newer remote firmware has now been flashed and hash-verified on /dev/ttyUSB0. Startup verified 8 MB PSRAM, its memory test, router Wi-Fi at 192.168.29.216 and the Remote bridge status API. Cloud pairing and remote device operations still await verification.

## Private local credentials

Run `idf.py menuconfig`, then open **Matrix Bridge local setup credentials** and set the setup Wi-Fi and admin passwords before a fresh build. Values are stored only in ignored sdkconfig. Wi-Fi password must be 8–32 characters; admin password 1–32. Builds without configured credentials fail rather than embedding shared secrets. Existing local credentials were retained in the private build configuration. Never commit sdkconfig or generated build artifacts.
