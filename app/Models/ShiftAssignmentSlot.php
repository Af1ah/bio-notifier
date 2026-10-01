<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftAssignmentSlot extends Model
{
    protected $guarded = [];

    public function assignmentSet()
    {
        return $this->belongsTo(ShiftAssignmentSet::class, 'shift_assignment_set_id');
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function ruleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class);
    }
}
