<?php

namespace App\Filament\Master\Resources\Organisations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                \Filament\Tables\Columns\ImageColumn::make('logo'),
                \Filament\Tables\Columns\ColorColumn::make('brand_color'),
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
                \Filament\Actions\Action::make('login_as_tenant')
                    ->label('Login')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('success')
                    ->url(fn (\App\Models\Organisation $record) => \Illuminate\Support\Facades\URL::signedRoute('tenant.impersonate', ['tenant' => $record->shortname ?? $record->id]))
                    ->openUrlInNewTab(),
                \Filament\Actions\Action::make('manage_admins')
                    ->label('Manage Admins')
                    ->icon('heroicon-o-users')
                    ->url(fn (\App\Models\Organisation $record) => \App\Filament\Master\Resources\Organisations\OrganisationResource::getUrl('manage-admins', ['record' => $record])),
                \Filament\Actions\ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
