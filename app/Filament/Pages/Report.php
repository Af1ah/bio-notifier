<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Report extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-chart-bar';

    // protected static ?string $navigationGroup = 'Attendance';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.report';
}
