<?php
namespace App\Filament\Tenant\Resources\SiteBridgeResource\Pages;
use App\Filament\Tenant\Resources\SiteBridgeResource;
use App\Models\SiteBridge;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
class CreateSiteBridge extends CreateRecord {
    protected static string $resource=SiteBridgeResource::class;
    protected function getRedirectUrl():string { return SiteBridgeResource::getUrl('edit',['record'=>$this->record]); }
    protected function handleRecordCreation(array $data):Model {
        $record=new SiteBridge(['name'=>$data['name']]);$record->forceFill(['tenant_id'=>(string)tenant('id')])->save();return $record;
    }
}
