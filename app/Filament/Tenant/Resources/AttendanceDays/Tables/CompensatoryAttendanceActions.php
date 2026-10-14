<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Tables;

use App\Filament\Tenant\LeaveActionErrors;
use App\Models\AttendanceDay;
use App\Models\CompensatoryAward;
use App\Models\LeaveType;
use App\Services\Leave\CompensatoryLeaveService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;

class CompensatoryAttendanceActions
{
    public static function calendarKind(AttendanceDay $day): string
    {
        return $day->explanation['calendar_kind'] ?? app(CompensatoryLeaveService::class)->calendarKind($day->user, $day->work_date);
    }

    public static function awardState(AttendanceDay $day): string
    {
        $award = $day->compensatoryAward;
        if (! $award) {
            return 'Not awarded';
        }
        if ($award->state === 'approved' && ! hash_equals($award->evidence_signature, app(CompensatoryLeaveService::class)->signature($day))) {
            return 'Needs review';
        }

        return ucfirst($award->state);
    }

    public static function columns(): array
    {
        return [
            TextColumn::make('calendar_kind')->label('Day type')->state(fn (AttendanceDay $record) => match (self::calendarKind($record)) {
                'holiday' => 'Holiday', 'off' => 'Rostered off', 'working' => 'Working day', default => 'No assigned shift'
            })->badge()->toggleable(),
            TextColumn::make('compensatory_reward')->label('Work reward')->state(function (AttendanceDay $record) {
                $award = $record->compensatoryAward;
                if (! $award) {
                    return '—';
                }
                $parts = [];
                if (in_array($award->reward, ['leave', 'both'])) {
                    $parts[] = ($award->half_units / 2).' day leave';
                }
                if ($award->payment_minor > 0) {
                    $parts[] = ($award->evidence['policy_rules']['comp_payment_currency'] ?? '').' '.number_format($award->payment_minor / 100, 2);
                }

                return implode(' + ', $parts);
            })->toggleable(),
            TextColumn::make('compensatory_state')->label('Reward status')->state(fn (AttendanceDay $record) => self::awardState($record))->badge()
                ->color(fn ($state) => match ($state) {
                    'Approved' => 'success', 'Needs review' => 'warning', default => 'gray'
                })->toggleable(),
        ];
    }

    public static function actions(): array
    {
        return [
            Action::make('award')->label('Award leave/payment')->icon('heroicon-o-gift')
                ->visible(fn (AttendanceDay $record) => (int) auth()->user()?->privilege === 14 && $record->approved_worked_minutes > 0 && in_array(self::calendarKind($record), ['holiday', 'off']) && (! $record->compensatoryAward || $record->compensatoryAward->state === 'reversed'))
                ->modalHeading('Review holiday/off-day work reward')
                ->modalDescription('Approve a reward for this employee and work date. Resolve attendance and overtime decisions first. One reward applies across all shifts on the date.')
                ->fillForm(fn (AttendanceDay $record) => ['evidence_signature' => app(CompensatoryLeaveService::class)->signature($record), 'evidence_summary' => $record->user->name.' · '.$record->work_date->format('d M Y').' · '.$record->approved_worked_minutes.' approved net minutes · '.(self::calendarKind($record) === 'holiday' ? 'Holiday' : 'Rostered off day')])
                ->schema([
                    Textarea::make('evidence_summary')->label('Selected attendance')->readOnly()->dehydrated(false),
                    Hidden::make('evidence_signature')->required(),
                    Select::make('leave_type_id')->label('Compensatory leave type')->options(fn () => LeaveType::where('active', true)->where('is_paid', true)->pluck('name', 'id'))->required()->searchable(),
                    Select::make('reward')->options(['leave' => 'Paid leave credit', 'payment' => 'Payment', 'both' => 'Both (policy must permit)'])->required(),
                    Textarea::make('reason')->required()->maxLength(1000),
                ])->action(function (AttendanceDay $record, array $data, Schema $schema) {
                    LeaveActionErrors::run($schema, fn () => app(CompensatoryLeaveService::class)->award($record, (int) $data['leave_type_id'], $data['reward'], $data['reason'], $data['evidence_signature'], auth()->user()));
                    $record->unsetRelation('compensatoryAward');
                    Notification::make()->title('Work reward approved')->body('See Reward details for the approved reward and any leave credit availability dates.')->success()->send();
                }),
            Action::make('viewReward')->label('Reward details')->visible(fn (AttendanceDay $record) => $record->compensatoryAward !== null)
                ->modalHeading('Work reward and leave credit')->modalContent(fn (AttendanceDay $record) => view('filament.tenant.pages.attendance-work-reward', ['day' => $record, 'award' => $record->compensatoryAward, 'state' => self::awardState($record)]))->modalSubmitAction(false),
            Action::make('reverse')->label('Reverse reward')->color('danger')
                ->visible(fn (AttendanceDay $record) => (int) auth()->user()?->privilege === 14 && $record->compensatoryAward !== null && $record->compensatoryAward->state !== 'reversed')
                ->modalDescription('Retain the original award and record a reversal. Reserved or consumed leave must be reconciled first; closed payroll periods cannot be changed here.')
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(function (AttendanceDay $record, array $data, Schema $schema) {
                    LeaveActionErrors::run($schema, fn () => app(CompensatoryLeaveService::class)->reverse(CompensatoryAward::where('attendance_day_id', $record->id)->firstOrFail(), auth()->user(), $data['reason']));
                    $record->unsetRelation('compensatoryAward');
                    Notification::make()->title('Work reward reversed')->success()->send();
                }),
        ];
    }
}
