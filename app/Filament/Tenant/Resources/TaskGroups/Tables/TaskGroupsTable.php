<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaskGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\BulkActionGroup::make([
                    \Filament\Tables\Actions\DeleteBulkAction::make(),
                    \Filament\Tables\Actions\BulkAction::make('unblockTaskGroupUsers')
                        ->label('Unblock users from door')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->form([
                            \Filament\Forms\Components\Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    return \App\Models\Device::all()->mapWithKeys(function ($d) {
                                        $loc = $d->options['location'] ?? null;
                                        return $loc ? [$loc => ($d->name ?: $d->serial_number) . " (Location: $loc)"] : [];
                                    })->filter()->toArray();
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $location = !empty($data['location']) ? (is_array($data['location']) ? implode(',', $data['location']) : $data['location']) : '';
                            $organisation = tenancy()->tenant;
                            $count = 0;
                            foreach ($records as $group) {
                                foreach ($group->users as $user) {
                                    \App\Jobs\BlockUnblockEbioUserJob::dispatch($organisation, $user->id, $location, false); // false = Unblock
                                    $count++;
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('Unblocked and Queued')
                                ->body("{$count} user(s) in selected task groups unblocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    \Filament\Tables\Actions\BulkAction::make('blockTaskGroupUsers')
                        ->label('Block users from door')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->form([
                            \Filament\Forms\Components\Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    return \App\Models\Device::all()->mapWithKeys(function ($d) {
                                        $loc = $d->options['location'] ?? null;
                                        return $loc ? [$loc => ($d->name ?: $d->serial_number) . " (Location: $loc)"] : [];
                                    })->filter()->toArray();
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            $location = !empty($data['location']) ? (is_array($data['location']) ? implode(',', $data['location']) : $data['location']) : '';
                            $organisation = tenancy()->tenant;
                            $count = 0;
                            foreach ($records as $group) {
                                foreach ($group->users as $user) {
                                    \App\Jobs\BlockUnblockEbioUserJob::dispatch($organisation, $user->id, $location, true); // true = Block
                                    $count++;
                                }
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('Blocked and Queued')
                                ->body("{$count} user(s) in selected task groups blocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
