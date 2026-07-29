<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($user) {
            if ($user->isDirty('password')) {
                $user->requires_password_change = false;
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_enabled' => 'boolean',
            'fingerprints' => 'array',
            'face_templates' => 'array',
            'requires_password_change' => 'boolean',
        ];
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class, 'pin', 'pin');
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function taskGroups()
    {
        return $this->belongsToMany(TaskGroup::class);
    }

    public function getPrivilegeLabelAttribute(): string
    {
        return match ((int) $this->privilege) {
            0 => 'User',
            14 => 'Admin',
            default => 'Unknown',
        };
    }

    public function getHasLoginAttribute(): bool
    {
        return (int) $this->privilege === 14
            && filled($this->email)
            && filled($this->password);
    }

    public function getActiveSchedule(?\Carbon\Carbon $date = null)
    {
        $date = $date ?? now();

        $groupSchedule = Schedule::where('status', true)
            ->where('target_type', TaskGroup::class)
            ->whereIn('target_id', $this->taskGroups()->pluck('task_groups.id'))
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            })
            ->first();

        if ($groupSchedule) return $groupSchedule;

        if ($this->department_id) {
            $deptSchedule = Schedule::where('status', true)
                ->where('target_type', Department::class)
                ->where('target_id', $this->department_id)
                ->where(function ($query) use ($date) {
                    $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
                })
                ->where(function ($query) use ($date) {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
                })
                ->first();
            
            if ($deptSchedule) return $deptSchedule;
        }

        if ($this->branch_id) {
            $branchSchedule = Schedule::where('status', true)
                ->where('target_type', Branch::class)
                ->where('target_id', $this->branch_id)
                ->where(function ($query) use ($date) {
                    $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
                })
                ->where(function ($query) use ($date) {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
                })
                ->first();
            
            if ($branchSchedule) return $branchSchedule;
        }

        return Schedule::where('status', true)
            ->whereNull('target_type')
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            })
            ->first();
    }
}
