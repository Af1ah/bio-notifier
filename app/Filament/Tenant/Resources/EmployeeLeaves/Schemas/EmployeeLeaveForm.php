<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Schemas;

use App\Models\LeaveType;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeLeaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee leave')
                    ->description('Record approved leave that should be included in payroll calculations.')
                    ->schema([
                        Select::make('user_id')
                            ->label('Employee')
                            ->options(fn () => User::query()->orderBy('name')->get()->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->pin})"]))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('leave_type_id')
                            ->label('Leave type')
                            ->options(fn () => LeaveType::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('leave_date')
                            ->label('Leave date')
                            ->native(false)
                            ->required(),
                        Select::make('duration')
                            ->options([
                                'full' => 'Full day',
                                'half' => 'Half day',
                            ])
                            ->default('full')
                            ->required(),
                        Select::make('state')
                            ->label('Status')
                            ->options([
                                'approved' => 'Approved',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('approved')
                            ->required(),
                        TextInput::make('deduction_override_minor')
                            ->label('Deduction override')
                            ->helperText('Optional. Enter the exact deduction for this leave record.')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->formatStateUsing(fn ($state): ?string => $state === null ? null : number_format(((int) $state) / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : (int) round(((float) $state) * 100)),
                        Textarea::make('notes')
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
