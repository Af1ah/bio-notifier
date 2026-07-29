<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $device_id
 * @property string $pin
 * @property Carbon $punched_at
 * @property int $status
 * @property int $verify_type
 * @property int|null $work_code
 * @property string|null $reserved_1
 * @property string|null $reserved_2
 * @property array|null $raw_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceLog extends Model
{
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($log) {
            $user = $log->user;
            if ($user && !empty($user->whatsapp_number)) {
                $status = $log->status_label;
                $time = $log->punched_at->format('Y-m-d H:i:s');
                $message = "Hello {$user->name}, your attendance punch ({$status}) at {$time} has been recorded.";
                
                // Dispatch to a background job or handle immediately?
                // For now, handle it immediately or use a lightweight background task.
                try {
                    app(\App\Services\WhatsAppService::class)->sendMessage($user->whatsapp_number, $message);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('WhatsApp notification failed: ' . $e->getMessage());
                }
            }
        });
    }

    protected $casts = [
        'punched_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function getTable(): string
    {
        return 'attendance_logs';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pin', 'pin');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            0 => 'Check In',
            1 => 'Check Out',
            2 => 'Break Out',
            3 => 'Break In',
            4 => 'OT In',
            5 => 'OT Out',
            default => 'Unknown',
        };
    }

    public function getVerifyTypeLabelAttribute(): string
    {
        return match ($this->verify_type) {
            0 => 'Password',
            1 => 'Fingerprint',
            2 => 'Card',
            15 => 'Face',
            default => 'Unknown',
        };
    }
}
