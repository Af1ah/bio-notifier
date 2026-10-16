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
    public static function configure(Schema $schema, bool $paidOnly = false): Schema
    {
        return $schema
            ->components([
                Section::make('Leave category')
                    ->description('Set the category and payroll deduction treatment. Paid leave entitlement is configured in Leave settings.')
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
                        Toggle::make('is_paid')
                            ->label('Paid leave')
                            ->helperText('Approved leave covered by policy credit allocations is paid.')
                            ->visible(! $paidOnly)
                            ->default(true)
                            ->live(),
                        Select::make('deduction_method')
                            ->label('Deduction rule')
                            ->helperText('Applied to this unpaid category in monthly payroll. No deduction keeps salary unchanged.')
                            ->options([
                                'none' => 'No deduction',
                                'salary_day_rate' => 'Employee salary day rate',
                                'basic_salary_31_day_rate' => 'Basic salary ÷ actual calendar days',
                                'fixed_amount' => 'Fixed amount per full day',
                            ])
                            ->default('salary_day_rate')
                            ->visible(fn ($get): bool => ! $get('is_paid'))
                            ->required(fn ($get): bool => ! $get('is_paid'))
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
                            ->visible(fn ($get): bool => ! $get('is_paid') && $get('deduction_method') === 'fixed_amount')
                            ->required(fn ($get): bool => ! $get('is_paid') && $get('deduction_method') === 'fixed_amount'),
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
