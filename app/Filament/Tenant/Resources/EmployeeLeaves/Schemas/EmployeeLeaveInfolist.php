<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeLeaveInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Leave details')
                    ->schema([
                        TextEntry::make('user.name')->label('Employee'),
                        TextEntry::make('user.pin')->label('Employee PIN'),
                        TextEntry::make('leaveType.name')->label('Leave type'),
                        TextEntry::make('leave_date')->date('d M Y'),
                        TextEntry::make('duration')->badge()->formatStateUsing(fn ($state): string => ucfirst($state).' day'),
                        TextEntry::make('state')->badge()->color(fn ($state): string => $state === 'approved' ? 'success' : 'gray'),
                        TextEntry::make('deduction_override_minor')
                            ->label('Deduction override')
                            ->formatStateUsing(fn ($state): string => $state === null ? 'Policy default' : 'INR '.number_format(((int) $state) / 100, 2)),
                        TextEntry::make('notes')->placeholder('No notes')->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ]),
            ]);
    }
}
