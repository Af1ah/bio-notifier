# Employee portal

The employee panel lives at `/{tenant}/employee`, with sign-in at `/{tenant}/employee/login`. It uses native Filament pages, the amber theme and the shared PWA configuration. The existing employee calendar is shared with the management view and fits narrow screens.

## Access and passwords

HR/Admin opens an employee record and chooses **Enable employee portal**. The employee needs an email address and a stored device password of at least six characters. Enabling access copies that device password into a hashed portal credential and requires a password change. It does not change the terminal password or send a command to the terminal.

The employee signs in with their email and initial device password, then chooses a portal password with at least twelve characters, uppercase, lowercase, a number and a symbol. Other portal pages and request services are blocked until this is complete. Later password changes require the current portal password.

**Reset portal password** uses the employee's current stored device password again, rotates the remember token and requires another password change. Native session password checks invalidate sessions using the old credential. Short or missing device passwords must be corrected by an Admin before access can be enabled or reset. Portal access is off by default for existing employees; disabling the employee also blocks portal access.

The employee guard is separate from management login and uses the existing tenant-scoped session and remember-cookie mechanism. Employee access does not grant access to employee administration, device settings, payroll or other management pages.

## Screens and requests

- **Dashboard:** today's attendance and worked time, pending attendance requests and pending leave requests, linking to their screens.
- **Calendar:** the same calendar and day details used on the employee record; all queries are restricted to the signed-in employee.
- **Attendance:** server-side date filters, pagination limited to 10/25/50 and correction requests. Forms load existing times and match the assigned/default shift automatically. Only night shifts roll checkout into the next day.
- **Leave requests:** the employee's requests and their review status. Requests cover one full or half day and show available leave for the selected date. Paid balance is reserved while a request is pending; normal policy, employment-date, holiday, off-day and closed-payroll checks still apply.

Employee corrections are pending decisions, not approved work. HR/Admin reviews them in **Attendance approvals**, including requested times and reason. Leave requests are reviewed in **Employee leave** through Approve/Reject or the existing edit flow. Employees cannot approve requests, set deduction overrides, edit attendance status or act for another employee.

The shared PWA retains the appropriate panel start URL and waits for an explicit **Update app** action before activating an update. Authenticated pages remain network-only. No offline attendance submissions are introduced.

## Validation and release

Run the employee portal tests and the existing authentication, attendance, payroll, leave and calendar regression tests. Run the full suite and frontend/PWA build before release. Browser/mobile/PWA acceptance remains separate from source and automated checks.

Apply the tenant migration `2026_11_03_060430_add_employee_portal_access_to_users` before enabling access. It adds `users.portal_enabled`, defaulting to false. This work is local; pushing and production deployment require a separate request.

Validation completed locally: the 381-test full suite passed (2,499 assertions); 147 focused attendance, payroll, leave, authentication and portal checks passed after refinements. The frontend/PWA build, PHP and inline JavaScript syntax checks, route caching and Blade view caching passed. No browser or physical-device acceptance was performed. The local `sec` tenant migration was applied after a backup, preserving existing record counts.
