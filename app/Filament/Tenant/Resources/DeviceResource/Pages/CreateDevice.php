<?php

namespace App\Filament\Tenant\Resources\DeviceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Tenant\Resources\DeviceResource;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;
}
