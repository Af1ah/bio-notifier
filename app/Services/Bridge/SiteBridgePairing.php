<?php
namespace App\Services\Bridge;

use App\Models\BridgePairingRequest;
use App\Models\SiteBridge;
use Illuminate\Support\Facades\DB;

class SiteBridgePairing
{
    public function decide(string $requestId, bool $approve): void
    {
        abort_unless(auth()->check() && ((int)auth()->user()->privilege === 14 || auth()->user()->role === 'admin'), 403);
        $tenantId = (string) tenant('id');
        abort_unless($tenantId !== '', 403);
        // Lock bridge before request: matches submission and code rotation lock order.
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($requestId, $approve, $tenantId) {
            $candidate = BridgePairingRequest::findOrFail($requestId);
            $bridge = SiteBridge::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($candidate->site_bridge_id);
            $request = BridgePairingRequest::where('site_bridge_id', $bridge->id)->lockForUpdate()->findOrFail($requestId);
            abort_unless(!$bridge->revoked_at && !$bridge->public_key && $request->isPending(), 409, 'This request is no longer awaiting approval.');
            $request->update(['status'=>$approve ? 'approved' : 'rejected', 'decided_at'=>now(), 'decided_by'=>(string)auth()->id()]);
            $changes = $approve ? ['pairing_code'=>null] : ['pairing_hash'=>null, 'pairing_code'=>null, 'pairing_expires_at'=>null];
            if ($approve) $changes += ['public_key'=>$request->public_key, 'hardware_id'=>$request->hardware_id];
            $bridge->forceFill($changes)->save();
        });
    }
}
