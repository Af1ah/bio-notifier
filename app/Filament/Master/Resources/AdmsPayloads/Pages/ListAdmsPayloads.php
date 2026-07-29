<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Pages;

use App\Filament\Master\Resources\AdmsPayloads\AdmsPayloadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmsPayloads extends ListRecords
{
    protected static string $resource = AdmsPayloadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
