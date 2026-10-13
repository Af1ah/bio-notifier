# Employee CSV import

Tenant administrators can import up to 1,000 employee records per file (5 MB maximum). Use **Users → Import Users**, download an employee or payroll template, upload, review the validation summary and confirm. UTF-8 with optional BOM is supported; comma, semicolon and tab delimiters are detected. No database records are changed during preview.

Create-only is the default. Choose create/update by PIN explicitly to modify existing employees. PIN is a string, so leading zeros are retained in the CSV; spreadsheet editors must format PINs as text. `pin` and `name` are required. Header names are case-insensitive. Duplicate PINs/emails, unknown or duplicate headers, malformed rows and invalid values reject the entire file. Fix the indicated record and retry. Validation runs again inside the submission transaction; a database conflict rolls back every row.

Blank cells preserve existing values, including existing assignments and payroll settings. They do not clear fields. `0` explicitly clears a monetary amount. Boolean values are `0`, `1`, `true`, `false`, `yes`, `no`. Dates use `YYYY-MM-DD`. Phone numbers contain digits only, including country code. Task-group IDs are separated by `|`; a nonblank list replaces existing membership. Branch, department and task-group IDs must exist in the current organisation; department must belong to the selected branch.

## Payroll fields

Confirmation is bound to the exact file content and import mode reviewed. Changing either requires a new preview. Database records are checked again at submission, so a previously valid preview cannot bypass new conflicts.

| Column | Meaning |
| --- | --- |
| payroll_enabled | Include in payroll; defaults to false for a new profile |
| pay_basis | monthly, daily or hourly |
| basic_salary | Monthly salary, daily rate or hourly rate according to pay_basis |
| currency | INR |
| effective_from | Salary effective date |
| housing_allowance, transport_allowance, other_allowance | Monthly allowances |
| fixed_deduction | Fixed monthly deduction |
| overtime_hourly_rate | Approved overtime rate |

Amounts are decimal INR, not paise, e.g. `8000.00`. No currency symbols, thousands separators, negative values, scientific notation or more than two decimal places. Maximum amount per field is `99999999.99`. Conversion uses integer arithmetic. A new payroll profile requires pay_basis, basic_salary and effective_from. Daily/hourly profiles cannot carry nonzero monthly allowances or fixed monthly deductions.

Changed payroll settings mark existing salary slips as needing recalculation without changing reviewed/finalized amounts. Changed organisation assignments mark existing attendance as stale too. Recalculate attendance after assignment changes, then review/recalculate draft salary slips; finalized payroll needs an adjustment.

## Safeguards and retention

Imports require an authenticated administrator in an initialized tenant. Only explicitly supported fields can be saved. CSV cannot set application privilege, login password, system-account flags, biometric templates or tenant identifiers. The default login account is protected; application-admin login email changes require the user editor. Device privilege is separate and accepts only 0 or 14.

Database queries use bound values; text is never executed as SQL. Spreadsheet formula prefixes (`=`, `+`, `-`, `@`), binary/control characters, invalid UTF-8 and overlong cells are rejected. Files are private and tenant-scoped; submitted paths are checked against the actual storage directory. This provides specific tested protections, not a guarantee against every possible injection vulnerability elsewhere in the application.

Successful action imports delete the source CSV. Cancelled/invalid uploads expire after 24 hours and are pruned hourly by the Laravel scheduler. Ensure the existing `schedule:run` cron is running. Manual cleanup: `php artisan users:prune-imports`. An expired preview must be uploaded again.
