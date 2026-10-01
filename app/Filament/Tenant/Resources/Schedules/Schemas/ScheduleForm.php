<?php

namespace App\Filament\Tenant\Resources\Schedules\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

class ScheduleForm
{
    private const WEEKDAYS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shift details')
                    ->description('Name the shift and define when it is available for assignment.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Shift name')
                            ->placeholder('For example, General Shift')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 2]),
                        Select::make('type')
                            ->label('Shift type')
                            ->options([
                                'regular' => 'Regular',
                                'special' => 'Special',
                            ])
                            ->helperText('Use Special for temporary or exceptional working arrangements.')
                            ->default('regular')
                            ->required(),
                        Toggle::make('status')
                            ->label('Shift enabled')
                            ->helperText('Disabled shifts remain saved but cannot be newly assigned.')
                            ->default(true),
                        DatePicker::make('valid_from')
                            ->label('Valid from')
                            ->native(false)
                            ->required()
                            ->default(today()),
                        DatePicker::make('valid_to')
                            ->label('Valid until')
                            ->helperText('Optional. Leave empty when the shift has no end date.')
                            ->native(false)
                            ->rule('after_or_equal:valid_from'),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ]),

                Section::make('Shift hours')
                    ->description('Set the scheduled start and finish time.')
                    ->schema([
                        TimePicker::make('rules.start_time')
                            ->label('Starts at')
                            ->seconds(false)
                            ->default('09:30')
                            ->required(),
                        TimePicker::make('rules.end_time')
                            ->label('Ends at')
                            ->seconds(false)
                            ->default('18:30')
                            ->required(),
                        Toggle::make('rules.end_day_offset')
                            ->label('Ends on the next day')
                            ->helperText('Enable this only for an overnight shift.')
                            ->default(false),
                    ])
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                        'xl' => 3,
                    ]),

                Section::make('Punch handling')
                    ->description('Choose which device punches count and how much time around the shift is accepted.')
                    ->schema([
                        Select::make('rules.punch_method')
                            ->label('Check-in and check-out method')
                            ->options([
                                'first_last' => 'First and last punch',
                                'device' => 'Device IN and OUT status',
                            ])
                            ->helperText('First and last punch is recommended when device directions are unreliable.')
                            ->default('first_last')
                            ->required(),
                        TextInput::make('rules.earliest_arrival_minutes')
                            ->label('Accept check-in before start')
                            ->helperText('Earlier punches are not assigned to this shift.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(60)
                            ->required(),
                        TextInput::make('rules.arrival_grace_minutes')
                            ->label('Late arrival grace')
                            ->helperText('Arrivals within this period are not marked late.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(15)
                            ->required(),
                        TextInput::make('rules.departure_grace_minutes')
                            ->label('Early departure grace')
                            ->helperText('Departures within this period are not marked early.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(15)
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),

                Section::make('Attendance thresholds')
                    ->description('Set the worked-time thresholds used to classify each attendance day.')
                    ->schema([
                        TextInput::make('rules.half_day_minutes')
                            ->label('Minimum time for half day')
                            ->helperText('Worked time below this value is marked absent.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(120)
                            ->required(),
                        TextInput::make('rules.full_day_minutes')
                            ->label('Minimum time for full day')
                            ->helperText('Worked time below this value, but above the half-day threshold, is marked half day.')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('minutes')
                            ->default(480)
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),

                Section::make('Overtime')
                    ->description('Define when additional worked time becomes eligible for approval.')
                    ->schema([
                        Select::make('rules.overtime_basis')
                            ->label('Calculate overtime')
                            ->options([
                                'after_required_hours' => 'After required daily hours are completed',
                                'after_shift_end' => 'After the scheduled shift end',
                            ])
                            ->default('after_required_hours')
                            ->required(),
                        TextInput::make('rules.overtime_minimum_minutes')
                            ->label('Minimum overtime')
                            ->helperText('Shorter overtime is ignored.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(30)
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),

                Section::make('Missing check-out')
                    ->description('Control how an attendance day closes when no valid check-out punch is received.')
                    ->schema([
                        Toggle::make('rules.auto_checkout_enabled')
                            ->label('Create an automatic check-out')
                            ->helperText('The detailed report will still flag the original check-out as missing.')
                            ->live(),
                        TimePicker::make('rules.auto_checkout_time')
                            ->label('Automatic check-out time')
                            ->seconds(false)
                            ->visible(fn ($get): bool => (bool) $get('rules.auto_checkout_enabled'))
                            ->required(fn ($get): bool => (bool) $get('rules.auto_checkout_enabled')),
                        Toggle::make('rules.auto_checkout_day_offset')
                            ->label('Check out on the next day')
                            ->helperText('Use this for overnight shifts when the automatic time is after midnight.')
                            ->visible(fn ($get): bool => (bool) $get('rules.auto_checkout_enabled')),
                        TextInput::make('rules.checkout_cutoff_minutes')
                            ->label('Wait for a check-out until')
                            ->helperText('After this period, the punch is treated as missing and the day can be finalized.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('minutes')
                            ->default(180)
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),

                Section::make('Working days')
                    ->description('Attendance is calculated only on the selected days.')
                    ->schema([
                        CheckboxList::make('rules.weekdays')
                            ->label('Days this shift applies')
                            ->options(self::WEEKDAYS)
                            ->default([1, 2, 3, 4, 5, 6])
                            ->columns([
                                'default' => 2,
                                'sm' => 4,
                                'lg' => 7,
                            ])
                            ->bulkToggleable()
                            ->required(),
                    ]),

                Section::make('Scheduled breaks')
                    ->description('Add the planned break periods included in this shift.')
                    ->headerActions([
                        Action::make('addBreak')
                            ->label('Add break')
                            ->icon('heroicon-o-plus')
                            ->modalHeading('Add scheduled break')
                            ->modalDescription('Choose when the break starts, its duration, and the days it applies.')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Break name')
                                    ->placeholder('For example, Lunch')
                                    ->required()
                                    ->maxLength(255),
                                TimePicker::make('start_time')
                                    ->label('Starts at')
                                    ->seconds(false)
                                    ->required(),
                                TextInput::make('duration_minutes')
                                    ->label('Duration')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->suffix('minutes')
                                    ->required(),
                                Toggle::make('day_offset')
                                    ->label('Starts on the next day'),
                                CheckboxList::make('weekdays')
                                    ->label('Applies on')
                                    ->options(self::WEEKDAYS)
                                    ->columns([
                                        'default' => 2,
                                        'sm' => 4,
                                    ])
                                    ->bulkToggleable()
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->action(function (array $data, Component $livewire): void {
                                $breaks = data_get($livewire->data, 'rules.breaks', []);
                                $breaks[] = $data;
                                data_set($livewire->data, 'rules.breaks', $breaks);
                            }),
                    ])
                    ->schema([
                        Repeater::make('rules.breaks')
                            ->label('Breaks')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Break name')
                                    ->required()
                                    ->maxLength(255),
                                TimePicker::make('start_time')
                                    ->label('Starts at')
                                    ->seconds(false)
                                    ->required(),
                                TextInput::make('duration_minutes')
                                    ->label('Duration')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->suffix('minutes')
                                    ->required(),
                                Toggle::make('day_offset')
                                    ->label('Starts on the next day'),
                                CheckboxList::make('weekdays')
                                    ->label('Applies on')
                                    ->options(self::WEEKDAYS)
                                    ->columns([
                                        'default' => 2,
                                        'sm' => 4,
                                        'lg' => 7,
                                    ])
                                    ->bulkToggleable()
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 4,
                            ])
                            ->itemLabel(fn (array $state): string => $state['name'] ?? 'Scheduled break')
                            ->addable(false)
                            ->defaultItems(0),
                    ]),
            ])
            ->columns(1);
    }
}
