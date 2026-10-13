<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $device_id
 * @property string $command_type
 * @property string $command_content
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $response
 * @property int $retry_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceCommand extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'outcome_unknown' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return 'device_commands';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function markAsAcknowledged(?string $response = null): void
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'response' => $response,
        ]);
    }

    public function markAsFailed(?string $response = null): void
    {
        $this->update([
            'status' => 'failed',
            'response' => $response,
        ]);
    }

    public function retry(): void
    {
        if ($this->device?->vendor === 'matrix' && $this->device?->connection_mode === 'remote'
            && ($this->outcome_unknown || $this->status === 'sent')
            && !preg_match('/^(INFO|CHECK|DATA QUERY)/', $this->command_content)) {
            throw new \RuntimeException('The previous outcome is unknown. Read the device state and reconcile it before retrying this write.');
        }
        $this->update([
            'status' => 'pending',
            'outcome_unknown' => false,
            'retry_count' => $this->retry_count + 1,
        ]);
    }
}
