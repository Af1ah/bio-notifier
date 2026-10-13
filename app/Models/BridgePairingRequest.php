<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BridgePairingRequest extends Model
{
    use HasUuids;
    protected $guarded = ['id'];
    protected $hidden = ['public_key'];
    public function getConnectionName() { return config('tenancy.database.central_connection'); }
    protected function casts(): array { return ['expires_at'=>'datetime', 'decided_at'=>'datetime']; }
    public function bridge(): BelongsTo { return $this->belongsTo(SiteBridge::class, 'site_bridge_id'); }
    public function isPending(): bool { return $this->status === 'pending' && $this->expires_at->isFuture(); }
    public function displayStatus(): string { return $this->status === 'pending' && !$this->expires_at->isFuture() ? 'expired' : $this->status; }
    public function fingerprint(): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $this->public_key), true);
        return implode(':', str_split(substr(hash('sha256', $der ?: ''), 0, 32), 2));
    }
}
