<?php

namespace App\Services\Payroll;

use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Models\User;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualPayrollReviewService
{
    public function bounds(User $user, Carbon $period): array
    {
        $profile = app(PayrollProfileHistoryService::class)->forDate($user, $period);
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth()->startOfDay();
        foreach ([$profile?->effective_from, $profile?->employment_starts_on] as $date) {
            if ($date && $date->gt($start)) {
                $start = Carbon::parse($date)->startOfDay();
            }
        }
        if ($profile?->employment_ends_on && $profile->employment_ends_on->lt($end)) {
            $end = Carbon::parse($profile->employment_ends_on)->startOfDay();
        }

        return [$start, $end];
    }

    public function required(User $user, Carbon $period): bool
    {
        if ((app(PayrollProfileHistoryService::class)->forDate($user, $period)?->pay_basis ?: 'monthly') !== 'monthly') {
            return false;
        }
        [$start,$end] = $this->bounds($user, $period);
        $resolver = app(ShiftAssignmentResolver::class);
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (! $resolver->resolve($user, $date)) {
                return true;
            }
        }

        return AttendanceDay::where('user_id', $user->id)->whereIn('source', ['manual', 'mixed'])->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->exists();
    }

    public function evidence(User $user, Carbon $period): array
    {
        [$start,$end] = $this->bounds($user, $period);
        $days = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->orderBy('work_date')->get();

        return ['profile' => app(PayrollProfileHistoryService::class)->forDate($user, $period)?->toArray(), 'eligible_start' => $start->toDateString(), 'eligible_end' => $end->toDateString(),
            'days' => $days->map(fn ($d) => ['id' => $d->id, 'date' => $d->work_date->toDateString(), 'status' => $d->status, 'source' => $d->source, 'revision' => $d->calculation_revision, 'approved_minutes' => $d->approved_worked_minutes, 'approved_ot' => $d->approved_overtime_minutes, 'stale' => $d->stale_at?->toIso8601String()])->all(),
            'leaves' => EmployeeLeave::with('leaveType')->where('user_id', $user->id)->whereDate('leave_date', '>=', $start)->whereDate('leave_date', '<=', $end)->orderBy('id')->get()->toArray(),
            'leave_history' => EmployeeLeave::with('leaveType')->where('user_id', $user->id)->where('state', 'approved')->whereDate('leave_date', '>=', $start->copy()->startOfYear())->whereDate('leave_date', '<', $start)->orderBy('id')->get()->toArray(),
            'leave_balance_evidence' => app(LeaveBalanceService::class)->payrollEvidence($user, $start, $end),
            'assignments' => collect(CarbonPeriod::create($start, $end))->map(fn ($date) => app(ShiftAssignmentResolver::class)->resolve($user, $date)?->load('slots.ruleRevision')->toArray())->all()];
    }

    public function signature(User $user, Carbon $period): string
    {
        return $this->evidenceSignature($this->evidence($user, $period));
    }

    public function approved(User $user, Carbon $period): bool
    {
        $query = DB::table('manual_payroll_reviews')->where('user_id', $user->id)->whereDate('period_start', $period->copy()->startOfMonth())->whereDate('period_end', $period->copy()->endOfMonth());
        $signature = $this->signature($user, $period);
        if ((clone $query)->where('evidence_signature', $signature)->exists()) {
            return true;
        }
        // Retain valid older reviews without rewriting their original evidence.
        foreach ($query->get(['snapshot', 'evidence_signature']) as $review) {
            $snapshot = json_decode($review->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $original = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if (hash_equals($review->evidence_signature, $original) && hash_equals($signature, $this->evidenceSignature($snapshot))) {
                return true;
            }
        }

        return false;
    }

    private function evidenceSignature(array $evidence): string
    {
        foreach (['payroll_template_id', 'template_snapshot'] as $field) {
            if (($evidence['profile'][$field] ?? null) === null) {
                unset($evidence['profile'][$field]);
            }
        }
        foreach (['leaves', 'leave_history'] as $key) {
            foreach ($evidence[$key] ?? [] as $index => $leave) {
                if ($leave['leave_type']['is_paid'] ?? false) {
                    unset($evidence[$key][$index]['leave_type']['deduction_method'], $evidence[$key][$index]['leave_type']['deduction_amount_minor']);
                }
            }
        }

        return hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    public function approve(User $user, User $actor, Carbon $period, string $reason, string $expectedSignature): void
    {
        abort_unless($actor->privilege === 14, 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record the reason for approving this manual payroll period.']);
        }
        DB::transaction(function () use ($user, $actor, $period, $reason, $expectedSignature) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $user->setRelation('payrollProfile', $user->payrollProfile()->lockForUpdate()->first());
            $service = app(SalarySlipService::class);
            $issues = array_filter($service->readinessIssues($user, $period), fn ($issue) => ! str_starts_with($issue, 'Manual payroll period'));
            if ($issues) {
                throw ValidationException::withMessages(['review' => implode(' ', $issues)]);
            }
            $signature = $this->signature($user, $period);
            if (! hash_equals($signature, $expectedSignature)) {
                throw ValidationException::withMessages(['review' => 'Payroll evidence changed during review. Reload it before approval.']);
            }
            DB::table('manual_payroll_reviews')->insertOrIgnore(['user_id' => $user->id, 'period_start' => $period->copy()->startOfMonth()->toDateString(), 'period_end' => $period->copy()->endOfMonth()->toDateString(), 'evidence_signature' => $signature, 'snapshot' => json_encode($this->evidence($user, $period), JSON_THROW_ON_ERROR), 'reason' => $reason, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);
        });
    }
}
