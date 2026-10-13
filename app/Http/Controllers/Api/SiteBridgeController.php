<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BridgePairingRequest;
use App\Models\SiteBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteBridgeController extends Controller
{
    public function pair(Request $request)
    {
        $data = $request->validate(['code'=>'required|string|size:24','public_key'=>'required|string|max:1024','hardware_id'=>'required|string|max:64']);
        $key = openssl_pkey_get_public($data['public_key']);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($details && $details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1', 422, 'Expected a P-256 public key.');
        $data['public_key'] = $details['key'];
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($data) {
            $bridge = SiteBridge::where('pairing_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            abort_unless($bridge && !$bridge->revoked_at && $bridge->pairing_expires_at?->isFuture(), 403, 'Pairing code is expired or already used.');
            $status = 'approved';
            if ($bridge->public_key) {
                abort_unless($bridge->public_key === $data['public_key'] && $bridge->hardware_id === $data['hardware_id'], 403);
            } else {
                $pending = $bridge->pairingRequests()->where('status', 'pending')->where('expires_at', '>', now())->first();
                if ($pending) {
                    abort_unless($pending->public_key === $data['public_key'] && $pending->hardware_id === $data['hardware_id'], 403, 'Another ESP is already awaiting approval.');
                } else {
                    $bridge->pairingRequests()->where('status', 'pending')->update(['status'=>'expired']);
                    $bridge->pairingRequests()->create(['public_key'=>$data['public_key'],'hardware_id'=>$data['hardware_id'],'expires_at'=>now()->addMinutes(30)]);
                }
                $status = 'pending';
            }
            return response()->json(['bridge_id'=>$bridge->id,'websocket_url'=>preg_replace('/^https:/','wss:',config('bridge.public_url')).'/agent','status'=>$status]);
        });
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['bridge_id'=>'required|uuid','nonce'=>'required|string|size:64','signature'=>'required|string|max:256']);
        $bridge = SiteBridge::find($data['bridge_id']);
        abort_unless($bridge && !$bridge->revoked_at, 403);
        $pending = $bridge->public_key ? null : $bridge->pairingRequests()->where('status', 'pending')->where('expires_at', '>', now())->latest()->first();
        $publicKey = $bridge->public_key ?: $pending?->public_key;
        $sig = base64_decode($data['signature'], true);
        if ($sig !== false && strlen($sig) === 64) {
            $r = ltrim(substr($sig,0,32), "\0") ?: "\0"; $s = ltrim(substr($sig,32,32), "\0") ?: "\0";
            if (ord($r[0]) & 128) $r = "\0".$r;
            if (ord($s[0]) & 128) $s = "\0".$s;
            $sig = "\x30".chr(4+strlen($r)+strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        }
        $message = "biomatrix-bridge-v1\n{$data['bridge_id']}\n{$data['nonce']}";
        abort_unless($publicKey && $sig && openssl_verify($message,$sig,$publicKey,OPENSSL_ALGO_SHA256) === 1, 403);
        if ($pending) return response()->json(['status'=>'pending'], 409);
        $bridge->forceFill(['last_seen_at'=>now()])->save();
        return response()->json(['bridge_id'=>$bridge->id,'tenant_id'=>$bridge->tenant_id]);
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate(['bridge_id'=>'required|uuid']);
        $bridge = SiteBridge::find($data['bridge_id']);
        abort_unless($bridge && $bridge->public_key && !$bridge->revoked_at, 403);
        $bridge->forceFill(['last_seen_at'=>now()])->save();
        return response()->json(['ok'=>true]);
    }
}
