<?php
namespace App\Filament\Tenant\Resources\SiteBridgeResource\Pages;

use App\Models\SiteBridge;
use App\Filament\Tenant\Resources\SiteBridgeResource;
use App\Services\Bridge\SiteBridgePairing;
use App\Services\Bridge\BridgeGateway;
use Filament\Notifications\Notification;

trait ManagesBridgePairing
{
    private function ownedBridge(string $id): SiteBridge
    {
        abort_unless(SiteBridgeResource::canViewAny(), 403);
        return SiteBridgeResource::getEloquentQuery()->findOrFail($id);
    }
    public function generateCode(string $id): void
    {
        $bridge = $this->ownedBridge($id);
        try { $bridge->issuePairingCode(); }
        catch (\RuntimeException $e) { Notification::make()->title($e->getMessage())->warning()->send(); }
    }
    public function approveRequest(string $id): void
    {
        if (!$this->decideRequest($id, true)) return;
        Notification::make()->title('ESP approved')->body('It will connect automatically on its next attempt.')->success()->send();
    }
    public function rejectRequest(string $id): void
    {
        if (!$this->decideRequest($id, false)) return;
        Notification::make()->title('Request rejected')->body('The ESP has no forwarding access. Generate a new code to try again.')->success()->send();
    }
    private function decideRequest(string $id, bool $approve): bool
    {
        try { app(SiteBridgePairing::class)->decide($id, $approve); return true; }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 409) throw $e;
            Notification::make()->title('This request has expired or was already reviewed.')->warning()->send();
            return false;
        }
    }
    public function revokeBridge(string $id): void
    {
        $bridge = $this->ownedBridge($id);
        $bridge->forceFill(['revoked_at'=>now(), 'pairing_hash'=>null, 'pairing_code'=>null, 'pairing_expires_at'=>null])->save();
        $bridge->pairingRequests()->where('status','pending')->update(['status'=>'cancelled','decided_at'=>now(),'decided_by'=>(string)auth()->id()]);
        try { app(BridgeGateway::class)->revoke($bridge); } catch (\Throwable $e) {}
        Notification::make()->title('Bridge access revoked')->success()->send();
    }
}
