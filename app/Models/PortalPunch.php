<?php

namespace App\Models;

use App\Casts\TenantWallTime;
use Illuminate\Database\Eloquent\Model;

class PortalPunch extends Model
{
    protected $guarded = [];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return ['punched_at' => TenantWallTime::class, 'evidence' => 'array', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $punch) {
            if ($punch->isDirty(['user_id', 'work_location_id', 'request_id', 'kind', 'punched_at', 'photo_path', 'evidence'])) {
                throw new \LogicException('Punch request evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Punch request evidence must be retained.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function attendanceLog()
    {
        return $this->belongsTo(AttendanceLog::class);
    }

    public function getPhotoUrlAttribute(): string
    {
        return route('tenant.punch-photo', ['tenant' => tenant('shortname'), 'punch' => $this->id]);
    }
}
