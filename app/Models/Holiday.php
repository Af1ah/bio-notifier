<?php

namespace App\Models;

use App\Services\Attendance\HolidayOccurrenceService;
use App\Services\Attendance\HolidayAttendanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Holiday extends Model
{
    protected $guarded = [];

    protected array $deletedOccurrenceDates = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'monthly_weeks' => 'array', 'active' => 'boolean'];
    }

    public function occurrences()
    {
        return $this->hasMany(HolidayOccurrence::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'holiday_branch_coverage');
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'holiday_department_coverage');
    }

    public function taskGroups()
    {
        return $this->belongsToMany(TaskGroup::class, 'holiday_task_group_coverage');
    }

    protected static function booted(): void
    {
        static::saved(fn (Holiday $holiday) => app(HolidayOccurrenceService::class)->sync($holiday));
        static::deleting(function (Holiday $holiday) {
            $holiday->deletedOccurrenceDates = $holiday->occurrences()->pluck('holiday_date')->all();
        });
        static::deleted(function (Holiday $holiday) {
            $dates = $holiday->deletedOccurrenceDates;
            DB::afterCommit(fn () => app(HolidayAttendanceService::class)->reconcile($dates));
        });
    }
}
