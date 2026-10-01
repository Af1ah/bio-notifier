<?php

namespace App\Filament\Tenant\Resources\LeaveTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Leave policy')
                    ->description('Define the allowance and payroll treatment for this leave type.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Leave name')
                            ->placeholder('For example, Casual Leave')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Code')
                            ->placeholder('CL')
                            ->required()
                            ->maxLength(24)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn ($state): string => strtoupper(trim((string) $state))),
                        TextInput::make('annual_allowance_half_units')
                            ->label('Annual paid allowance')
                            ->helperText('Use 0 when every occurrence should follow the deduction rule.')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->suffix('days')
                            ->default(0)
                            ->formatStateUsing(fn ($state): string => number_format(((int) ($state ?? 0)) / 2, 1, '.', ''))
                            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 2))
                            ->required(),
                        Toggle::make('is_paid')
                            ->label('Paid leave')
                            ->helperText('Leave within the annual allowance is paid.')
                            ->default(true)
                            ->live(),
                        Select::make('deduction_method')
                            ->label('Deduction rule')
                            ->helperText('Applied to unpaid leave and paid leave taken beyond its annual allowance.')
                            ->options([
                                'none' => 'No deduction',
                                'salary_day_rate' => 'Employee salary day rate',
                                'basic_salary_31_day_rate' => 'Basic salary ÷ month days (February: 30)',
                                'fixed_amount' => 'Fixed amount per full day',
                            ])
                            ->default('none')
                            ->required()
                            ->live(),
                        TextInput::make('deduction_amount_minor')
                            ->label('Fixed deduction per full day')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->formatStateUsing(fn ($state): string => number_format(((int) ($state ?? 0)) / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100))
                            ->visible(fn ($get): bool => $get('deduction_method') === 'fixed_amount')
                            ->required(fn ($get): bool => $get('deduction_method') === 'fixed_amount'),
                        Toggle::make('active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),
            ])
            ->columns(1);
    }
}
