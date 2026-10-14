<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function revisions()
    {
        return $this->hasMany(LeavePolicyRevision::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
