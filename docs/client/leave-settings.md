# Configure your company's leave policies

A leave type is a category such as Casual Leave, Monthly Paid Leave or Compensatory Leave. A policy tells the app how that category is earned, carried forward and used. Your company can have several policies and assign different policies to different employees. Changes affect only your company.

## First-time setup

1. Sign in as your company's administrator or HR administrator.
2. Open **Payroll → Leave settings → Leave calendar settings**. Choose the company's leave timezone and expiry-warning period.
3. Review the employment start/end dates in employee payroll profiles. These dates control join/exit proration. Without employment dates, eligibility begins at the policy or assignment start; importing an employee is not treated as a verified employment start date.
4. Either choose **Add starter policies**, or create a leave type under **Payroll → Leave types** and then create its policy in **Leave settings**.
5. Choose a policy start date. Existing companies should use a date after closed payroll and existing pending/approved leave commitments. The app blocks conflicting changes rather than rewriting those records.
6. Set the entitlement, carry-forward and work-reward rules described below. For a policy that applies to everyone without a specific assignment, enable **Default for employees without an individual assignment**.
7. For different employee rules, create another policy for the same leave type with the default switch off. Use **Assign employees** to select employees after applying branch, department and task-group filters.
8. Open **Leave Balances** and verify the results. If moving from another system, use **Add reviewed balance** only for a verified balance that should be added to the new entitlement. Check that the new monthly/annual grant does not duplicate your opening balance.

If starters are added alongside older paid categories, those older categories remain available. For a replacement benefit, configure the existing paid category with a new policy from the agreed start date, or deactivate the replaced category for new selections. Avoid offering the same entitlement twice under different categories.

Starter values are editable examples. Review them against your company policy and applicable employment requirements before use.

## Starter policies

**Add starter policies** creates Monthly Paid Leave and Compensatory Leave as separate paid categories. It does not replace existing leave types, and using it again does not duplicate the starter policies. Choose the start date and confirm that you will review the values.

| Setting | Monthly Paid Leave starter | Compensatory Leave starter |
| --- | --- | --- |
| Entitlement mode | Monthly | Earned compensatory leave only |
| Regular grant | 2 days per month | None; earned from reviewed work |
| Grant timing | Start of eligible period | Not used |
| Join/exit proration | Enabled; rounded down to half-days | Regular grant proration not used |
| Maximum carried leave | 6 days | Regular carry settings not used |
| Maximum available entitlement balance | 12 days | Regular entitlement cap not used |
| Entitlement expiry | End of third extra month after grant period | Not used |
| Further rollovers | Allowed within original expiry | Not used |
| Allow excess leave as unpaid | Off | Off |
| Compensatory rewards | Off | On |
| Eligible work | If enabled: holidays and rostered off days | Holidays and rostered off days |
| Half/full-day work threshold | If enabled: 240 / 480 minutes | 240 / 480 approved net minutes |
| Compensatory credit availability | If enabled: following date | Following date |
| Compensatory expiry | If enabled: 90 days after availability | 90 days after availability |
| Allowed reward | HR chooses leave or payment | HR chooses leave or payment |
| Additional overtime with reward | Off | Off |
| Full-day reward payment | 0; payment cannot be approved until configured | 0; payment cannot be approved until configured |
| Payment currency | INR; editable | INR; editable |

## Leave calendar settings

| Field | How to configure it | Effect |
| --- | --- | --- |
| Timezone | Select the company's leave timezone. Initially uses the application's attendance timezone, normally Asia/Kolkata. | Determines the current date for leave grants, expiry processing and future-work checks. It does not change shift times or the attendance timezone. |
| Expiry warning days | Enter 0–365; default 30. | Leave Balances shows unused eligible credits expiring within this many days as **Expiring soon**. It does not change their expiry date. |

## Policy and effective date

| Field | How to configure it | Effect |
| --- | --- | --- |
| Name | Use a clear name, such as “Office staff — Monthly paid leave”. | Identifies the policy to HR. |
| Leave type | Select an active paid leave category. Create a separate unpaid category for unpaid leave. | Employees request this category in Employee Leave; balances remain separate between categories. A saved policy keeps its leave type. |
| Default for employees without an individual assignment | On for the company-wide rule for this leave type; off for an assigned employee policy. | An applicable individual assignment takes priority over the default. Only one default is allowed per leave type. A saved policy keeps its default/assigned scope. |
| Effective from | Set the first applicable date. Initial enrolment can start mid-period. | The policy does not create entitlement before this date. Conflicting leave or closed payroll blocks enrolment. |
| Reason for this revision | Describe the approved company decision. | Retains the reason for later review. |
| Enable policy | On to issue grants and allow configured work rewards; off to stop new grants/rewards from the revision date. | Existing unexpired credits remain usable subject to the applicable leave rules. Disabling does not erase earlier credits or decisions. |

To change a saved policy, choose **New revision**. Monthly entitlement revisions begin on a future first day of a month; annual revisions begin on a future 1 January. Compensatory-only revisions may begin on another future date. Issued credits keep their original expiry and rules. The app also blocks revisions that conflict with already issued credits or existing leave/payroll commitments.

## Entitlement

| Field | How to configure it | Effect |
| --- | --- | --- |
| Entitlement mode | Choose Monthly, Annual, or Earned compensatory leave only. | Monthly grants once per eligible month; Annual grants once per eligible January–December year. Compensatory-only has no regular grant. |
| Leave granted per period | Enter days in 0.5-day steps; for example 2 monthly or 12 annually. | Sets the regular grant. Changing mode does not automatically convert the amount into an equivalent yearly/monthly allowance. |
| Grant timing | Start of eligible period or End of eligible period. | Start makes entitlement available at the eligible start; End waits until the eligible period ends. Future unearned regular grants are not available for advance booking. |
| Prorate join/exit using actual calendar days; round down to half-days | Enable when entitlement should follow the eligible portion of the month/year. | Uses employment, enrolment and assignment dates. For a 31-day month with 16 eligible days and a 2-day grant, the grant is 1 day after rounding down to a half-day step. |
| Allow excess leave as unpaid | Off to reject a request when valid credits cannot cover it; on to allow an uncovered portion. | Covered days remain paid; uncovered days follow the leave type's deduction rule for monthly payroll. Daily/hourly pay covers only the approved paid portion. |
| Maximum available entitlement balance | Set the maximum available days for new regular grants. | Limits the amount issued at grant time. It does not delete approved bookings; reviewed opening balances and compensatory awards are separate from regular entitlement grants. |

A grant already issued is retained when employment dates are changed later. Review any resulting balance correction through Leave Balances rather than expecting the old grant to be rewritten.

## Carry-forward and expiry

These fields apply to regular monthly/annual entitlement. Compensatory credits use their own expiry below.

| Field | How to configure it | Effect |
| --- | --- | --- |
| Carry unused entitlement forward | On to retain eligible unused days beyond the grant period. | Off expires entitlement at the period end. |
| Allow further rollovers within original expiry | On to allow another rollover if the original expiry still permits it. | Off limits the credit to at most the one following month after its grant period. Neither option extends the original expiry. |
| Maximum carried leave | Enter the carry limit in days; default 6. | Limits eligible unused entitlement at the next grant boundary. Excess free credit is removed with retained history. Reserved/consumed leave remains accounted for separately. |
| Extra months after grant period (0 = period end) | Enter 0–24. | With carry enabled, expires at the end of this many extra months. An August grant with 3 extra months expires on 30 November. |
| Optional cutoff month (1–12) | Set with the cutoff day, or leave both blank. | Selects a fixed expiry month instead of the relative extra-month rule. |
| Optional cutoff day (1–31; shorter months use month end) | Set with the cutoff month. | For March 31 enter month 3, day 31. The app uses the next applicable cutoff that is not before the grant-period end. February 31 uses February's actual last day. |

Expiry dates are inclusive: a credit can cover eligible leave on its expiry date, but not a later leave date. A fixed cutoff is used only with carry enabled. If further rollovers are off, the one-following-month limit can make expiry earlier than the fixed cutoff.

## Holiday and rostered off-day work

A rostered off day is determined by the employee's effective shift assignment. It is not automatically Saturday or Sunday. Set shifts, assignments and covered holidays correctly before reviewing work rewards.

| Field | How to configure it | Effect |
| --- | --- | --- |
| Enable compensatory rewards | On for a policy that should reward eligible work. | Enables HR awards from Attendance Days. It does not automatically award credits from every punch. |
| Eligible on holidays | On when work on a holiday covered for that employee can earn a reward. | Holiday work may qualify after attendance is current and approved. |
| Eligible on rostered off days | On when work on the employee's rostered off day can earn a reward. | Off-day work may qualify; a working weekend remains a normal working day. |
| Minimum minutes for half-day reward | Enter 1–1440 approved net minutes; default 240. | Below this threshold there is no credit; meeting it earns 0.5 day unless the full-day threshold is met. |
| Minimum minutes for full-day reward | At least the half-day threshold, up to 1440; default 480. | Meeting it earns 1 day. Multiple shifts on the same work date do not create duplicate full-day awards. |
| Credit expiry in days after availability | Enter 0–730; default 90. | Credit expires this many days after its availability date. A value of 0 means it can cover only its availability date. |
| Credit available on work date (leave/work overlap is still checked) | Off for availability on the following date; on for the work date. | Changes availability, not permission to take leave on a worked holiday. A full worked day cannot also be a full leave day. |
| Allowed reward | HR chooses leave or payment; Leave only; Payment only; or Both leave and payment. | Controls which reward HR may approve. Both requires the explicit Both policy; Choice allows one or the other. |
| Allow additional approved overtime with reward | Off unless company policy permits both overtime and the work reward. | Off blocks a reward when overtime is already approved. Resolve pending overtime decisions first. |
| Payment currency | Enter a three-letter currency code, such as INR or USD. | Must match the employee's payroll currency when approving a monetary reward. |
| Full-day reward payment | Enter the full-day amount in the selected currency. | A half-day reward pays half this amount. A positive amount is required for Payment/Both. The approved amount is included in the work month's payslip when payroll is calculated. |

## Assigning different rules

Use **Assign employees** on the policy row. Branch, department and task-group selections narrow the employee list together. Selecting specific employees narrows it further. Blank employee selection means all employees matching the chosen filters; blank filters mean all employees.

Set **Starts on** to a month's first day and **Ends on**, if used, to a month end. Annual assignments use 1 January through 31 December. Employment dates still control join/exit proration within these windows. Overlapping assignments for the same employee and leave type are rejected.

Assignments apply to the employees selected at that time. A new employee joining a branch/group later does not automatically acquire that individual assignment; assign them explicitly or let the applicable tenant default apply.

Use **End assignment** to shorten an assignment before switching policy. Choose the employee assignment, a permitted future month end and a reason. Then assign the next policy from the following period. Annual assignments end on 31 December. Resolve future leave or credits that conflict with shortening first.

## Leave Types and deduction settings

These settings remain under **Payroll → Leave types**.

| Field | Purpose and payroll effect |
| --- | --- |
| Leave name | Category shown in Employee Leave and balances. |
| Code | Unique category identifier; saved in uppercase. |
| Annual paid allowance | Existing annual allowance for employees/dates not enrolled in Leave Settings. New enrolled policies use approved balance allocations, so this does not add a second annual grant. |
| Paid leave | Enables paid treatment for covered leave. A type already used by a balance policy keeps its paid classification; create a separate unpaid type rather than changing that classification. |
| Deduction rule: No deduction | No additional leave deduction is charged by that rule in monthly payroll. Select this only when that is the intended company treatment. |
| Deduction rule: Employee salary day rate | Uses the employee's calculated scheduled-day rate for the unpaid portion in monthly payroll. |
| Deduction rule: Basic salary ÷ actual calendar days | Uses actual days in the month, including 28/29-day February, for the unpaid portion in monthly payroll. |
| Deduction rule: Fixed amount per full day | Uses the configured full-day deduction; half-day uncovered leave uses half. |
| Fixed deduction per full day | Amount used only for the Fixed amount rule. |
| Active | Makes the category available for new selections when enabled; does not erase its historical records. |
| Description | Explains the category to administrators. |

Daily/hourly employees receive pay for the covered leave portion under enrolled policies; uncovered leave does not earn that leave pay. Monthly employees retain salary for covered leave and the uncovered portion follows the configured deduction treatment. For daily use, see [Leave workflows](leave-workflows.md). For report interpretation, see [Attendance and payslips](leave-attendance-reports.md).
