# Employee portal

The employee panel lives at `/{tenant}/ess`, with sign-in at `/{tenant}/ess/login`. It uses native Filament pages, the amber theme and the shared PWA configuration. The existing employee calendar is shared with the management view, fits narrow screens and is capped at 48rem on desktop. Old `/{tenant}/employee` page links redirect to `/{tenant}/ess`.

## Access and passwords

Employees sign in directly using their email and stored biometric device password of at least six characters. No per-employee activation is needed. The employee-only authentication provider checks that credential, then initializes a hashed portal credential under a row lock and requires a password change. The existing `portal_enabled` database flag now tracks credential initialization, not permission. It does not change the terminal password or send a command to the terminal.

The employee signs in with their email and initial device password, then chooses a portal password with at least eight characters, uppercase, lowercase, a number and a symbol. Other portal pages and request services are blocked until this is complete. Later password changes require the current portal password.

**Reset portal password** uses the employee's current stored device password again, rotates the remember token and requires another password change. Native session password checks invalidate sessions using the old credential. Short or missing device passwords must be corrected by an Admin before initial login or reset. Disabling the employee blocks portal access. Once a portal password is chosen, the biometric password cannot be used again unless HR/Admin resets the portal password.

The employee guard is separate from management login and uses the existing tenant-scoped session and remember-cookie mechanism. Employee access does not grant access to employee administration, device settings, payroll administration or other management pages.

## Screens and requests

- **Dashboard:** today's attendance and worked time, monthly present/absent/half-day totals to date using the calendar display rules, pending attendance requests and pending leave requests, linking to their screens.
- **Calendar:** the same calendar and day details used on the employee record; all queries are restricted to the signed-in employee.
- **Attendance:** all available calculated records except holidays and weekly offs by default, optional server-side date filters, pagination limited to 10/25/50 and correction requests. Forms load existing times and match the assigned/default shift automatically. Only night shifts roll checkout into the next day.
- **Salary slips:** own approved and paid slips, including past months; read-only details and PDF download reuse existing payroll presentation. Draft and reviewed slips are hidden.
- **Leave requests:** the employee's requests and their review status. Requests cover one full or half day and show available leave for the selected date. Paid balance is reserved while a request is pending; normal policy, employment-date, holiday, off-day and closed-payroll checks still apply.

Employee corrections are pending decisions, not approved work. HR/Admin reviews them in **Attendance approvals**, including requested times and reason. Leave requests are reviewed in **Employee leave** through Approve/Reject or the existing edit flow. Employees cannot approve requests, set deduction overrides, edit attendance status or act for another employee.

The shared PWA retains the appropriate panel start URL and waits for an explicit **Update app** action before activating an update. Authenticated pages remain network-only. No offline attendance submissions are introduced.

## Validation and release

Run the employee portal tests and the existing authentication, attendance, payroll, leave and calendar regression tests. Run the full suite and frontend/PWA build before release. Browser/mobile/PWA acceptance remains separate from source and automated checks.

Apply the tenant migration `2026_11_03_060430_add_employee_portal_access_to_users` before using the portal. It adds `users.portal_enabled`, defaulting to false.

Latest ESS changes: 158 focused attendance, payroll, leave, authentication and portal checks passed (887 assertions). The frontend/PWA build, route caching and Blade view caching passed. No browser or physical-device acceptance was performed. No new migration is required for these refinements.

## Work locations and geofenced punches

HR/Admin manages **Organisation Management → Work locations**. Each location has a name, optional branch, coordinates, radius (10–10,000 metres) and active switch. Several locations may share a branch; standalone work locations are supported. Employees can use any active location in their own organisation. Deactivate a location to stop new punches; historical punches retain a snapshot of its coordinates, name, branch and radius.

The mobile-friendly pin picker uses locally bundled Leaflet 1.9.4 and OpenStreetMap tiles, with visible attribution. Tap the map or drag the dot; the circle shows the radius. **Use my location** requests browser location permission. When permission already exists, an unset map centers near the current position without saving a pin. Otherwise the fallback map centre is configured with `MAP_DEFAULT_LATITUDE` and `MAP_DEFAULT_LONGITUDE` (initially India). Saved pins open first. Coordinates can also be entered directly if map tiles are unavailable. **Open in Google Maps** opens the saved pin using a free Maps URL; no Google API key or billing account is configured. OpenStreetMap public tiles are best effort and subject to their [tile usage policy](https://operations.osmfoundation.org/policies/tiles/); `MAP_TILE_URL` supports a compatible provider change. The service worker does not intercept third-party map image requests; browser HTTP caching applies. No map prefetch/offline downloads are added.

The ESS dashboard has **Check in** and **Check out**. Each opens the live camera; **Take photo and request** captures a new JPEG together with a fresh high-accuracy location. There is no gallery/file upload option. HTTPS (or localhost) and camera/location permissions are required. Location is collected only for a punch, not continuously. The server validates the active location in the current tenant, coordinate ranges, positive accuracy and GPS reading age (maximum two minutes). The reported accuracy circle must fit within the allowed radius (`distance + accuracy <= radius`). Missing/inaccurate/outside readings fail closed with no punch.

The server supplies employee identity, PIN and current company-local request time. It validates the JPEG size, dimensions and decoding, strips metadata through re-encoding, then stores the photo encrypted using the existing private photo storage helper. A new `portal_punches` row holds immutable photo/location evidence and starts as **pending**. User-row locking and a unique request UUID prevent retry duplication. Submitting or rejecting a request creates no attendance log and earns no attendance/payroll time.

HR/Admin uses **Organisation Management → Manual punch approvals**. The dedicated page shows the photo, employee, original recorded time, location, GPS accuracy/distance and a Google Maps coordinate link. Approve and Reject have separate actions; rejection needs a reason. Reviewed history is available through the status filter. Approval locks the employee and request in a consistent order, checks the photo remains readable and the PIN unchanged, then creates exactly one immutable `attendance_logs` row using the original request time, with `device_id = null`, `portal_user_id`, request UUID and explicit portal/reviewer provenance. Same-second collisions remain pending for review. Repeated approval is idempotent; conflicting decisions fail validation.

Only approved punches use the existing after-commit refresh queue, shift pairing, night-shift work-date matching, missing-checkout review and payroll freshness protection. The `portal` calculation source means the result includes approved portal punches; historical correction approval remains separate. Employees see the status of their latest photo request on the dashboard.

Photos are delivered through an authenticated, tenant-scoped route to an active HR/Admin or their owning employee after password setup. The response is private/no-store. The review component fetches the JPEG into a temporary object URL rather than using a network image `src`, so even older deployed service workers cannot put it into their generic image cache. Object URLs and camera tracks are released when the component closes. No punch photo is uploaded to a map provider or public disk.

Browser coordinates can be falsified by a determined user. This is a distance/accuracy check, not device attestation or proof of physical presence. Existing employee authentication and tenant boundaries remain required.

Apply tenant migrations `2026_11_03_105455_create_work_locations_and_employee_punches` and `2026_11_03_112930_create_portal_punches` before serving these changes. It preserves existing attendance records and database immutability triggers (including SQLite table-rebuild handling). Rollback refuses to remove portal punch evidence once recorded. The local `sec` tenant was backed up and migrated; existing employee, punch and salary slip counts and paid payroll hash stayed unchanged. Production deployment must migrate every tenant, preserve private storage and keys, and restart the existing workers.

Geofence validation: 222 broader attendance, gateway, payroll, authentication and portal checks passed (1,558 assertions); 49 final focused portal/authentication/photo checks passed (355 assertions). Frontend/PWA build, Blade/route compilation and Alpine expression parsing passed. Browser GPS, interactive map and physical-device acceptance were not performed.

Master company login uses a native Filament action to issue the signed, single-use grant only after a click and perform a full-page redirect. The old POST-form action produced 419 responses on production after the framework upgrade; CSRF and grant validation remain enforced. The generic tenant-root route is constrained to one path segment so it cannot swallow private photo routes.
