<?php

namespace App\Filament\Tenant\Resources\Schedules\Schemas;

use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class ScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('Details')
                    ->columnSpan('full')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\Toggle::make('status')
                            ->default(true)
                            ->required(),
                        \Filament\Forms\Components\MorphToSelect::make('target')
                            ->types([
                                \Filament\Forms\Components\MorphToSelect\Type::make(\App\Models\Branch::class)->titleAttribute('name'),
                                \Filament\Forms\Components\MorphToSelect\Type::make(\App\Models\Department::class)->titleAttribute('name'),
                                \Filament\Forms\Components\MorphToSelect\Type::make(\App\Models\TaskGroup::class)->titleAttribute('name'),
                            ])
                            ->searchable()
                            ->preload(),
                        \Filament\Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options([
                                'regular' => 'Regular',
                                'special' => 'Special',
                            ])
                            ->default('regular')
                            ->required()
                            ->native(false),
                        \Filament\Forms\Components\DatePicker::make('valid_from'),
                        \Filament\Forms\Components\DatePicker::make('valid_to'),
                    ])->columns(3),

                \Filament\Schemas\Components\Section::make('Schedules')
                    ->columnSpan('full')
                    ->headerActions([
                        Action::make('addBreak')
                            ->label('Add Break')
                            ->icon('heroicon-m-plus')
                            ->color('primary')
                            ->modalHeading('Add Break')
                            ->modalWidth('md')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Break Name')
                                    ->placeholder('e.g. Lunch')
                                    ->required(),
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('duration')
                                            ->label('Duration')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required()
                                            ->default(60),
                                        Select::make('duration_unit')
                                            ->label('Unit')
                                            ->options([
                                                'minutes' => 'Minutes',
                                                'hours'   => 'Hours',
                                            ])
                                            ->default('minutes')
                                            ->required()
                                            ->native(false),
                                    ]),
                                TimePicker::make('start')
                                    ->label('Start Time')
                                    ->required()
                                    ->default('13:00'),
                            ])
                            ->action(function (array $data, \Livewire\Component $livewire): void {
                                // Read current weekly schedule from entangled Livewire form data
                                $current = data_get($livewire->data, 'rules.weekly');

                                // Ensure structure exists with safe fallbacks
                                if (! is_array($current)) {
                                    $current = [];
                                }
                                if (! isset($current['breaks']) || ! is_array($current['breaks'])) {
                                    $current['breaks'] = [];
                                }
                                if (! isset($current['days']) || ! is_array($current['days'])) {
                                    $current['days'] = [];
                                }

                                $breakId = 'brk_' . time();
                                $duration = (int) ($data['duration'] ?? 60);
                                $unit     = $data['duration_unit'] ?? 'minutes';
                                $start    = $data['start'] ?? '13:00';

                                // Register the break definition
                                $current['breaks'][] = [
                                    'id'            => $breakId,
                                    'name'          => $data['name'],
                                    'duration'      => $duration,
                                    'duration_unit' => $unit,
                                    'start'         => $start,
                                ];

                                // Apply break entry to every day
                                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                foreach ($days as $day) {
                                    if (! isset($current['days'][$day]) || ! is_array($current['days'][$day])) {
                                        $current['days'][$day] = [
                                            'is_working' => ! in_array($day, ['saturday', 'sunday']),
                                            'start'      => '09:00',
                                            'end'        => '18:00',
                                            'breaks'     => [],
                                        ];
                                    }
                                    if (! isset($current['days'][$day]['breaks'])) {
                                        $current['days'][$day]['breaks'] = [];
                                    }
                                    $current['days'][$day]['breaks'][$breakId] = [
                                        'is_active'     => true,
                                        'start'         => $start,
                                        'duration'      => $duration,
                                        'duration_unit' => $unit,
                                    ];
                                }

                                // Write back — Livewire entangle will push this to Alpine
                                data_set($livewire->data, 'rules.weekly', $current);
                            }),
                    ])
                    ->schema([
                        \Filament\Forms\Components\ViewField::make('rules.weekly')
                            ->view('filament.forms.components.weekly-schedule')
                            ->default([])
                            ->registerActions([
                                \Filament\Actions\Action::make('editBreak')
                                    ->label('Edit Break')
                                    ->modalHeading('Edit Default Break')
                                    ->modalWidth('md')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Break Name')
                                            ->required(),
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('duration')
                                                    ->label('Duration')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->required(),
                                                Select::make('duration_unit')
                                                    ->label('Unit')
                                                    ->options([
                                                        'minutes' => 'Minutes',
                                                        'hours'   => 'Hours',
                                                    ])
                                                    ->required()
                                                    ->native(false),
                                            ]),
                                        TimePicker::make('start')
                                            ->label('Start Time')
                                            ->required(),
                                    ])
                                    ->fillForm(function (array $arguments, \Livewire\Component $livewire) {
                                        $current = data_get($livewire->data, 'rules.weekly');
                                        $id = $arguments['id'] ?? null;
                                        if (! $id || empty($current['breaks'])) {
                                            return [];
                                        }

                                        $break = collect($current['breaks'])->firstWhere('id', $id);
                                        return $break ?: [];
                                    })
                                    ->action(function (array $arguments, array $data, \Livewire\Component $livewire): void {
                                        $current = data_get($livewire->data, 'rules.weekly');
                                        $id = $arguments['id'] ?? null;
                                        if (! $id) return;

                                        // Update the main break definition
                                        foreach ($current['breaks'] as &$break) {
                                            if ($break['id'] === $id) {
                                                $break['name']          = $data['name'];
                                                $break['duration']      = (int) $data['duration'];
                                                $break['duration_unit'] = $data['duration_unit'];
                                                $break['start']         = $data['start'];
                                            }
                                        }

                                        // Apply updated break properties to all days
                                        foreach ($current['days'] as $day => &$dayData) {
                                            if (isset($dayData['breaks'][$id])) {
                                                $dayData['breaks'][$id]['start']         = $data['start'];
                                                $dayData['breaks'][$id]['duration']      = (int) $data['duration'];
                                                $dayData['breaks'][$id]['duration_unit'] = $data['duration_unit'];
                                            }
                                        }

                                        data_set($livewire->data, 'rules.weekly', $current);
                                    }),

                                \Filament\Actions\Action::make('editDayBreak')
                                    ->label('Edit Day Break')
                                    ->modalHeading(fn (array $arguments) => 'Edit Break for ' . ucfirst($arguments['day'] ?? ''))
                                    ->modalWidth('md')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('duration')
                                                    ->label('Duration')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->required(),
                                                Select::make('duration_unit')
                                                    ->label('Unit')
                                                    ->options([
                                                        'minutes' => 'Minutes',
                                                        'hours'   => 'Hours',
                                                    ])
                                                    ->required()
                                                    ->native(false),
                                            ]),
                                        TimePicker::make('start')
                                            ->label('Start Time')
                                            ->required(),
                                    ])
                                    ->fillForm(function (array $arguments, \Livewire\Component $livewire) {
                                        $current = data_get($livewire->data, 'rules.weekly');
                                        $id = $arguments['id'] ?? null;
                                        $day = $arguments['day'] ?? null;
                                        if (! $id || ! $day || empty($current['days'][$day]['breaks'][$id])) {
                                            return [];
                                        }

                                        return $current['days'][$day]['breaks'][$id];
                                    })
                                    ->action(function (array $arguments, array $data, \Livewire\Component $livewire): void {
                                        $current = data_get($livewire->data, 'rules.weekly');
                                        $id = $arguments['id'] ?? null;
                                        $day = $arguments['day'] ?? null;
                                        if (! $id || ! $day) return;

                                        $current['days'][$day]['breaks'][$id]['start']         = $data['start'];
                                        $current['days'][$day]['breaks'][$id]['duration']      = (int) $data['duration'];
                                        $current['days'][$day]['breaks'][$id]['duration_unit'] = $data['duration_unit'];

                                        data_set($livewire->data, 'rules.weekly', $current);
                                    }),
                            ]),
                    ]),
            ])->columns(1);
    }
}
