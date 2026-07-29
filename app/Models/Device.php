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
}
