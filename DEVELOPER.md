# Matrix COSEC Device Integration & Hardware Diagnostic Guide

This document provides a comprehensive technical reference for integrating, debugging, and maintaining **Matrix COSEC** biometric and door controller hardware (ARGO FACE, VEGA Door, PATH readers, etc.) within the **Biomatrix** platform.

---

## Table of Contents
1. [Architectural Overview & Authentication Protocols](#1-architectural-overview--authentication-protocols)
2. [Hardware Penetration & Deep Inspection Methodology](#2-hardware-penetration--deep-inspection-methodology)
3. [The "Exit Reader Inactive" Root Cause Investigation](#3-the-exit-reader-inactive-root-cause-investigation)
4. [Hardware Event Log Decoding & Diagnostic Protocol](#4-hardware-event-log-decoding--diagnostic-protocol)
5. [CGI REST API Reference](#5-cgi-rest-api-reference)
6. [Codebase Architecture & Implemented Files](#6-codebase-architecture--implemented-files)
7. [Troubleshooting & Field Operations Playbook](#7-troubleshooting--field-operations-playbook)

---

## 1. Architectural Overview & Authentication Protocols

Matrix COSEC controllers (running embedded Linux on architectures such as Rockchip RV1126 or ARM) expose **two distinct communication channels**:

```
+-------------------------------------------------------------------------+
|                        Matrix COSEC Controller                          |
|                                                                         |
|  +--------------------------------+   +------------------------------+  |
|  |       Web Interface Port       |   |       Device CGI API         |  |
|  |        (/cgi-bin/submit)       |   |        (/device.cgi/*)       |  |
|  +--------------------------------+   +------------------------------+  |
|  | - Proprietary AES-CBC protocol |   | - HTTP Digest Authentication |  |
|  | - Dynamic Key/IV negotiation   |   | - Stateless REST queries     |  |
|  | - Session cookies required     |   | - Official automation API    |  |
|  | - 5-attempt lockout (15 mins)  |   | - No GUI lockout penalty     |  |
|  +--------------------------------+   +------------------------------+  |
|                                                                         |
|  Physical Hardware Bus (RS-485 / Wiegand / Tamper / Door Sense / Relays) |
+-------------------------------------------------------------------------+
```

### Authentication Comparison

| Feature | Device CGI API (`/device.cgi/*`) | Embedded Web GUI (`/cgi-bin/submit`) |
|---|---|---|
| **Protocol** | HTTP Request-Response with **HTTP Digest Auth** (RFC 2617) | Custom POST payload with **AES-128-CBC** encrypted credentials |
| **Credentials** | `admin:<devicePassword>` (Default: `admin:1234`) | Negotiated session via dynamic Key/IV pair |
| **Session Lifetime** | Stateless per request | 30 minutes rolling cookie (`MxSessionIdCookie`) |
| **Lockout Risk** | None (digest challenges are handled per request) | High: 5 failed attempts locks GUI for 15 minutes |
| **Output Formats** | `format=xml` or `format=text` | Delimited strings (`\x01` byte separator) + JSON |

---

## 2. Hardware Penetration & Deep Inspection Methodology

To diagnose why connected readers showed "Inactive" without physical console access, we reverse-engineered the controller's internal interface by inspecting web assets and script engines directly from the live device (`192.168.1.22`).

### 2.1 Web GUI Session Negotiation (Reverse Engineered)

The controller uses an AES-128-CBC encryption scheme for administrator authentication:

1. **Parameter & System Discovery**:
   ```bash
   curl -s -X POST -d "getLoginPara?" "http://<deviceIP>/cgi-bin/submit"
   ```
   *Returns:* `doorType`, `cosecDeviceCode` (`21` = ARGO FACE, `9` = VEGA), `platformType` (`rv1126`).

2. **Key & IV Handshake**:
   ```bash
   curl -s -X POST -d "GetKeyAndIV?EncryptPassword=0" "http://<deviceIP>/cgi-bin/submit"
   ```
   *Returns:* 16-byte UTF-8 string for `KeyValue` and `IvValue`.

3. **Password Encryption & Formatting**:
   * Password is right-padded with `#` characters up to 16 bytes:
     ```js
     let pswd = "1234";
     while (pswd.length < 16) pswd += "#"; // "1234############"
     ```
   * Encrypted via `aes-128-cbc` (no PKCS padding, zero-padded buffer) and formatted as hex.
   * Delimited using ASCII byte `0x01` (`\x01`):
     ```http
     POST /cgi-bin/submit
     Body: SetLogin?userLevel=0\x01pswd=<encryptedHex>\x01desidePwdType=0
     ```

4. **Session Acquisition**:
   * On success (`validStatus: 0`), the controller returns a 32-character hexadecimal `sessionId`.
   * Requests then pass cookie: `MxSessionIdCookie=<sessionId>; MxCosecDeviceCode=MjE=; MxDeviceType=MA==; MxUserType=0`.

---

## 3. The "Exit Reader Inactive" Root Cause Investigation

### 3.1 Debunking the "Missing License" Theory
It was suspected that the external exit reader was inactive due to an unactivated license. We inspected the internal device firmware scripts (`config.js` and `home.js`):
* `#Lecense_Activation_li` is explicitly hidden on standalone door controllers (`hideElements(...)`).
* Querying hardware interface capabilities via `GetInterfaceStatusConfig?` returned:
  ```json
  {
    "interface": ["1","1","1","1","1","1","1","1","1","0","1","1","1","1","1","1","1","0","0","0","0","0"],
    "VariantType": "COSEC ARGO FACEE"
  }
  ```
  Mapping the array to `InterfaceList` showed:
  * Index 4 (`Wiegand`): `1`
  * Index 8 (`Rfid`): `1`
  * Index 16 (**`ExitReader`**): **`1` (Enabled & Licensed in Hardware)**

**Conclusion:** The controller hardware fully supports external exit readers without requiring any license key.

### 3.2 Uncovering the Configuration Mismatch
Querying the reader status via internal and external APIs revealed the root issue:

1. **Hardware Detection vs Configuration**:
   ```bash
   POST "GetConfiguredExtReaderType?"
   ```
   *Output:*
   ```json
   {
     "detectedRdrType": 9,
     "configRdrType": 0
   }
   ```
   * The physical communication port **successfully detected** the attached reader hardware (`detectedRdrType: 9`).
   * But `configRdrType` was set to **`0` (None / Inactive)**.

2. **CGI Reader Configuration**:
   ```bash
   curl -s --digest -u admin:1234 "http://<deviceIP>/device.cgi/reader-config?action=get&format=xml"
   ```
   *Output:*
   ```xml
   <COSEC_API>
     <reader1>1</reader1>
     <reader3>0</reader3>
     <reader-access-mode>6</reader-access-mode>
     <reader-entry-exit-mode>1</reader-entry-exit-mode>
   </COSEC_API>
   ```
   * `<reader3>` was set to `0` (`None`).
   * Because `<reader3>` was disabled in software, the controller firmware refused to initialize communication with the port, marking the reader as **Inactive**.

---

## 4. Hardware Event Log Decoding & Diagnostic Protocol

Matrix controllers record all hardware transactions and punches in an internal circular buffer (up to 500,000 events). We can inspect these events directly from memory using the CGI API without depending on external polling services.

### 4.1 Step 1: Query Current Event Counter
```bash
curl -s --digest -u admin:1234 "http://<deviceIP>/device.cgi/command?action=geteventcount&format=xml"
```
*Output:*
```xml
<COSEC_API>
  <Roll-Over-Count>0</Roll-Over-Count>
  <Seq-Number>45</Seq-Number>
</COSEC_API>
```

### 4.2 Step 2: Fetch Raw Event Log Records
```bash
curl -s --digest -u admin:1234 "http://<deviceIP>/device.cgi/events?action=getevent&roll-over-count=0&seq-number=30&no-of-events=15&format=xml"
```

### 4.3 Matrix Event ID Dictionary

| Event ID | Event Name | Description / Detail Fields |
|---|---|---|
| **`101`** | **User Allowed (Access Granted)** | Punch accepted. `detail-1` = User Ref ID, `detail-3` = Entry (`0`) or Exit (`1`) |
| **`151`** | User Denied – Unidentified | Biometric/card rejected (unknown credential) |
| **`152`** | User Denied – Inactive User | User disabled via `user-active=0` |
| **`154`** | User Denied – Invalid PIN | PIN verification failure |
| **`163`** | User Denied – Invalid Access Group | User not permitted during schedule/door group |
| **`164`** | User Denied – Expired Validity | Validity date (`validity-date-yyyy`) passed |
| **`201`** | Door Status Changed | Door sensor input opened/closed |
| **`207`** | Door Controller Comm Status | Sub-controller/reader communication lost or restored |
| **`305`** | Door Held Open Too Long (DOTL) | Door sensor exceeded open timeout |
| **`307`** | Door Forced Open | Door opened without authorization pulse |
| **`310`** | Tamper Alarm | Physical tamper microswitch triggered |
| **`402`** | Login to ACS / Web Access | Admin authentication attempt (`detail-2=5` for Web GUI; `detail-3=1` for success, `0` for failure) |
| **`405`** | Credential Enrollment | On-device enrollment event (`detail-2`: `8` = Card, `9` = Finger, `19` = Face) |
| **`409`** | Credential Deleted | Biometric template or card removed |
| **`457`** | Default System Configuration | System parameters restored to factory settings |
| **`458`** | Finger Reader Self-Test | Internal/external sensor calibration status |

---

## 5. CGI REST API Reference

All requests require HTTP Digest authentication: `curl --digest -u admin:1234 "http://<IP>/device.cgi/<endpoint>?<params>"`.

### 5.1 Reader Configuration (`/device.cgi/reader-config`)

#### Actions: `get`, `set`, `getdefault`

| Parameter | Type / Values | Description |
|---|---|---|
| **`reader1`** | `0` = None, `1` = EM Prox, `2` = HID Prox, `3` = MiFare, `4` = HID iCLASS | Internal Card Reader |
| **`reader2`** | `0` = None, `1` = Finger Reader, `2` = Palm Vein Reader | Internal Biometric Reader |
| **`reader3`** | `0` = None<br>`1` = EM Prox Reader (**COSEC PATH RDCE**)<br>`2` = HID Prox<br>`3` = MiFare Reader (**COSEC PATH RDCM**)<br>`4` = HID iCLASS<br>`5` = Finger Reader (**COSEC PATH RDFE**)<br>`6` = HID iCLASS-W<br>`7` = UHF Reader<br>`8` = Combo Exit Reader (Card + Fingerprint)<br>`9` = MiFare-W | **External Exit / Entry Reader Type** |
| **`reader-entry-exit-mode`** | `0` = Entry, `1` = Exit | Reader Direction |
| **`reader-access-mode`** | `0` = Card, `1` = Finger, `4` = Card+Finger, `6` = Any | Authentication Mode |
| **`tag-re-detect-delay`** | `0` to `3600` (seconds) | Delay to prevent duplicate card scans |

#### Activation Commands:
* **EM Proximity Exit Reader (COSEC PATH RDCE)**:
  ```http
  GET /device.cgi/reader-config?action=set&reader3=1&reader-entry-exit-mode=1&reader-access-mode=0&format=xml
  ```
* **Combo Fingerprint + Card Exit Reader (COSEC PATH RDFE)**:
  ```http
  GET /device.cgi/reader-config?action=set&reader3=8&reader-entry-exit-mode=1&reader-access-mode=6&format=xml
  ```
* **Fingerprint-Only Exit Reader**:
  ```http
  GET /device.cgi/reader-config?action=set&reader3=5&reader-entry-exit-mode=1&reader-access-mode=1&format=xml
  ```

---

### 5.2 Device-Level Enrollment Mode (`/device.cgi/enroll-options`)
Controls whether the hardware terminal accepts biometric enrollment commands. This is a **device-level setting**, not a user property.

```http
GET /device.cgi/enroll-options?action=set&enroll-on-device=1&format=xml
```
* **`enroll-on-device`**: `1` = Active, `0` = Inactive.
* **`enroll-mode`**: `0` = Read Only Card, `1` = Smart Card, `2` = Fingerprint, `4` = Palm.

---

### 5.3 User Profile Management (`/device.cgi/users`)
Creates or modifies user records on the controller.

```http
GET /device.cgi/users?action=set&user-id=45&ref-user-id=45&name=John+Doe&user-active=1&format=xml
```
* **`user-id`**: Alphanumeric user identifier (max 10 chars).
* **`ref-user-id`**: **Strictly numeric** user reference ID (1 to 8 digits, `1` to `99999999`). Alphanumeric characters trigger `Request Failed: Invalid Command`.
* **`user-active`**: `1` = Active, `0` = Inactive.
* **Important Firmware Constraint:** Do **NOT** pass `user-group` to modern Matrix controllers (e.g. ARGO FACE); it causes `Request Failed: Invalid Command "user-group=1"`.
* **ARGO FACE Restrict Access:** The setting is visible in the device web UI but is not part of the documented `/device.cgi/users` parameters. ARGO FACE rejects `restrict-access` with `Request Failed: Invalid Command`; do not send it through this API.

---

### 5.4 Triggering On-Device Biometric Enrollment (`/device.cgi/enrolluser`)
Commands the terminal to prompt the user on screen and capture biometrics.

```http
GET /device.cgi/enrolluser?action=enroll&user-id=45&type=2&finger-count=1&format=xml
```
* **`type`**: `0` = Card, `2` = Fingerprint, `4` = Palm, `7` = Face.
* **`finger-count`**: Number of fingers to capture (`0` = 1 finger, `1` = 2 fingers).

---

### 5.5 Door Commands (`/device.cgi/command`)

| Command | Query String | Description |
|---|---|---|
| **Normalize Door** | `action=normalizedoor` | Clears override states and returns door to normal access mode |
| **Lock Door** | `action=lockdoor` | Locks the door relay continuously |
| **Unlock Door** | `action=unlockdoor` | Opens/unlocks the door continuously |
| **Clear Alarm** | `action=clearalarm` | Clears active audible/relay alarms |
| **Get Event Count** | `action=geteventcount` | Returns current `Seq-Number` and `Roll-Over-Count` |
| **Get User Count** | `action=getusercount` | Returns total registered user count |

---

## 6. Codebase Architecture & Implemented Files

```
biomatrix/
├── app/
│   ├── Services/Attendance/
│   │   ├── MatrixDeviceService.php         # Matrix API driver & hardware communicator
│   │   └── DeviceCommandBuilder.php        # Command dispatch orchestrator & refresh logic
│   ├── Jobs/
│   │   └── ProcessMatrixCommand.php        # Async queue handler for Matrix commands
│   └── Filament/Tenant/Resources/
│       ├── DeviceResource.php              # Device management UI, actions, and dialogs
│       ├── DeviceResource/Pages/
│       │   ├── ViewDevice.php              # Header actions (reader config, live logs)
│       │   ├── EditDevice.php              # Auto-test connection on save
│       │   └── CreateDevice.php            # Auto-test connection on create
│       └── UserResource.php                # User push and device sync UI
```

### Key Implementations

1. **[`MatrixDeviceService.php`](file:///home/aflah/projects/biomatrix/app/Services/Attendance/MatrixDeviceService.php)**:
   * **`checkConnection()`**: Uses digest authentication, fetches `device-basic-config`, discovers hardware capabilities (`face`, `finger`, `card`), and extracts device model names.
   * **`getReaderConfig()`**: Reads current `reader1`, `reader3`, and access modes.
   * **`setReaderConfig()`**: Dispatches external reader activation commands (`reader3`, `reader-access-mode`, `reader-entry-exit-mode`).
   * **`getEventLogs()`**: Fetches raw hardware event sequences and parses event IDs into human-readable logs.
   * **`enableDeviceEnrollment()`**: Enables on-device enrollment mode (`enroll-options?action=set&enroll-on-device=1`).
   * **`setUser()`**: Sanitizes user names, guarantees purely numeric `ref-user-id`, maps software `is_enabled` to device `user-active`, and omits unsupported fields (`user-group`, `restrict-access`).
   * **Error Code Mapping**: Maps all Matrix response codes (`0` to `22`) from Table 129 of the official manual into descriptive error notifications.

2. **[`DeviceResource.php`](file:///home/aflah/projects/biomatrix/app/Filament/Tenant/Resources/DeviceResource.php)**:
   * **"Configure External / Exit Reader"**: Filament action with a pre-filled modal form allowing administrators to configure reader models (PATH RDCE, PATH RDFE, UHF, Combo), direction (Exit/Entry), and authentication modes.
   * **"View Device Event Logs"**: Directly fetches and renders live event records from the controller's memory in a scrollable diagnostic modal.
   * **"Enable Device Enrollment"**: One-click API action to activate terminal enrollment mode.

---

## 7. Troubleshooting & Field Operations Playbook

### Scenario A: External Reader Shows "Inactive" on the Panel
1. **Check Detection vs Configuration**:
   * If the panel shows `Detected Reader: EM Prox Reader` or `Finger Reader`, the hardware wiring is fine.
   * Check `Configured Reader`. If it says `None` or `UHF Reader`, run:
     ```bash
     curl --digest -u admin:1234 "http://<IP>/device.cgi/reader-config?action=set&reader3=1&reader-entry-exit-mode=1&reader-access-mode=0&format=xml"
     ```
     *(Use `reader3=8` for fingerprint combo readers).*

2. **Check for Centralized Server Overwrites (COSEC CENTRA / VYOM)**:
   * Look at `Basic Profile -> Server Connection`.
   * If set to **`COSEC CENTRA`**, the CENTRA server pushes door profiles periodically. If the profile inside CENTRA has "UHF Reader" or "None", CENTRA will overwrite local settings. Update the reader model inside the COSEC CENTRA admin console.

### Scenario B: Reader Does Not Appear in "Detected Reader"
1. **Verify RS-485 Polarity**:
   * Controller `RS485+ (A)` $\rightarrow$ Reader `RS485+ (A)`.
   * Controller `RS485- (B)` $\rightarrow$ Reader `RS485- (B)`.
   * Shared `GND` is mandatory. Swapped polarity allows the reader to light up but prevents data packets from reaching the controller.

2. **DIP Switch Reader Address**:
   * Matrix exit readers on RS-485 require **Address 2**:
     * **Switch 1**: OFF
     * **Switch 2**: ON
     * **Switch 4**: ON (if termination is required on long runs).

### Scenario C: Web GUI Locked Out ("Remaining Time to Unlock: 15")
* The Web GUI `/cgi-bin/submit` locks after 5 invalid attempts.
* **Workaround:** The REST API at `/device.cgi/*` uses HTTP Digest authentication and **is NOT blocked by the Web GUI lockout**. All management and configuration commands can proceed immediately via `curl` or the Biomatrix interface.
