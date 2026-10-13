<?php
namespace App\Filament\Tenant\Resources\SiteBridgeResource\Pages;

use App\Filament\Tenant\Resources\SiteBridgeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
use Illuminate\Contracts\Support\Htmlable;

class ListSiteBridges extends ListRecords
{
    use ManagesBridgePairing;
    protected static string $resource = SiteBridgeResource::class;
    protected string $view = 'filament.tenant.site-bridges.index';
    public function getTitle(): string|Htmlable { return 'Site bridges'; }
    protected function getHeaderActions(): array { return [CreateAction::make()->label('Add site')]; }
    public function bridges() { return SiteBridgeResource::getEloquentQuery()->with(['pairingRequests'=>fn($q)=>$q->latest()->limit(5)])->latest()->paginate(12, pageName:'sites'); }
}
