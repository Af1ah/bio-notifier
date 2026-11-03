<?php

namespace App\Filament\Employee\Pages;

class Calendar extends PortalPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.employee.pages.calendar';
}
