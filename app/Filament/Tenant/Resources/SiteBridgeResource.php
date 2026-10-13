<?php
namespace App\Filament\Tenant\Resources;
use App\Filament\Tenant\Resources\SiteBridgeResource\Pages;
use App\Models\SiteBridge;
use App\Services\Bridge\BridgeGateway;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
class SiteBridgeResource extends Resource {
    protected static ?string $model=SiteBridge::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-signal';
    protected static \UnitEnum|string|null $navigationGroup='Device Management';
    protected static ?string $navigationLabel='Site bridges';
    public static function canViewAny():bool{return auth()->check() && ((int)auth()->user()->privilege===14 || auth()->user()->role==='admin');}
    public static function canCreate():bool{return static::canViewAny();}
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record):bool{return static::canViewAny() && $record->tenant_id===(string)tenant('id');}
    public static function canView(\Illuminate\Database\Eloquent\Model $record):bool{return static::canEdit($record);}
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record):bool{return false;}
    public static function getEloquentQuery():Builder{return parent::getEloquentQuery()->where('tenant_id',(string)tenant('id'));}
    public static function form(Schema $schema):Schema{return $schema->components([TextInput::make('name')->label('Site name')->required()->maxLength(120)]);}
    public static function table(Table $table):Table{return $table->columns([
        TextColumn::make('name')->searchable(),TextColumn::make('hardware_id')->label('ESP32'),
        TextColumn::make('public_key')->label('Pairing')->formatStateUsing(fn($state,$record)=>$record->revoked_at?'Revoked':($state?'Paired':'Not paired')),
        TextColumn::make('last_seen_at')->label('Last connected')->dateTime(),
    ])->recordActions([
        EditAction::make(),
        Action::make('pair')->label('Pair ESP32')->visible(fn($record)=>!$record->public_key&&!$record->revoked_at)->action(function($record){
            $code=$record->issuePairingCode();Notification::make()->title('Pairing code: '.$code)->body('Use this code in the ESP32 Remote bridge page within 15 minutes. It works once.')->persistent()->success()->send();
        }),
        Action::make('revoke')->label('Revoke')->requiresConfirmation()->visible(fn($record)=>!$record->revoked_at)->action(function($record){
            $record->forceFill(['revoked_at'=>now(),'pairing_hash'=>null,'pairing_code'=>null])->save();
            try{app(BridgeGateway::class)->revoke($record);}catch(\Throwable $e){/* Registry heartbeat also enforces revocation. */}
            Notification::make()->title('Bridge identity revoked')->success()->send();
        }),
    ]);}
    public static function getPages():array{return ['index'=>Pages\ListSiteBridges::route('/'),'create'=>Pages\CreateSiteBridge::route('/create'),'edit'=>Pages\EditSiteBridge::route('/{record}/edit')];}
}
