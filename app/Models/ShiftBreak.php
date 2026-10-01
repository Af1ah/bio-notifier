<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftBreak extends Model
{
    protected $guarded = [];

    public function ruleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class, 'shift_rule_revision_id');
    }
}
