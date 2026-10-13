<?php

namespace App\Filament\Master\Resources\Organisations\Tables;

use App\Filament\Master\Resources\Organisations\OrganisationResource;
use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use App\Services\QuickLoginService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrganisationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('device_integration')
                    ->label('Device integration')
                    ->formatStateUsing(fn (?string $state): string => DeviceIntegration::options()[$state ?? DeviceIntegration::EBIO] ?? 'Unknown')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                ImageColumn::make('logo'),
                ColorColumn::make('brand_color'),
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
            ->actions([
                Action::make('login_as_tenant')
                    ->label('Login')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('success')
                    ->url(fn (Organisation $record) => app(QuickLoginService::class)->issue($record))
                    ->openUrlInNewTab(),
                Action::make('manage_admins')
                    ->label('Manage Admins')
                    ->icon('heroicon-o-users')
                    ->url(fn (Organisation $record) => OrganisationResource::getUrl('manage-admins', ['record' => $record])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
