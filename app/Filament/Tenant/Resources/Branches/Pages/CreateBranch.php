<?php

namespace App\Filament\Tenant\Resources\Branches\Pages;

use App\Filament\Tenant\Resources\Branches\BranchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;
}
