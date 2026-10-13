<?php
namespace App\Filament\Tenant\Resources\SiteBridgeResource\Pages;

use App\Filament\Tenant\Resources\SiteBridgeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditSiteBridge extends EditRecord
{
    use ManagesBridgePairing;
    protected static string $resource = SiteBridgeResource::class;
    protected string $view = 'filament.tenant.site-bridges.edit';
    public function getTitle(): string|Htmlable { return 'Manage site bridge'; }
    public function bridgeDetails() { return SiteBridgeResource::getEloquentQuery()->with(['pairingRequests'=>fn($q)=>$q->latest()->limit(10)])->findOrFail($this->getRecord()->getKey()); }
}
