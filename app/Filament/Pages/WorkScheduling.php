<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class WorkScheduling extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.work-scheduling';
}
