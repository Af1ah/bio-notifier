# Record leave and work rewards

Use these workflows after configuring your company in [Leave Settings](leave-settings.md). Administrators and HR administrators manage these pages.

## Record an employee's leave

1. Open **Payroll → Employee leave** and create a record.
2. Select the employee, leave type and leave date.
3. Choose **Full day** or **Half day**.
4. Select **Pending** to reserve the valid balance while the request is being reviewed, or **Approved** after HR has verified the request.
5. Add notes and save. The balance check uses credits available and unexpired on the selected leave date, and spends the earliest-expiring credit first.
6. To approve a pending request, edit that record, change Status to Approved and save.

| Field | What to enter |
| --- | --- |
| Employee | Employee taking the leave. This cannot change on an existing record. |
| Leave type | The requested category. To use compensatory credit, select its credited category. |
| Leave date | The actual date of leave, within the employee's employment dates. Use one record per employee/date. |
| Duration | Full day or Half day. |
| Status | Pending, Approved, Rejected or Cancelled. |
| Deduction override | Optional exact deduction for that leave record. Leave blank to use the company rule. Enter 0 only when deliberately overriding the deduction to zero. It does not create leave balance. |
| Notes | Supporting reason, reference or explanation. |

### What the status means

| Status | Balance effect | Payroll effect |
| --- | --- | --- |
| Pending | Reserves earned eligible credit so another request cannot spend it. | Blocks calculation of the affected payroll period until resolved. |
| Approved | Consumes the paid credit allocated to the request. Any allowed excess remains uncovered. | Includes approved paid/unpaid coverage in payroll. |
| Rejected | Releases any reservation associated with the record. | Does not count as approved leave. |
| Cancelled | Releases previously reserved/consumed credit. | Does not count as approved leave; affected draft payroll must be recalculated. |

Saving an unchanged record does not consume the balance again. Failed validation does not save a partial request.

Cancel a record instead of deleting it. Restored credit keeps its original expiry: cancelling August leave in October does not make an August-expired credit usable for new October leave. An existing cancelled record can be edited for a replacement request on that same date.

### Restrictions to resolve before saving

- A holiday or rostered off day does not consume ordinary leave. Choose a working leave date.
- Full-day leave cannot overlap approved work. Half-day leave with work requires a verified half-day attendance result.
- A new request cannot spend future monthly entitlement that has not yet been issued. It can reserve already earned credit valid on the requested future date.
- Insufficient balance rejects the request unless the applicable company policy allows the excess as unpaid.
- Reviewed, approved or paid payroll periods are closed to leave changes. Ask HR/payroll to follow the company's adjustment process for a historical correction.

## Award compensatory leave or payment from attendance

There is no separate compensatory page. Open **Organisation Management → Attendance**; the page is titled **Attendance Days**.

1. Filter by the employee's work date, source or shift as needed; search for the employee.
2. Check the row's **Day type**. It must be Holiday or Rostered off for an applicable enabled reward policy. A weekend that is a scheduled working day is not an off-day reward date.
3. Verify the shift and actual times. Use Correct or Add manual attendance if needed, then complete the attendance approval. Manual minutes are not eligible for an award while still awaiting approval.
4. Resolve pending overtime decisions. If overtime was approved, an additional reward requires a policy that permits it.
5. Choose **Award leave/payment** on that row. This action is shown for holiday/off rows with approved worked minutes and no active award. Meeting the company's policy and minimum minutes is checked when you submit.
6. Review the selected employee, date and approved net minutes. Select the compensatory leave type, reward and reason, then submit.
7. Check **Work reward**, **Reward status** and **Reward details** on the same row. Details show the approved amount, leave availability/expiry where applicable and the decision reason.

| Reward field | What to enter |
| --- | --- |
| Selected attendance | Read-only summary. Confirm that you opened the correct row. |
| Compensatory leave type | A category with an enabled compensatory policy applying to this employee and work date. |
| Reward | Paid leave credit, Payment, or Both. The selected category's policy must permit it. |
| Reason | HR's verified reason for the decision. |

With the starter thresholds, 240 approved net minutes earn 0.5 day and 480 earn 1 day. Breaks do not count toward net work. The date's shifts are considered together; additional shifts or repeated submission do not create duplicate awards. Payment for a half-day award is half the configured full-day reward amount.

Choosing paid leave credit records a balance for later use; it does not automatically create a leave request or change the work date to leave. Choosing payment puts the approved reward in the work month's payroll when the slip is calculated. Both does both only when explicitly allowed. Payment also requires a configured positive amount and matching employee/policy currency.

### Take the earned leave later

1. Open **Leave Balances**, filter to the credited leave type and check availability and expiry.
2. Create a record in **Employee Leave** for a valid working date, using that leave type.
3. Review and approve it through the normal leave workflow.

Same-date credit availability is optional. It does not allow a full worked holiday/off day to also count as full leave, and holidays/off dates themselves do not consume ordinary leave.

### Review or reverse a reward

| Reward status | Meaning and next action |
| --- | --- |
| Not awarded | No reward has been recorded for this date. Review eligibility before awarding. |
| Approved | An award was approved against the displayed work evidence. Check Reward details for its amount and any credit expiry. |
| Needs review | Attendance changed after the award. The affected credit is unavailable for new use; payroll using the award/credit must be reconciled. |
| Reversed | The original award has been reversed and retained in history. Its credit/payment no longer applies. A corrected award can be reviewed again if eligible. |

Choose **Reverse reward**, enter the reason and save to reverse unused credit/payment. If the credit is reserved or consumed, reconcile those leave records first. If payroll is already closed, use HR's reviewed adjustment process; this action does not rewrite a paid slip.

After a reversal, review the corrected work and use Award leave/payment again if appropriate. The prior decisions remain recorded.

## Review balances and opening adjustments

Open **Payroll → Leave Balances**. In Filters, select the leave type and balance date. Branch and department filters narrow the employees displayed.

| Balance column | Meaning |
| --- | --- |
| Available days | Eligible unspent credit that can cover leave on the selected date, after reservations and expiry checks. |
| Carried days | Remaining eligible regular entitlement from before the selected month. |
| Reserved days | Credit held by pending requests. |
| Consumed days | Credit consumed by approved requests. |
| Expiring soon days | Eligible unspent credit expiring within the company's warning window. |

The selected date determines credit eligibility. Existing reservations and consumption still reduce availability, including bookings for a later date; this screen is not a reconstruction of what HR knew at a historical point in time. Future dates do not issue unearned regular grants.

Choose **History** to see credit sources, availability/expiry dates and grant, reservation, consumption, release, expiry or correction records.

### Add reviewed balance

Use this for a verified opening balance or positive correction. Select the category, number of days, availability date, expiry date and reason. Enter days in half-day steps. Check whether regular grants have already been issued so the opening amount does not duplicate entitlement.

### Reduce reviewed balance

Select the credit, the days to remove and a reason. The reduction must fit the free credit; it cannot take away reserved or consumed leave. Compensatory credits are handled with Reverse reward on the attendance row instead. The original granted amount remains in history alongside the correction.

For how these records appear in reports and payroll, read [Attendance, reports and payslips](leave-attendance-reports.md).
