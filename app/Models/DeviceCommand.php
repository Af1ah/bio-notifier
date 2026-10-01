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
        $this->update([
            'status' => 'pending',
            'retry_count' => $this->retry_count + 1,
            'response' => null,
            'sent_at' => null,
            'acknowledged_at' => null,
        ]);

        if ($this->command_type === 'sync_users') {
            \App\Jobs\SyncEbioUsersJob::dispatch(tenancy()->tenant, $this->id);

            return;
        }

        if ($this->device && in_array($this->command_type, ['reboot', 'clear_logs', 'reset_transaction_stamp', 'reset_op_stamp', 'unlock_door'], true)) {
            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $this->device->serial_number, $this->command_type, $this->id);

            return;
        }

        $this->markAsFailed('This command type cannot be retried automatically.');
    }
}
