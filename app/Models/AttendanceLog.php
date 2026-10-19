<?php

namespace App\Models;

use App\Jobs\RefreshAttendanceDay;
use App\Jobs\SendPunchNotification;
use App\Services\Payroll\PayrollFreshnessService;
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
            if (! $user) {
                return;
            }
            $days = AttendanceDay::where('user_id', $user->id)->where(function ($query) use ($log) {
                $query->whereDate('work_date', $log->punched_at->toDateString())
                    ->orWhereHas('occurrences', fn ($q) => $q->where('window_starts_at', '<=', $log->punched_at)->where('window_ends_at', '>=', $log->punched_at));
            })->get();
            foreach ($days as $day) {
                $day->update(['stale_at' => now()]);
                app(PayrollFreshnessService::class)->invalidate($user->id, $day->work_date);
                if (tenancy()->initialized) {
                    RefreshAttendanceDay::dispatch((string) tenant('id'), $user->id, $day->work_date->toDateString())->afterCommit();
                }
            }
            if (tenancy()->initialized && $user->whatsapp_number) {
                SendPunchNotification::dispatch((string) tenant('id'), $log->id)->afterCommit();
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
        return match (is_numeric($this->status) ? (int) $this->status : null) {
            0 => 'Check In',
            1 => 'Check Out',
            2 => 'Break Out',
            3 => 'Break In',
            4 => 'OT In',
            5 => 'OT Out',
            255 => 'INOUT',
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
