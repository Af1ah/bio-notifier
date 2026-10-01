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
                Section::make('Leave policy')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code')->badge(),
                        TextEntry::make('annual_allowance_half_units')
                            ->label('Annual paid allowance')
                            ->formatStateUsing(fn ($state): string => number_format(((int) $state) / 2, 1).' days'),
                        IconEntry::make('is_paid')->label('Paid leave')->boolean(),
                        TextEntry::make('deduction_method')
                            ->label('Deduction rule')
                            ->formatStateUsing(fn ($state): string => match ($state) {
                                'salary_day_rate' => 'Employee salary day rate',
                                'basic_salary_31_day_rate' => 'Basic salary ÷ month days (February: 30)',
                                'fixed_amount' => 'Fixed amount per full day',
                                default => 'No deduction',
                            }),
                        TextEntry::make('deduction_amount_minor')
                            ->label('Fixed deduction')
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
