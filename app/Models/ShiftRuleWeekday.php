<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftRuleWeekday extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function ruleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class, 'shift_rule_revision_id');
    }
}
