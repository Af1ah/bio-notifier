<?php

namespace App\Filament\Employee\Pages;

use App\Models\EmployeeLeave;
use App\Services\EmployeePortal\DepartmentReview;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Leave\EmployeeLeaveService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TeamLeaveRequests extends DepartmentReviewPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationLabel(): string
    {
        return __('department-review.leaves');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => EmployeeLeave::whereIn('user_id', DepartmentReview::employees(PortalAccess::employee())->select('users.id'))
            ->with(['user', 'leaveType']))
            ->contentGrid(['default' => 1])
            ->columns([Split::make([
                TextColumn::make('user.name')->label(__('department-review.employee'))->searchable(),
                TextColumn::make('leave_date')->label(__('department-review.date'))->date('d M Y')->extraAttributes(['class' => 'portal-number'])->sortable(),
                TextColumn::make('leaveType.name')->label(__('department-review.leave_type')),
                TextColumn::make('duration')->label(__('department-review.duration'))->formatStateUsing(fn (string $state) => __('department-review.duration_'.$state)),
                TextColumn::make('state')->label(__('department-review.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('department-review.state_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', default => 'gray',
                    }),
            ])])
            ->filters([SelectFilter::make('state')->label(__('department-review.status'))->options([
                'pending' => __('department-review.state_pending'),
                'approved' => __('department-review.state_approved'),
                'rejected' => __('department-review.state_rejected'),
                'cancelled' => __('department-review.state_cancelled'),
            ])->default('pending')])
            ->recordActions([ActionGroup::make([
                Action::make('reason')->label(__('department-review.reason'))->icon(Heroicon::OutlinedDocumentText)
                    ->modalDescription(fn (EmployeeLeave $record) => $record->notes)
                    ->modalSubmitAction(false),
                $this->decision(true), $this->decision(false),
            ])])
            ->defaultSort('leave_date', 'desc')->defaultKeySort()
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions();
    }

    private function decision(bool $approve): Action
    {
        return Action::make($approve ? 'approve' : 'reject')
            ->label(__($approve ? 'department-review.approve' : 'department-review.reject'))
            ->icon($approve ? Heroicon::OutlinedCheck : Heroicon::OutlinedXMark)
            ->color($approve ? 'success' : 'danger')->requiresConfirmation()
            ->modalDescription(fn (EmployeeLeave $record) => $record->notes)
            ->visible(fn (EmployeeLeave $record) => $record->state === 'pending')
            ->action(fn (EmployeeLeave $record) => app(EmployeeLeaveService::class)
                ->decide($record, $approve ? 'approved' : 'rejected', PortalAccess::employee()))
            ->successNotificationTitle(__($approve ? 'department-review.leave_approved' : 'department-review.leave_rejected'));
    }
}
