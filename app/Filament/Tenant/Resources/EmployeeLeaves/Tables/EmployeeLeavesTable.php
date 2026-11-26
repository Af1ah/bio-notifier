<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Tables;

use App\Models\EmployeeLeave;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Support\TenantCurrency;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeLeavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('leave_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('user.name')->label('Employee')->description(fn ($record): string => 'PIN '.$record->user->pin)->searchable()->sortable(),
                TextColumn::make('leaveType.name')->label('Leave type')->badge()->searchable(),
                TextColumn::make('duration')->badge()->formatStateUsing(fn ($state): string => ucfirst($state).' day'),
                TextColumn::make('state')->badge()->color(fn ($state): string => $state === 'approved' ? 'success' : 'gray'),
                TextColumn::make('deduction_override_minor')
                    ->label('Deduction override')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'Policy default' : TenantCurrency::format((int) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload(),
                SelectFilter::make('leave_type_id')->label('Leave type')->relationship('leaveType', 'name')->preload(),
                SelectFilter::make('state')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']),
            ])
            ->defaultSort('leave_date', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(), EditAction::make(),
                    Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('success')->modalDescription(fn (EmployeeLeave $record) => $record->notes)->requiresConfirmation()
                        ->visible(fn (EmployeeLeave $record) => $record->state === 'pending')
                        ->action(fn (EmployeeLeave $record) => self::decide($record, 'approved')),
                    Action::make('reject')->label('Reject')->icon('heroicon-o-x-mark')->color('danger')->modalDescription(fn (EmployeeLeave $record) => $record->notes)->requiresConfirmation()
                        ->visible(fn (EmployeeLeave $record) => $record->state === 'pending')
                        ->action(fn (EmployeeLeave $record) => self::decide($record, 'rejected')),
                ])->label('Actions'),
            ])
            ->toolbarActions([]);
    }

    public static function decide(EmployeeLeave $record, string $state): void
    {
        abort_unless(auth('web')->user()?->hasManagementAccess(), 403);
        app(EmployeeLeaveService::class)->decide($record, $state, auth('web')->user());
        Notification::make()->title($state === 'approved' ? 'Leave approved' : 'Leave rejected')->success()->send();
    }
}
