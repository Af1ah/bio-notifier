<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

class AccessPeriodsRelationManager extends AccessSettingsRelationManager
{
    protected static string $relationship = 'accessPeriods';

    protected static ?string $title = 'Daily periods';

    protected static string $accessKind = 'period';
}
