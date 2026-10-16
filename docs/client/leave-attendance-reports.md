# How leave affects attendance, reports and payslips

Attendance answers **“What work was recorded?”** Leave balances answer **“What leave is available or used?”** Payroll combines approved work, leave coverage and company payment rules.

## Effect of each workflow

| Change | Attendance Days | Attendance reports | Balances and payroll |
| --- | --- | --- | --- |
| Change monthly/annual entitlement or carry/expiry settings | Does not change punches, minutes or attendance status. | Does not add work hours or Present days. | Future grants follow the new policy revision. Issued credit keeps its original rules/expiry. |
| Reserve leave as Pending | Does not mark the employee Present. | Work status remains based on attendance. | Reserves credit; pending leave blocks the affected payroll calculation. |
| Approve paid leave | Does not automatically rewrite the attendance status or device punches. | A row with no work can still show Absent/A even though the leave is paid. | Consumes covered credit. Payroll accounts for approved leave separately and avoids charging the same covered leave as attendance absence. |
| Approve permitted excess leave | Does not add worked minutes. | Remains a work-status report. | The uncovered portion follows the deduction/pay rules for that employee and category. |
| Record actual holiday/off-day work | Shift rules calculate actual work; the row can become Present or Half day while Day type remains Holiday or Rostered off. | Includes recorded work minutes and the calculated work status. | Approved work may qualify for an optional leave credit/payment. |
| Award paid leave credit | Adds Work reward and Reward status to the work row. It does not change approved minutes. | Does not add extra hours, Present days or a second worked day. | Adds dated compensatory credit. It is paid leave coverage when later requested and approved; unused credit does not itself add salary. |
| Award payment | Adds the approved work reward to that attendance row. | Does not add extra worked hours or overtime minutes. | Adds the approved monetary reward to the work month's payslip when calculated. |
| Correct work after awarding a reward | Corrected times take effect after attendance approval. The award may show Needs review. | Calculated work results follow the correction/recalculation. | Changed reward evidence blocks further credit use/related payroll until HR reconciles it. |
| Cancel leave or reverse a reward | Does not create or remove device punches. | Does not turn leave credit into work hours. | Restores leave with its original expiry or reverses the award. Affected draft payroll needs recalculation; paid amounts are preserved. |

## Read Attendance Days

Open **Organisation Management → Attendance**.

- **Shifts** identifies the shift occurrences included in the date's calculation.
- **Day type** identifies a working date, holiday, rostered off date or missing assignment. This is separate from the employee's worked status.
- **Source** distinguishes device, manual, or mixed attendance evidence.
- **Status** is the calculated/approved attendance result, such as Present, Half day, Absent, Holiday or Off.
- **Calculated minutes** are the result derived from times and shift rules.
- **Approved minutes** are the worked minutes accepted for payroll and reward eligibility. Manual/assumed work can have calculated minutes while approval remains outstanding.
- **OT minutes** are the row's calculated overtime candidate; approved overtime is a separate decision.
- **Work reward** shows the awarded leave days and/or amount. **Reward status** shows whether the award is approved, reversed or needs review. Use **Reward details** for availability, expiry and reasons.

Awarding leave credit is a reward decision, not an attendance correction. Use Correct for wrong times/status; use Award leave/payment for eligible work rewards.

## Read attendance reports

Daily, weekly and monthly attendance reports use the attendance results. In the report data, the work-status codes mean:

| Code | Meaning |
| --- | --- |
| P | Present |
| H | Half day |
| A | Absent in the attendance result |
| O | Holiday or off |
| ? | Pending attendance result |
| N | Not calculated or no assigned shift |
| ! | Other exception status |

Report work minutes use approved minutes when available; otherwise they can show calculated candidate minutes. Therefore displayed report hours are not, by themselves, proof that manual/assumed attendance has been approved for payroll. Overtime totals use approved overtime.

Attendance report CSV/PDF exports remain attendance exports. The CSV includes daily time displays and work/approved-overtime totals plus Present/Half-day/Absent counts. It does not export leave balances, expiry dates, paid-leave coverage or compensatory award amounts as separate fields.

An **Absent/A** count in an attendance report is not automatically an unpaid deduction. Check Employee Leave and the payslip for approved paid coverage. The current attendance report does not overlay approved leave with a separate L or Paid Leave code.

Use **Employee Leave** to review requests and dates, **Leave Balances → History** to review credits and expiry, and **Salary slips** to review financial treatment. A dedicated leave-balance export/report is not included in the current attendance export.

## Read payslips

- Monthly employees retain salary for covered paid leave. The uncovered portion follows the leave category's deduction rule.
- Daily employees receive the configured daily pay for the allocated paid-leave portion.
- Hourly employees receive allocated paid-leave earnings using the effective shift's daily-hours rule. An effective daily-hours rule is required.
- Paid leave days, unpaid leave days and leave deduction are shown in the slip summary.
- Approved holiday/off-day monetary rewards are included in **Other earnings**, with an “included” reward breakdown in the slip and PDF. This breakdown is part of the total, not an additional amount to add again.
- For daily/hourly employees, paid-leave earnings are also identified within Other earnings.
- Unused leave credits do not automatically become a cash payment. The work award's selected reward controls whether there is a monetary reward.

The payslip list defaults to the current month and unpaid statuses. Select Paid or All statuses to see paid slips. CSV and all-employee PDF require one month; organizational and employee selections narrow the export. Attendance CSV and payslip CSV are different reports.

## Worked examples

### Monthly leave carried forward

A company grants 2 days monthly. An employee uses 1 paid day in August. If the carry limit and expiry permit it, the remaining 1 day is eligible in September, alongside September's grant. It keeps the expiry captured when August's credit was issued. With the starter relative expiry, August's credit expires on 30 November. Changing September's policy does not extend that old expiry.

This changes the leave balance. It does not add hours or Present days to an attendance report.

### Work on a holiday, take leave later

An employee works 480 approved net minutes on an eligible holiday, 3 August. HR awards 1 day of compensatory leave.

The work row keeps its actual worked status/minutes and Day type Holiday. Reward details show the 1-day credit. With following-date availability and 90-day expiry, it is available from 4 August through 2 November.

The employee takes approved compensatory leave on the working date 5 August. That date can remain Absent/A in the work report, while Employee Leave records paid coverage and payroll does not treat the covered day as unpaid absence. The credit is consumed once. No extra worked hours are added for either awarding or taking the credit.

### Choose payment instead

For the same eligible work, HR chooses Payment under a policy with a 500.00 full-day reward. The approved 500.00 appears in the work month's Other earnings when its draft payroll is calculated. There is no leave credit from this payment-only choice. Both rewards require the explicit Both policy. Additional approved overtime also requires its own permitted combination.

### Attendance corrected after a reward

HR discovers that a rewarded full day's work should have been only a half day. Correct the attendance and complete its approval. The old reward shows Needs review. HR must reconcile any leave already using that credit, reverse the old award where permitted, then review the corrected reward. Historical paid slips are not silently rewritten.

## When payroll is blocked

| Message or situation | What HR should do |
| --- | --- |
| Pending attendance or overtime decision | Review the actual shift evidence and complete/reject the applicable decisions before awarding a reward. |
| Pending leave request | Approve, reject or cancel the request before calculating its payroll month. |
| Insufficient unexpired balance | Reduce the request, use the appropriate unpaid category, or review a genuine missing opening balance. Do not extend expiry to bypass the company rule. |
| Reward needs review / changed evidence | Review the affected attendance, award and any leave using that credit. |
| Currency mismatch or zero reward payment | Correct the company payment policy and employee payroll currency/amount before awarding payment. |
| Closed payroll period | Follow HR/payroll's reviewed historical adjustment process. |
| Draft marked for recalculation | Resolve the underlying changes, complete any required manual-period review, then regenerate the draft. |

For configuration details, see [Configure leave policies](leave-settings.md). For daily operations, see [Record leave and work rewards](leave-workflows.md).

### Financial-year end and carried-credit expiry

A 1 April 2026–31 March 2027 leave period defines when regular grants can be issued. A separate annual cutoff can expire the eligible remaining credit on 31 March, or permit use until a later cutoff such as 30 June. Neither setting adds work minutes or changes attendance status. Existing payslip amounts and already issued credit expiry are retained when HR saves a new policy revision.

### Payslip worked hours and per-day salary

The payslip details and PDF show **Working days** (previously Scheduled days), **Total worked hours**, and **Per day salary**. Working days counts the scheduled working dates used by the saved payroll calculation; it is not the employee's Present-day count. Total worked hours uses saved approved work minutes, includes overtime and excludes paid-leave hours. It is displayed as hours/minutes.

For monthly pay, per-day salary is the saved base-salary rate divided by actual calendar days, matching the monthly attendance-deduction basis. For daily pay, it is the saved configured daily base rate. Hourly pay has no fixed daily salary. Historical slips without the saved value show Not recorded; the app does not reconstruct it from current attendance or salary profiles. Displaying these fields does not change payslip amounts or saved payroll evidence.

## Overtime rate evidence

New overtime approvals capture the applicable employee/shift rate and currency. Salary slip details, PDFs and payroll CSVs include the same rate breakdown from the saved calculation. Shift settings now have an explicit overtime enable switch; a zero hourly rate is a payment setting. See [0.2 business rules](../beta-02-business-features.md).
