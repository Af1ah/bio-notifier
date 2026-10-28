<?php

namespace App\Models;

use App\Jobs\EbioDeviceCommandJob;
use App\Jobs\SyncEbioUsersJob;
use App\Services\Devices\DeviceAccessSettings;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\Gateway\GatewayCommands;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

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
        'data_receipt_ids' => 'array', 'data_applied' => 'boolean',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'gateway_result_at' => 'datetime',
    ];

    public function actionLabel(): string
    {
        return GatewayCommands::OPERATIONS[$this->command_type]
            ?? str($this->command_type)->replace('_', ' ')->title()->toString();
    }

    public function getAccessReadbackIdAttribute(): ?string
    {
        return in_array($this->command_type, ['set_user_access_group', 'set_user_validity'], true) && Str::isUuid($this->gateway_command_id ?? '')
            ? (string) Uuid::uuid5($this->gateway_command_id, 'user-readback') : null;
    }

    public function accessReadback(): HasOne
    {
        return $this->hasOne(self::class, 'gateway_command_id', 'access_readback_id')->where('command_type', 'query_users');
    }

    public function syncStatusLabel(): string
    {
        if (! in_array($this->command_type, [...DeviceAccessSettings::OPERATIONS, 'set_user_validity'], true)) {
            return '—';
        }
        $state = match ($this->status) {
            'pending' => 'Queued', 'sent' => str_starts_with($this->command_type, 'query_') ? 'Waiting for device data' : 'Waiting for acknowledgement',
            'failed' => 'Failed', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'outcome_unknown' => 'Needs review', default => null,
        };
        if ($state !== null) {
            return $state;
        }
        if ($this->status !== 'acknowledged') {
            return 'Waiting for device';
        }
        if (! in_array($this->command_type, ['set_user_access_group', 'set_user_validity'], true)) {
            return str_starts_with($this->command_type, 'query_')
                ? ($this->data_applied ? 'Data saved' : 'Waiting for saved data') : 'Acknowledged';
        }
        if ($this->data_applied) {
            return 'Synced';
        }
        $read = $this->accessReadback;
        if (! $read || $read->device_id !== $this->device_id) {
            return 'Acknowledged';
        }
        if (in_array($read->status, ['failed', 'expired', 'cancelled', 'outcome_unknown'], true)) {
            return 'Readback '.match ($read->status) {
                'expired' => 'expired', 'cancelled' => 'cancelled', 'outcome_unknown' => 'needs review', default => 'failed',
            };
        }

        return $read->data_applied ? ($this->command_type === 'set_user_validity' ? 'Verify on terminal' : 'Readback needs review') : 'Waiting for readback';
    }

    public function syncStatusColor(): string
    {
        return match ($this->syncStatusLabel()) {
            'Synced', 'Data saved' => 'success', 'Failed', 'Readback failed', 'Readback needs review', 'Needs review' => 'danger',
            'Expired', 'Readback expired', 'Cancelled', 'Readback cancelled', '—' => 'gray', 'Acknowledged' => 'info', default => 'warning',
        };
    }

    public function completionLabel(): string
    {
        $read = in_array($this->command_type, ['query_biodata', 'query_users', 'info', 'query_access_groups', 'query_access_periods'], true) && $this->gateway_command_id;
        if ($read && ($this->data_applied || $this->status === 'acknowledged')) {
            return $this->data_applied ? 'Data saved; inventory completeness unconfirmed' : ($this->data_receipt_ids ? 'Device data received; saving pending' : 'Device confirmed; no saved data');
        }

        return match ($this->status) {
            'acknowledged' => 'Device confirmed', 'pending' => 'Queued', 'sent' => 'Sent / awaiting response', default => str($this->status)->replace('_', ' ')->title()->toString()
        };
    }

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
        DeviceIntegration::requireEbio(tenant());

        $this->update([
            'status' => 'pending',
            'retry_count' => $this->retry_count + 1,
            'response' => null,
            'sent_at' => null,
            'acknowledged_at' => null,
        ]);

        if ($this->command_type === 'sync_users') {
            SyncEbioUsersJob::dispatch(tenancy()->tenant, $this->id);

            return;
        }

        if ($this->device && in_array($this->command_type, ['reboot', 'clear_logs', 'reset_transaction_stamp', 'reset_op_stamp', 'unlock_door'], true)) {
            EbioDeviceCommandJob::dispatch(tenancy()->tenant, $this->device->serial_number, $this->command_type, $this->id);

            return;
        }

        $this->markAsFailed('This command type cannot be retried automatically.');
    }
}
