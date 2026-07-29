<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Pages;

use App\Filament\Master\Resources\AdmsPayloads\AdmsPayloadResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmsPayload extends EditRecord
{
    protected static string $resource = AdmsPayloadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
