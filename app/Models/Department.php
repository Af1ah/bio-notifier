<?php

namespace App\Models;

use App\Services\EmployeePortal\DepartmentReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Department extends Model
{
    protected $fillable = [
        'organisation_id',
        'branch_id',
        'name',
        'manager_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $department) {
            if (! $department->isDirty('manager_id')) {
                return;
            }

            abort_unless(DepartmentReview::canAssign(), 403);
            if ($department->manager_id !== null
                && ! User::employees()->find($department->manager_id)?->canAccessEmployeePortal()) {
                throw ValidationException::withMessages(['data.manager_id' => __('department-review.eligible')]);
            }
        });
    }

    public function manager()
    {
        return $this->belongsTo(User::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class);
    }
}
