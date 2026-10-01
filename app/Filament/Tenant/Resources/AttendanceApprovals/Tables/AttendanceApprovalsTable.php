<?php

namespace App\Filament\Tenant\Resources\AttendanceApprovals\Tables;

use App\Models\Branch;
use App\Models\AttendanceApproval;
use App\Models\Department;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attendanceDay.user.name')->label('Employee')->searchable(), TextColumn::make('attendanceDay.work_date')->date()->sortable(), TextColumn::make('type')->badge(), TextColumn::make('proposal.reason')->label('Reason'), TextColumn::make('proposal.minutes')->label('Minutes'), TextColumn::make('state')->badge(), TextColumn::make('created_at')->since(),
            ])
            ->filters([
                SelectFilter::make('state')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'superseded' => 'Superseded'])->default('pending'),
                SelectFilter::make('employee')->options(fn () => User::pluck('name', 'id'))->searchable()->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('attendanceDay', fn ($day) => $day->where('user_id', $id)))),
                SelectFilter::make('branch')->options(fn () => Branch::pluck('name', 'id'))->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('attendanceDay.user', fn ($user) => $user->where('branch_id', $id)))),
                SelectFilter::make('department')->options(fn () => Department::pluck('name', 'id'))->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('attendanceDay.user', fn ($user) => $user->where('department_id', $id)))),
                Filter::make('work_date')->schema([DatePicker::make('from'), DatePicker::make('to')])->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereHas('attendanceDay', fn ($day) => $day->whereDate('work_date', '>=', $date)))->when($data['to'] ?? null, fn ($q, $date) => $q->whereHas('attendanceDay', fn ($day) => $day->whereDate('work_date', '<=', $date)))),
            ])
            ->recordActions([
                Action::make('approve')->visible(fn (?AttendanceApproval $record): bool => $record?->state === 'pending')
                    ->modalHeading(fn (?AttendanceApproval $record): string => $record?->type === 'overtime' ? 'Approve overtime' : 'Approve attendance')->modalSubmitActionLabel('Approve')
                    ->schema([
                        DateTimePicker::make('checkout_at')->label('Checkout time')->seconds(false)
                            ->helperText('Defaults to the automatic checkout. Enter the actual departure date and time if different.')
                            ->visible(fn (?AttendanceApproval $record): bool => $record?->type === 'attendance' && ($record?->proposal['reason'] ?? null) === 'missing_checkout')
                            ->required(fn (?AttendanceApproval $record): bool => $record?->type === 'attendance' && ($record?->proposal['reason'] ?? null) === 'missing_checkout'),
                    ])
                    ->fillForm(fn (AttendanceApproval $record): array => ['checkout_at' => $record->occurrence?->assumed_out_at?->format('Y-m-d H:i:s') ?? ($record->proposal['assumed_out_at'] ?? null)])
                    ->action(function (AttendanceApproval $record, array $data, Schema $schema) {
                    try {
                        app(ApprovalService::class)->decide($record, auth()->user(), true, checkoutAt: $data['checkout_at'] ?? null);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Approval not saved')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
                        throw ValidationException::withMessages([$schema->getStatePath().'.checkout_at' => implode(' ', $exception->validator->errors()->all())]);
                    }
                    Notification::make()->title('Approved')->success()->send();
                }),
                Action::make('reject')->visible(fn (?AttendanceApproval $record): bool => $record?->state === 'pending')->color('danger')->schema([Textarea::make('reason')->required()])->action(function (AttendanceApproval $record, array $data) {
                    app(ApprovalService::class)->decide($record, auth()->user(), false, $data['reason']);
                    Notification::make()->title('Rejected')->success()->send();
                }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')->label('Approve selected')->requiresConfirmation()->action(function (Collection $records) {
                        if ($records->count() > 100 || $records->pluck('type')->unique()->count() !== 1) {
                            throw ValidationException::withMessages(['selection' => 'Select up to 100 items from one approval tab only.']);
                        }foreach ($records as $r) {
                            if ($r->state === 'pending') {
                                app(ApprovalService::class)->decide($r, auth()->user(), true);
                            }
                        }
                    })->deselectRecordsAfterCompletion(),
                    BulkAction::make('reject')->label('Reject selected')->color('danger')->schema([Textarea::make('reason')->required()])->action(function (Collection $records, array $data) {
                        if ($records->count() > 100 || $records->pluck('type')->unique()->count() !== 1) {
                            throw ValidationException::withMessages(['selection' => 'Select up to 100 items from one approval tab only.']);
                        }foreach ($records as $r) {
                            if ($r->state === 'pending') {
                                app(ApprovalService::class)->decide($r, auth()->user(), false, $data['reason']);
                            }
                        }
                    })->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
