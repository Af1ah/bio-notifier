# Organisation scenario review

14 October 2026. The original scenario findings below are retained for traceability. All six business-feature gaps have since been implemented locally; see the implementation follow-up and [current rules](beta-02-business-features.md). Broader UI refinement and 0.2 deployment remain separate.

## Verification scope

Three independent tenant-schema simulations, each with three employees and three payroll months: 27 salary slips. Fixtures run in disposable SQLite memory; current organisation records and processed payslips are untouched. The reusable test is `tests/Feature/OrganisationScenarioTest.php`. No browser, device connection, queue execution, concurrent PostgreSQL writers, or live organisation setup was verified by these simulations.

Secumax and Nova use January–March 2026; Outreach uses April–June 2026. Test salaries are INR 30,000 monthly or INR 100/hour, with Nova approved overtime at INR 200/hour. Nine employees receive raw IN/OUT fixtures for normal scheduled work, absence for requested leave, and service-generated attendance/payroll. An additional seasonal test covers three employees in January, February and March.

## Results by company

| Company | Verified behavior | Assessment |
| --- | --- | --- |
| Secumax | Monday–Friday; weekends excluded from working days; no paid entitlement; one unpaid day each month; deduction uses actual 31/28/31 calendar-day divisor; no OT earned or paid in these fixtures. | Core requested configuration works. An explicit company/shift switch to forbid OT candidates remains missing. |
| Nova | All seven weekdays scheduled; holiday excluded from ordinary absence; default two paid days monthly; assigned managers three; ten March leave days after three monthly grants; unpaid excess; hourly employee; night shift with one approved OT hour attributed to starting work date; 31 March expiry on issued Jan–March credits. | Core rules work. Different hourly OT rates for different shifts of the same employee remain unsupported. |
| Outreach Global | Monday–Saturday; one paid day monthly; second day deducted; calendar-day divisor; regular carry is supported by existing regression coverage. | Partial fit. Two recurring expiry cutoffs and month-end expiry of earned holiday/off-day leave are missing. Confirmed reset dates: 1 June and 31 December. These exact rules are not implemented or verified. |

### Secumax sample

For INR 30,000 basic salary and one unpaid day: January/March deduction INR 967.74 and net INR 29,032.26; February deduction INR 1,071.43 and net INR 28,928.57. This requires the category option **Basic ÷ actual month days**. The alternative salary-day-rate option uses scheduled working days and would give a different result.

The no-OT fixture proves zero overtime for ordinary eight-hour work. Setting monthly OT rate to zero prevents overtime payment, but does not disable calculation/approval candidates. Hourly payroll falls back to the base hourly rate when its OT rate is zero; zero must not be treated as a universal “disable overtime” setting.

### Nova sample

Three monthly grants give staff/hourly employees six paid days and managers nine. A ten-day March request uses six paid/four unpaid for staff and nine paid/one unpaid for managers, as confirmed by the user. Each leave date is currently a separate record. A ten-day continuous request is represented by ten records in the scenario.

Staff March leave deduction is INR 3,870.96 and manager deduction INR 967.74. Four separate daily deductions round individually; rounding the four-day amount once would yield INR 3,870.97. The current system consistently rounds individual leave records to minor units. Reports should expose that convention.

Hourly work uses approved regular minutes and adds paid leave based on the assigned shift's full-day minutes. Approved OT is paid separately without counting the same OT hour at both regular and OT rates. Branch assignment can organise hourly employees, but pay basis/rates remain employee payroll-profile configuration; branch-level inherited salary defaults are not provided.

The night fixture starts at 22:00 and ends the next day at 07:00 on its OT test day. Its 60 approved OT minutes belong to the starting work date. The seasonal fixture applies an 08:00–16:00 direct assignment in February; the employee inherits the normal 09:00–17:00 default again in March when that assignment ends.

Existing regressions verify manual punch correction/approval, preserved raw punches, and correction of a selected occurrence without rewriting other approved shift evidence. These are additional regression checks rather than manual corrections in the 27 monthly fixtures.

### Outreach limitations

The monthly fixture uses a provisional 30 June cutoff solely to exercise a supported single-cutoff configuration. It does not represent the confirmed company calendar: regular unused leave resets on 1 June and 31 December; holiday/off-day earned leave expires at the end of its work month. Tests consume one paid and one unpaid day each month; accumulated unused carry is tested separately by the existing leave regressions.

Holiday coverage supports branches, departments and task groups. Role/religion coverage can be maintained through explicit task groups, but there is no direct religion selector or automatic religion-based membership. Never infer religion from employee names. The existing holiday and compensatory regressions verify eligible holiday/off-day work and subsequent credit use, not Outreach's missing month-end expiry requirement.

Compensatory expiry is currently a fixed number of days after availability. It cannot reliably mean “last day of this work month”: different work dates need different durations. Regular credits support one recurring annual month/day cutoff or a relative expiry duration; they cannot configure two recurring cutoffs in one policy. Creating successive revisions is a manual workaround, not equivalent automation.

## Pending features, in priority order

1. **Recurring cutoff schedule:** support multiple reviewed dates per year, choose the next applicable cutoff for each grant, preserve issued-credit expiry, and explain the result in a preview. Outreach uses 1 June and 31 December. Define whether these are reset-at-start dates or last-usable dates, and order the June reset before the new June grant; do not accidentally erase the new grant.
2. **Compensatory expiry at month-end:** explicit expiry mode alongside duration; define availability for the month's last work date so a next-day credit is not already expired. Keep issuance and expiry in the ledger.
3. **Overtime controls and rates:** explicit enable/disable, approved effective-dated rates by employee/shift and day type, and a rate breakdown on payslips. A single profile OT rate is currently applied across the month's approved OT minutes.
4. **Multi-day leave entry:** enter a date range once; preview paid, carried, unpaid, holidays/off-days, and expiry; save daily allocations atomically. This reduces Nova's ten-record entry without losing daily evidence.
5. **Holiday eligibility management:** task-group setup is available; clearer eligibility groups and bulk membership would reduce religion/role administration. Direct attributes would need an explicit product/data decision.
6. **Pay-profile templates:** apply reviewed branch/role defaults in bulk while preserving employee overrides and effective dates. Existing CSV import supports bulk profiles but does not create inherited branch pay rules.

## UX improvements that preserve reporting quality

- A guided company setup: working weekdays → shifts → holiday coverage → salary basis/divisor → entitlement/carry/expiry → employee exceptions. Show a one-page readable summary before activation.
- Show concrete examples next to deduction and expiry choices: “INR 30,000 ÷ 31 = INR 967.74 per unpaid day”; “March credit expires 31 March.” Warn when a selected rule cannot represent the requested company policy.
- Show inherited rules and their source on each employee: company, branch, task group or individual assignment. Provide an end date for seasonal overrides and preview the rule after that date.
- Batch review only current, complete attendance; put missing punches, stale evidence, holiday-work awards and OT candidates in one exception queue. Preserve individual audit decisions and block unresolved payroll.
- Preview payroll before generating: working days, approved hours, paid/unpaid leave, expiry, OT rate breakdown and unresolved items. Use the same calculation snapshot for screen, CSV and PDF.
- Offer a safe scenario sandbox with generated employees/punches and expected totals. Keep sample data separate from active employees and production payroll.

## Confirmed Outreach requirements and remaining boundary decisions

The monthly one-day entitlement and holiday/off-day earned credit need distinct expiry rules. They can use the existing policy/credit ledger architecture; no second application or parallel approval flow is needed. Separate credit sources must remain visible even if one leave-request interface covers both.

- Regular monthly entitlement: carry unused credit until the reset on 1 June or 31 December. Old credit must not cover a later date after its reset. The new June entitlement must survive the June reset.
- Holiday/off-day work: reward only verified approved work; allow the earned credit during that work month; expire it at that month's end regardless of the regular entitlement reset calendar.
- Excess leave: apply the agreed monthly payroll deduction rule, or no paid coverage for hourly/daily employees. Do not charge the same date as both unpaid leave and absence.

A reset date and a last usable date are different. Current `expires_on` is inclusive. Selecting 1 June as an inclusive expiry permits use on 1 June and clears availability on 2 June. A reset at the start of 1 June needs old credits valid only through 31 May. Similarly, an inclusive 31 December expiry clears availability on 1 January, while a reset at the start of 31 December needs old credits valid only through 30 December. The user subsequently confirmed start-of-day resets for both dates: old credits are valid through 31 May and 30 December respectively. The implemented UI and tests now use that interpretation.

Work on the month's final day has no later leave date within that month. Same-day availability also does not permit a full worked day and full leave on the same date. The stated strict month-end rule therefore leaves no later opportunity to use a full-day reward earned on that final day. Display this consequence rather than extending expiry silently. Late HR approval must keep expiry tied to the work month; it must not create a fresh month of usable credit.

Reservations and future requests must be checked against the leave date, not just the date of requesting. Expiry/reset removes unused eligible credit with an audit entry; it must preserve original grants, consumed credit, decisions and finalized payroll. Already-issued credit should not be silently rewritten when a new rule is added.

### Required verification before claiming a complete fit

- Outreach: 31 May/1 June/2 June and 30 December/31 December/1 January; reset/grant ordering; future bookings spanning each boundary; holiday work early and late in 28/29/30/31-day months; late approval; cancellation after expiry; no duplicate award.
- Nova: approved overtime across midnight and across month-end with distinct shift rates; manager assignment changes; three-month accrual and ten-day requests with insufficient credit; March/April expiry; seasonal override completion.
- Secumax: extra punches when OT is explicitly disabled; February in leap/non-leap years; half-day leave; multiple unpaid days and the rounding convention; weekly-off exclusion.
- All companies: complete screen/CSV/PDF parity, current PostgreSQL tenant isolation, concurrent leave reservations, and queue retries. Existing service tests are useful evidence but do not prove these unexecuted production boundaries.

## Evidence and boundaries

The final scenario run passed 4 tests and 164 assertions, including the seasonal assignment check. Existing attendance editing, attendance workflow, leave carry-forward and attendance-calculation regression suites passed 113 tests and 520 assertions.

An initial broader run hit temporary Blade-cache configuration errors and tenant-database environment errors in the disposable copy. The supported regression suites passed after explicitly setting the disposable view cache path. TenantBoundaryRegressionTest was excluded from the successful run; this report does not claim a new PostgreSQL multi-tenant isolation verification.

## Implementation follow-up

The six business-feature gaps above are now implemented in the local 0.2 preparation: recurring resets, work-month compensatory expiry, overtime eligibility/rate snapshots, atomic leave ranges, dated holiday eligibility and payroll templates. The earlier gap assessment remains historical context. Outreach uses confirmed start-of-day resets on 1 June and 31 December. The scenario fixture now uses these actual resets rather than the provisional June cutoff. See [Implemented business rules](beta-02-business-features.md) for current behavior and rollout boundaries. Broader UI improvements remain the next phase; version 0.1 online is unchanged.
