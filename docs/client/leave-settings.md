# Configure your company's leave policies

A leave type is a category such as Casual Leave, Monthly Paid Leave or Compensatory Leave. A policy tells the app how that category is earned, carried forward and used. Your company can have several policies and assign different policies to different employees. Changes affect only your company.

## First-time setup

1. Sign in as your company's administrator or HR administrator.
2. Open **Payroll → Leave settings → Leave calendar settings**. Choose the company's leave timezone and expiry-warning period.
3. Review the employment start/end dates in employee payroll profiles. These dates control join/exit proration. Without employment dates, eligibility begins at the policy or assignment start; importing an employee is not treated as a verified employment start date.
4. Choose **Add starter policies**, or choose **Add policy** in Leave settings. Create the paid category directly from the category selector. Use **Leave categories** in the same settings area for unpaid categories and category deduction rules.
5. Choose a policy start date. For a financial-year cycle, also set **Leave period starts** and **Leave period ends**, for example 1 April 2026 through 31 March 2027. Leave both blank to retain the existing calendar-based periods. Existing companies should use a date after closed payroll and existing pending/approved leave commitments. The app blocks conflicting changes rather than rewriting those records.
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
| Maximum regular entitlement balance | 12 days | Regular entitlement cap not used |
| Entitlement expiry | End of third extra month after grant period | Not used |
| Further rollovers | Automatic within original expiry | Not used |
| Allow excess leave as unpaid | Off | Off |
| Compensatory rewards | Off | On |
| Eligible work | If enabled: holidays and rostered off days | Holidays and rostered off days |
| Half/full-day work threshold | If enabled: Inherited from the effective daily shift rule | Inherited from the effective daily shift rule |
| Compensatory credit availability | If enabled: following date | Following date |
| Compensatory expiry | If enabled: 90 days after availability | 90 days after availability |
| Reward | HR chooses leave or payment | HR chooses leave or payment |
| Additional overtime with reward | Off | Off |
| Full-day reward payment | 0; payment cannot be approved until configured | 0; payment cannot be approved until configured |
| Payment currency | INR; editable | INR; editable |

## Leave calendar settings

| Field | How to configure it | Effect |
| --- | --- | --- |
| Select time zone | Select the company's leave timezone. Initially uses the application's attendance timezone, normally Asia/Kolkata. | Determines the current date for leave grants, expiry processing and future-work checks. It does not change shift times or the attendance timezone. |
| Expiry warning days | Enter 0–365; default 30. | Leave Balances shows unused eligible credits expiring within this many days as **Expiring soon**. It does not change their expiry date. |

## Policy

| Field | How to configure it | Effect |
| --- | --- | --- |
| Name | Use a clear name, such as “Office staff — Monthly paid leave”. | Identifies the policy to HR. |
| Leave type | Select an active paid leave category. Create a separate unpaid category for unpaid leave. | Employees request this category in Employee Leave; balances remain separate between categories. A saved policy keeps its leave type. |
| Default for employees without an individual assignment | On for the company-wide rule for this leave type; off for an assigned employee policy. | An applicable individual assignment takes priority over the default. Only one default is allowed per leave type. A saved policy keeps its default/assigned scope. |
| Leave period starts / ends | Optional pair of actual dates defining the company leave cycle. | Annual mode grants once for this window; monthly mode grants only for its eligible months. Dates do not repeat automatically. New regular grants stop after the end date. |
| Effective from | Set the first applicable date. Initial enrolment can start mid-period. | The policy does not create entitlement before this date. Conflicting leave or closed payroll blocks enrolment. |
| Reason for this revision | Describe the approved company decision. | Retains the reason for later review. |
| Enable policy | On to issue grants and allow configured work rewards; off to stop new grants/rewards from the revision date. | Existing unexpired credits remain usable subject to the applicable leave rules. Disabling does not erase earlier credits or decisions. |

Open the policy row’s **three-dot menu** and choose **New revision** to change a saved policy. Monthly entitlement revisions begin on a future first day of a month; annual revisions begin at the next configured leave-period start, or a future 1 January when no period is set. Compensatory-only revisions may begin on another future date. Issued credits keep their original expiry and rules. The app also blocks revisions that conflict with already issued credits or existing leave/payroll commitments.

## Entitlement and unused leave

| Field | How to configure it | Effect |
| --- | --- | --- |
| Entitlement | Choose Monthly, Annual, or Earned from holiday/off-day work. | Monthly grants once per eligible month; Annual grants once per configured leave period, or once per January–December year when period dates are blank. Compensatory-only has no regular grant. |
| Leave granted per period | Enter days in 0.5-day steps; for example 2 monthly or 12 annually. | Sets the regular grant. Changing mode does not automatically convert the amount into an equivalent yearly/monthly allowance. |
| Grant timing | Start of eligible period or End of eligible period. | Start makes entitlement available at the eligible start; End waits until the eligible period ends. Future unearned regular grants are not available for advance booking. |
| Prorate join/exit using actual calendar days; round down to half-days | Enable when entitlement should follow the eligible portion of the month/year. | Uses employment, enrolment and assignment dates. For a 31-day month with 16 eligible days and a 2-day grant, the grant is 1 day after rounding down to a half-day step. |
| Allow excess leave as unpaid | Off to reject a request when valid credits cannot cover it; on to allow an uncovered portion. | Covered days remain paid; uncovered days follow the policy’s unpaid-excess deduction rule for monthly payroll. Daily/hourly pay covers only the approved paid portion. |
| Maximum regular entitlement balance | Set the maximum available days for new regular grants. | Limits the amount issued at grant time. It does not delete approved bookings; reviewed opening balances and compensatory awards are separate from regular entitlement grants. |

A grant already issued is retained when employment dates are changed later. Review any resulting balance correction through Leave Balances rather than expecting the old grant to be rewritten.

### Unused leave and expiry

For multiple yearly resets, choose **On recurring reset dates** and add each date. Old credit expires the previous day; a grant issued on the reset date starts the new cycle. Outreach uses 1 June and 31 December, both at the start of the date. See [0.2 business rules](../beta-02-business-features.md).

These fields apply to regular monthly/annual entitlement. Compensatory credits use their own expiry below.

| Field | How to configure it | Effect |
| --- | --- | --- |
| Keep unused leave after the grant period | On to retain eligible unused days beyond the grant period. | Off expires entitlement at the period end. |
| Maximum carried leave | Enter the carry limit in days; default 6. | Limits eligible unused entitlement at the next entitlement-period boundary. Excess free credit is removed with retained history. Reserved/consumed leave remains accounted for separately. |
| Additional months after the grant period | Enter 0–24. | Select “After additional months” to expire at the end of this many extra months. An August grant with 3 extra months expires on 30 November. |
| Annual expiry cutoff | Shown when “On an annual cutoff date” is selected. Choose a date, such as 31 March or 30 June. | Its month and day repeat each year; the selected year is not stored as a one-time expiry. Each grant expires on the first cutoff on or after its grant-period end. A 29 February cutoff uses 28 February in a non-leap year. |

Expiry dates are inclusive: a credit can cover eligible leave on its expiry date, but not a later leave date. A fixed cutoff is used only with carry enabled. There is no separate rollover switch or manual carryforward action. Carry limits apply at period boundaries, including when grants are issued at period end.

### Financial-year period and expiry example

Set **Leave period starts** to **1 April 2026** and **Leave period ends** to **31 March 2027**. These are actual dates: regular grants stop after 31 March 2027. A monthly policy issues its configured grant each eligible month; an annual policy issues one grant for this full period. Join/exit and mid-period enrolment proration still apply. A monthly period ending mid-month prorates the final grant against that calendar month's full day count when proration is enabled.

For unused credit to expire at financial-year end, enable **Keep unused leave after the grant period**, choose **On an annual cutoff date**, and select **31 March**. To allow use through June instead, select **30 June**. The cutoff repeats annually, while the period dates do not. Credit is usable through its expiry date, and unavailable on the following day. Turning carry off expires regular credit at its own grant-period end.

To use relative expiry instead, choose **After additional months** and enter the duration. The form shows one expiry choice at a time; you do not configure a separate day field. Carry and available-balance limits still apply, so expiry alone does not guarantee the full unused grant remains available.

For the next financial year, create a **New revision** and review its proposed next-period dates. Employees on individual annual assignments also need the next-period assignment. Existing issued credit keeps its original expiry, even if a later revision changes the cutoff. Credits can remain usable after the old period ends when their expiry and the applicable assignment/policy allow it.

## Holiday and rostered off-day work

A rostered off day is determined by the employee's effective shift assignment. It is not automatically Saturday or Sunday. Set shifts, assignments and covered holidays correctly before reviewing work rewards.

| Field | How to configure it | Effect |
| --- | --- | --- |
| Enable work rewards | On for a policy that should reward eligible work. | Enables HR awards from Attendance Days. It does not automatically award credits from every punch. |
| Eligible on holidays | On when work on a holiday covered for that employee can earn a reward. | Holiday work may qualify after attendance is current and approved. |
| Eligible on rostered off days | On when work on the employee's rostered off day can earn a reward. | Off-day work may qualify; a working weekend remains a normal working day. |
| Half/full-day reward thresholds | Configure in Shift settings. There are no duplicate minute settings here. | Uses the effective daily shift rule captured for the work date: half threshold earns 0.5 day; full threshold earns 1 day. Multiple shifts use the selected daily rule and one reward per work date. |
| Earned leave expires | Choose a fixed number of days or **At the end of the month worked**. | Work-month expiry is independent of regular carry resets; late approval does not extend it. |
| Credit expiry in days after availability | Enter 0–730; default 90. | Credit expires this many days after its availability date. A value of 0 means it can cover only its availability date. |
| Credit available on work date (leave/work overlap is still checked) | Off for availability on the following date; on for the work date. | Changes availability, not permission to take leave on a worked holiday. A full worked day cannot also be a full leave day. |
| Reward | HR chooses leave or payment; Leave only; Payment only; or Both leave and payment. | Controls which reward HR may approve. Both requires the explicit Both policy; Choice allows one or the other. |
| Allow approved overtime alongside this reward | Off unless company policy permits both overtime and the work reward. | Off blocks a reward when overtime is already approved. Resolve pending overtime decisions first. |
| Payment currency | Enter a three-letter currency code, such as INR or USD. | Must match the employee's payroll currency when approving a monetary reward. |
| Full-day reward payment | Enter the full-day amount in the selected currency. | A half-day reward pays half this amount. A positive amount is required for Payment/Both. The approved amount is included in the work month's payslip when payroll is calculated. |

## Assigning different rules

Open the policy row’s **three-dot menu** and choose **Assign employees**. Branch, department and task-group selections narrow the employee list together. Selecting specific employees narrows it further. Blank employee selection means all employees matching the chosen filters; blank filters mean all employees.

Monthly assignments start on a month’s first day and end, if specified, at month end. Annual assignments use the configured leave-period dates. Leaving the end blank uses the configured period end. Without period dates, annual assignments use 1 January through 31 December. Employment dates still control join/exit proration within these windows. Overlapping assignments for the same employee and leave type are rejected.

Assignments apply to the employees selected at that time. A new employee joining a branch/group later does not automatically acquire that individual assignment; assign them explicitly or let the applicable tenant default apply.

Use **End assignment** to shorten an assignment before switching policy. Choose the employee assignment, a permitted future month end and a reason. Then assign the next policy from the following period. Annual assignments end on the configured period end, or 31 December when period dates are blank. Resolve future leave or credits that conflict with shortening first.

## Leave categories and deduction settings

Open **Payroll → Leave categories**, or use the category link in Leave settings. Paid categories can also be created while adding a policy. Deduction controls appear only when Paid leave is disabled. Paid categories store no deduction method or amount. Permitted unpaid excess is configured separately in Leave settings; covered paid leave is not deducted.

| Field | Purpose and payroll effect |
| --- | --- |
| Leave name | Category shown in Employee Leave and balances. |
| Code | Unique category identifier; saved in uppercase. |
| Paid leave | Enables paid treatment for covered leave. A type already used by a balance policy keeps its paid classification; create a separate unpaid type rather than changing that classification. |
| Deduction rule: No deduction | No additional leave deduction is charged by that rule in monthly payroll. Select this only when that is the intended company treatment. |
| Deduction rule: Employee salary day rate | Uses the employee's calculated scheduled-day rate for the unpaid portion in monthly payroll. |
| Deduction rule: Basic salary ÷ actual calendar days | Uses actual days in the month, including 28/29-day February, for the unpaid portion in monthly payroll. |
| Deduction rule: Fixed amount per full day | Uses the configured full-day deduction; half-day uncovered leave uses half. |
| Fixed deduction per full day | Amount used only for the Fixed amount rule. |
| Active | Makes the category available for new selections when enabled; does not erase its historical records. |
| Description | Explains the category to administrators. |

Daily/hourly employees receive pay for the covered leave portion under enrolled policies; uncovered leave does not earn that leave pay. Monthly employees retain salary for covered leave and the uncovered portion follows the configured deduction treatment. For daily use, see [Leave workflows](leave-workflows.md). For report interpretation, see [Attendance and payslips](leave-attendance-reports.md).

All new paid leave requests require an applicable entitlement policy. Historical unenrolled records retain their previous payroll treatment; existing balances and paid slips are not silently migrated. Annual allowance is no longer a second editable grant setting. Policies allowing unpaid excess require their own unpaid-excess deduction rule. Paid categories have no category deduction settings. Fields that do not apply to the selected entitlement, carry or reward mode are hidden.

## Remove a policy

Open the policy row’s **three-dot menu** and choose **Remove policy**. Review the record counts, type the exact policy name and enter the removal reason. Submission checks the counts again; if leave or payroll changed during review, reopen the action.

Removal deletes the policy, employee assignments, unprocessed policy leave/rewards/credits, and affected draft payslips. The leave category, raw attendance and unrelated policies remain. Reviewed, approved and paid payslips keep their exact stored amounts, status and snapshots. Only historical leave, allocations, rewards and detached revisions required by processed payroll remain; their credits cannot fund new leave. An audit snapshot retains the removal reason, actor and original records. Historical reward payments are kept for their already processed work month, and do not reappear in an unprocessed work month.

This action cannot be undone from the settings page. Employees can use a remaining applicable default policy; otherwise new paid leave requires a new policy. Recreate affected draft payslips only after reviewing the replacement leave rules.

The Scope column shows **Default** for employees without an individual assignment, or **Assigned employees** for an explicitly assigned policy. All row actions are in the three-dot menu. Policy sections span the full content width, with two field columns on desktop and one on smaller screens. **Expiry warning days** only controls the Expiring soon balance display; it does not change credit expiry or send notifications.

## Paid categories and unpaid deductions

The category list no longer includes a Deduction column. Paid categories have no deduction method or amount in the database or details page. Unpaid categories retain their own deduction controls in Create/Edit/View.

If **Allow excess leave as unpaid** is enabled in Leave settings, select **Unpaid excess deduction** there: salary day rate, basic salary divided by actual calendar days, or a positive fixed amount per full day. This applies only to uncovered leave in monthly payroll. Daily/hourly employees receive no leave earnings for the uncovered portion. The migration preserves existing configured excess rules in the corresponding policy revisions before clearing the paid-category values.

Previously processed payslips are not rewritten. Historical unenrolled paid-category leave with an uncovered portion needs HR reconciliation using an unpaid category before payroll can be recalculated; no deduction method is inferred from a cleared paid category.
