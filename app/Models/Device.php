<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $serial_number
 * @property string|null $name
 * @property string|null $ip_address
 * @property string|null $model
 * @property string|null $firmware_version
 * @property string|null $push_version
 * @property string|null $device_type
 * @property string $status
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $last_sync_at
 * @property int $att_stamp
 * @property int $op_stamp
 * @property array|null $options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Device extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'options' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Device $device): void {
            if ($device->connection_mode !== 'remote') { $device->site_bridge_id = null; return; }
            try {
                app(\App\Services\Bridge\BridgeGateway::class)->approvedTarget($device);
            } catch (\RuntimeException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(['site_bridge_id'=>$e->getMessage()]);
            }
        });
    }

    public function getTable(): string
    {
        return 'devices';
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function pendingCommands(): HasMany
    {
        return $this->commands()->where('status', 'pending');
    }

    public function isOnline(): bool
    {
        if (! $this->last_activity_at) {
            return false;
        }

        $threshold = 10;

        return $this->last_activity_at->diffInMinutes(now()) < $threshold;
    }

    public function markAsOnline(): void
    {
        $this->update([
            'status' => 'online',
            'last_activity_at' => now(),
        ]);
    }

    public function markAsOffline(): void
    {
        $this->update([
            'status' => 'offline',
        ]);
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getEnrollmentMethods(): array
    {
        $methods = $this->options['enrollment_methods'] ?? null;
        if (is_array($methods) && ! empty($methods)) {
            return $methods;
        }

        if ($this->vendor === 'matrix') {
            return ['face', 'card', 'special_card'];
        }

        return ['finger', 'card'];
    }

    public function supportsEnrollmentMethod(string $method): bool
    {
        return in_array($method, $this->getEnrollmentMethods(), true);
    }
}
