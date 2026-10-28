<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

class AccessGroupsRelationManager extends AccessSettingsRelationManager
{
    protected static string $relationship = 'accessGroups';

    protected static ?string $title = 'Access groups';

    protected static string $accessKind = 'group';
}
