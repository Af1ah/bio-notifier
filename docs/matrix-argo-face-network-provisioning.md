# Matrix ARGO FACE network provisioning

## Purpose

This note records the network-discovery and IP-provisioning boundary for Matrix COSEC ARGO FACE devices. It is intended for a future server-side integration, not for browser or GUI automation.

## Device observed on 2026-09-25

| Field | Observed value |
| --- | --- |
| Product family | Matrix COSEC ARGO FACE |
| Firmware platform reported by the device | `rv1126` |
| Device code reported by the device | `21` (ARGO FACE) |
| MAC address | `00:1b:09:17:83:29` |
| Current Ethernet address | `192.168.50.10/24` |
| Requested address | `192.168.50.5/24` |

The address has **not** been changed by this investigation. The device remains at `192.168.50.10`.

## Confirmed interfaces

### Standard device CGI API

The repository's `MatrixDeviceService` correctly uses HTTP Digest authentication with `/device.cgi/*` for device operations such as basic configuration, users, readers, events, and commands. The bundled Matrix COSEC Device API manual describes this API, but explicitly directs IP-assignment questions to device-specific documentation.

On this ARGO FACE firmware, these unauthorised-by-documentation candidates all returned `Request Failed: Incomplete Command` when queried with `action=get`:

- `ethernet-config`
- `ethernet-settings`
- `lan-config`
- `lan-settings`
- `network-config`
- `network-settings`

Do not add a guessed network setter to `MatrixDeviceService`.

### ARGO management service

The built-in management UI uses a different endpoint:

```text
POST /cgi-bin/submit
```

Requests use ASCII `0x01` as a field separator. The observed login sequence is:

1. `GetKeyAndIV?EncryptPassword=0`
2. Encrypt the 16-byte password (`password` followed by `#` padding) using the returned 16-byte key and IV with AES-128-CBC and no extra padding.
3. `SetLogin?userLevel=0<0x01>pswd=<lowercase-hex-ciphertext><0x01>desidePwdType=0`

The live device returned `response-code=0` and `validStatus=0`, so the programmatic login was accepted. It also recognises `GetEthernetSetting?`, but responds with `relogin` when called from a separate short-lived HTTP client request. This firmware did not return a session ID in the JSON login response or an HTTP `Set-Cookie` header. The protected LAN setter and its exact parameters are therefore not yet verified.

Do not send `SetEthernetSetting`, `SetLanSetting`, or similarly guessed commands. A failed or partial network write can make the device unreachable.

## Safe implementation plan

1. Obtain a Matrix-supported ARGO FACE LAN management API specification, or capture the authenticated LAN request from an approved Matrix client and validate it against a non-production device.
2. Implement a dedicated `MatrixNetworkProvisioningService`; do not mix this stateful protocol into the existing Digest CGI service.
3. Keep credentials in tenant-scoped encrypted configuration. Never store them in device notes, logs, command payload history, or this document.
4. Before a write, read and retain the current LAN configuration, MAC address, and ARGO device fingerprint.
5. Send one idempotent setter with the desired IP, mask, gateway, and DNS values. Preserve existing gateway and DNS unless the caller explicitly supplies replacements.
6. Immediately poll the new address and verify both the expected MAC address and the ARGO fingerprint. Only then update the application's persisted device IP.
7. If verification fails, probe the old address and report the physical-device result; do not claim that an IP change succeeded merely because the request was accepted.

The management protocol must be serialized per device: changing its IP while another process is reading events or provisioning users can create false offline states. Place a short device-scoped lock around the read, write, and verification sequence.

## MAC-based discovery

IP addresses are mutable. Use a MAC address as the stable local-network discovery key, but validate it with a device fingerprint before trusting it.

For an isolated ARGO subnet, the host needs an address in that subnet first. A temporary secondary address is sufficient, for example:

```bash
sudo ip addr add 192.168.50.1/24 dev enp7s0
```

Then perform a layer-2 inventory (administrator permission is normally required):

```bash
sudo arp-scan --interface=enp7s0 --localnet
```

For each response, match the configured exact MAC address first. An OUI-only match such as `00:1b:09:*` is only a candidate filter, not proof of model or ownership. Confirm candidates by making a read-only fingerprint request such as `getLoginPara?` to `/cgi-bin/submit` and checking for device code `21`; where credentials are available, also use the standard Digest `device-basic-config` request.

The application should persist `mac_address` as a normalized, unique per-tenant value and treat the discovered IP as current network state. A scan result must not automatically reassign a device across tenants.

## Verification checklist

- The host has a route and source address on the target subnet.
- The target IP resolves to the expected MAC address.
- The MAC candidate reports ARGO FACE device code `21`.
- The network setter and response semantics have been verified on a controlled device.
- The device is reachable at the new address and retains the same MAC and fingerprint.
- The database IP is updated only after the live verification succeeds.
