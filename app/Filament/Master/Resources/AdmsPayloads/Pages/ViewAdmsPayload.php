<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Pages;

use App\Filament\Master\Resources\AdmsPayloads\AdmsPayloadResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdmsPayload extends ViewRecord
{
    protected static string $resource = AdmsPayloadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
