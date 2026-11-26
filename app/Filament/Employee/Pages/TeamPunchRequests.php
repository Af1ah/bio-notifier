<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Tenant\Resources\PortalPunches\PortalPunchResource;
use App\Models\PortalPunch;
use App\Services\EmployeePortal\DepartmentReview;
use App\Services\EmployeePortal\PortalAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TeamPunchRequests extends DepartmentReviewPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    public static function getNavigationLabel(): string
    {
        return __('department-review.punches');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => PortalPunch::whereIn('user_id', DepartmentReview::employees(PortalAccess::employee())->select('users.id'))
            ->with(['user', 'reviewer']))
            ->contentGrid(['default' => 1])
            ->columns([Split::make([
                TextColumn::make('user.name')->label(__('department-review.employee'))->searchable(),
                TextColumn::make('kind')->label(__('department-review.punch'))
                    ->formatStateUsing(fn (string $state) => __($state === 'in' ? 'employee-punch.checkIn' : 'employee-punch.checkOut')),
                TextColumn::make('punched_at')->label(__('department-review.recorded_at'))->dateTime('d M, h:i A')->extraAttributes(['class' => 'portal-number'])->sortable(),
                TextColumn::make('state')->label(__('department-review.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('department-review.state_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                    }),
            ])])
            ->filters([SelectFilter::make('state')->label(__('department-review.status'))->options([
                'pending' => __('department-review.state_pending'),
                'approved' => __('department-review.state_approved'),
                'rejected' => __('department-review.state_rejected'),
            ])->default('pending')])
            ->recordActions([ActionGroup::make([
                Action::make('view')->label(__('department-review.details'))->icon(Heroicon::OutlinedEye)
                    ->schema(PortalPunchResource::details(includeReviewNote: true))->modalWidth('3xl')->modalSubmitAction(false),
                PortalPunchResource::reviewAction(true, fn () => PortalAccess::employee()),
                PortalPunchResource::reviewAction(false, fn () => PortalAccess::employee()),
            ])])
            ->recordAction('view')->defaultSort('punched_at', 'desc')->defaultKeySort()
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions();
    }
}
