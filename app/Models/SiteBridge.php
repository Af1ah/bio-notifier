<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class SiteBridge extends Model {
    use HasUuids;
    protected $guarded = ['id', 'tenant_id', 'public_key', 'hardware_id', 'pairing_hash', 'pairing_code', 'pairing_expires_at', 'revoked_at', 'last_seen_at'];
    protected $hidden = ['public_key', 'pairing_hash', 'pairing_code'];
    protected function casts(): array { return ['pairing_code'=>'encrypted','pairing_expires_at'=>'datetime','last_seen_at'=>'datetime','revoked_at'=>'datetime']; }
    public function getConnectionName() { return config('tenancy.database.central_connection'); }
    public function pairingRequests(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(BridgePairingRequest::class); }
    public function isOnline(): bool { return !$this->revoked_at && $this->last_seen_at && $this->last_seen_at->gt(now()->subSeconds(75)); }
    public function activePairingCode(): ?string {
        if ($this->public_key || $this->revoked_at || !$this->pairing_expires_at?->isFuture() || !$this->pairing_code) return null;
        return hash_equals((string)$this->pairing_hash,hash('sha256',$this->pairing_code)) ? $this->pairing_code : null;
    }
    public function issuePairingCode(): string {
        return \Illuminate\Support\Facades\DB::connection($this->getConnectionName())->transaction(function () {
            $bridge = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($bridge->public_key || $bridge->revoked_at) throw new \RuntimeException('Add a new bridge to pair replacement hardware.');
            if ($bridge->pairingRequests()->where('status','pending')->where('expires_at','>',now())->exists()) throw new \RuntimeException('Approve or reject the waiting request before generating another code.');
            if ($code = $bridge->activePairingCode()) return $code;
            $bridge->pairingRequests()->where('status','pending')->update(['status'=>'expired']);
            $code = bin2hex(random_bytes(12));
            $bridge->forceFill(['pairing_hash'=>hash('sha256',$code),'pairing_code'=>$code,'pairing_expires_at'=>now()->addMinutes(15)])->save();
            return $code;
        });
    }
}
