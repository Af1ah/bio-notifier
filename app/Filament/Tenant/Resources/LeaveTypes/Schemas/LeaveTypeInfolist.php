<?php

namespace App\Filament\Tenant\Resources\LeaveTypes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveTypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Leave category')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code')->badge(),
                        IconEntry::make('is_paid')->label('Paid leave')->boolean(),
                        TextEntry::make('deduction_method')
                            ->label('Deduction rule')->visible(fn ($record) => ! $record->is_paid)
                            ->formatStateUsing(fn ($state): string => match ($state) {
                                'salary_day_rate' => 'Employee salary day rate',
                                'basic_salary_31_day_rate' => 'Basic salary ÷ actual calendar days',
                                'fixed_amount' => 'Fixed amount per full day',
                                default => 'No deduction',
                            }),
                        TextEntry::make('deduction_amount_minor')
                            ->label('Fixed deduction')->visible(fn ($record) => ! $record->is_paid && $record->deduction_method === 'fixed_amount')
                            ->formatStateUsing(fn ($state): string => 'INR '.number_format(((int) $state) / 100, 2)),
                        IconEntry::make('active')->boolean(),
                        TextEntry::make('description')->placeholder('No description')->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),
            ]);
    }
}
