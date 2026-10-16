# Business rules for the 0.2 beta

14 October 2026. Implemented locally; UI refinement and release/deployment are separate steps. Version 0.1 online has not been changed.

## Recurring balance resets

In **Leave settings**, enable carry, choose **On recurring reset dates**, and add the dates. Each selected month/day repeats every year. Reset occurs at the start of the date in the configured company leave timezone; the old credit is valid only through the previous date. A grant issued on the reset date belongs to the new cycle.

For Outreach, add **1 June** and **31 December**. May credit expires 31 May; June credit starts the new cycle. December credit expires 30 December, so there is no remaining regular entitlement on 31 December; January starts with its new grant. This follows the confirmed start-of-day reset rule. A February 29 reset uses February 28 in non-leap years. Existing issued lots retain their recorded expiry when a later policy revision changes the rule.

Relative expiry and an inclusive annual cutoff remain available for other companies. Choose one expiry method; changing methods clears the incompatible settings. A reset does not delete grant/consumption history or change finalized salary slips. Future requests are checked against the requested leave date.

## Holiday/off-day leave valid in the work month

In the policy's **Holiday and off-day work** section, select **Leave only** and **At the end of the month worked**. Verified approved work earns half/full-day credit using the daily shift thresholds. The credit expires on the final calendar date of the work month, independently of regular entitlement reset dates.

Approval after month-end does not extend the expiry. The award can be recorded as historical evidence, but its expired credit is unavailable for new leave. When next-day availability would fall after month-end, the leave award is rejected with a clear explanation: there is no usable date within the month. Same-day availability does not bypass holiday/off-day or work/leave overlap restrictions. The policy never silently grants another month to use the reward.

The original duration-based expiry option remains available for other companies. Award/reversal, attendance review and payroll continue through the existing workflow.

## Overtime eligibility and rates

In **Shift settings → Overtime**, use **Allow overtime** to explicitly enable or disable candidates and approval eligibility. Existing shifts without this setting retain their prior enabled behavior. A multi-shift day uses its selected daily rule as the overall eligibility gate; a disabled contributing shift receives no allocated overtime. Approved overtime cannot be removed by an incompatible correction without reconciliation.

Enter a shift-specific hourly rate and its currency, or leave the rate blank to use the employee payroll rate. An explicit shift rate of zero means no payment; it is different from blank/inherit. Hourly employee profiles retain their legacy fallback from a zero employee overtime rate to the regular hourly rate. Use the enable switch to prohibit overtime.

Approval captures the effective shift rule, rate, currency and rate source. Later edits to an employee's overtime rate do not change newly captured approvals. A mismatched shift/payroll currency blocks approval. Salary slips sum the captured amounts and include the same date/shift/minutes/rate breakdown on screen, PDF and CSV. Legacy approvals without a rate snapshot retain the existing employee-rate calculation and aggregate rounding convention. Existing finalized payslips are not regenerated.

## Leave ranges

Open **Employee leave → Add leave range**. Select employee, category, start/end dates, full/half-day duration and Pending/Approved status. Preview shows each date's paid/unpaid coverage and skipped holiday/off dates. Payroll calculates actual salary deductions later.

Save creates the daily leave records and credit allocations together. Failure on any date rolls back the entire request. Coverage is rechecked against the preview; changed coverage requires a fresh preview. Requests are limited to 93 calendar days, preserve the existing closed-payroll and work-overlap guards, and cannot spend future unearned grants. Repeated submission of the same request key returns the original result rather than creating duplicate records. Preview rolls back all temporary records and grants.

Daily records remain available for approval, cancellation, payroll and reports. The date-range action uses the same leave service as a single-date request.

## Holiday eligibility groups

Use **Organisation Management → Holiday eligibility** to create Role, Religion or Other company groups. HR explicitly assigns employees with an inclusive start and optional end date. Use **End membership** to record the final eligible date and reason. Historical membership is retained; overlaps within the same group are rejected, and changes affecting closed payroll are blocked. Open affected attendance is marked stale for recalculation.

Select these groups in a holiday's coverage settings. Existing branch, department and task-group coverage remains available. Coverage is a union: matching any selected scope grants eligibility. A holiday with no coverage selectors applies to everyone. Use a specifically maintained composite group when eligibility needs a combination of branch/role/religion. Eligibility is never inferred from names.

## Payroll templates

Use **Payroll → Payroll templates** to create monthly/daily/hourly defaults, rate, currency, allowances and an effective date. Optionally restrict the template to a branch or exact employee designation. **Apply to employees** applies the template to selected matching employees with missing or empty disabled payroll profiles.

Existing configured profiles and overrides are preserved; the result reports applied/preserved counts. Application records a template snapshot and actor on the profile. Monthly allowances/deductions are cleared for daily/hourly pay. Closed payroll cannot be affected. Once applied, the template is immutable; create a new template for different defaults. This is an explicit bulk setup workflow, not automatic rewriting of pay on a later branch transfer. Continue to edit agreed employee-specific rates through the employee payroll form.

## Migration and release sequence

Additive tenant migrations:

- `2026_10_14_190000_add_leave_range_requests.php`
- `2026_10_14_191000_add_holiday_eligibility_groups.php`
- `2026_10_14_192000_add_payroll_templates.php`

Apply tenant migrations before using the new code and restart workers through the normal release procedure. Prior leave migrations from this branch are also required for a version 0.1 upgrade. Follow [Beta verification and recovery](beta-verification.md) for backup and recovery. Do not roll back these tables after they contain business records without preserving that evidence.

Broader guided setup, inherited-rule presentation, unified exception queues and payroll previews remain the next UI phase. Publishing/tagging/deploying 0.2 is not part of this implementation step.
