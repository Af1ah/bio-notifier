# Bio-Notifier Development Agenda & Quick Reference

## 1. Project Overview
**Bio-Notifier** is a multi-tenant Laravel 12 application using Filament v3 for administration. It manages biometric attendance devices, synchronizes users/fingerprints/faces, and triggers notifications (e.g., WAHA WhatsApp API) based on real-time attendance logs.

## 2. Tech Stack & Versions
* **PHP:** 8.5.x
* **Laravel:** 12.64.0
* **Admin Panel:** Filament v3 (Livewire 3.x)
* **Database:** PostgreSQL (Multi-tenant architecture)
* **Biometric Middleware:** eBioServer New (SOAP API 1.1)

## 3. Core Architecture & Standards
* **Multi-Tenancy:** The application is tenant-aware. Always initialize tenancy in background queue jobs using `tenancy()->initialize($this->organisation);` before querying any tenant-specific models.
* **Legacy vs. New API:** The legacy ADMS direct integration (`DeviceCommandBuilder`) is **DEPRECATED**. All device communication MUST use the new eBioServer SOAP architecture via `app/Services/EbioSoapService.php`.
* **SOAP Communication Rules:**
  * **Method:** Always use `Http::send('POST', $url, ['body' => $xml])`. **NEVER** use `Http::post($url, $string)` as it alters the payload to `application/x-www-form-urlencoded`, which the eBioServer rejects.
  * **Headers:** Always include `'Content-Type' => 'text/xml; charset=utf-8'` and the appropriate `'SOAPAction'`.
  * **Identifiers:** The eBioServer `EmployeeCode` maps exactly to the `pin` attribute on the `User` model (`$user->pin`).
  * **URL Construction:** Target `$url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';`

## 4. Key Variables & Credentials
When interacting with the eBioServer, always rely on these `Organisation` model properties:
* `ebio_url`: The base URL for the server (e.g., `http://20.198.121.193:40010/iclock`).
* `ebio_soap_username`: Authentication username for the SOAP payload.
* `ebio_soap_password`: Authentication password for the SOAP payload.

## 5. Where to Find Things
* **Device Communication Logic:** `app/Services/EbioSoapService.php` (SOAP XML generation & HTTP requests).
* **Background Jobs:** `app/Jobs/` (e.g., `EbioDeviceCommandJob`, `EnrollEbioBiometricJob`). Ensure tenant initialization is at the top of the `handle()` method.
* **Filament UI/Actions:** `app/Filament/Tenant/Resources/`
  * `DeviceResource`: Device management and direct actions (reboot, clear logs).
  * `UserResource`: User sync, biometric enrollment (finger/face), door blocking.
  * `DeviceCommandResource`: Command queuing and history tracking.
* **API Documentation:** The official SOAP API documentation is located at `reference/eBioServerNew-Web_API-Manual.txt`. Always consult this before writing new SOAP actions.
