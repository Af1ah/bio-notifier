<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftAssignmentSet extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'active' => 'boolean'];
    }

    public function slots()
    {
        return $this->hasMany(ShiftAssignmentSlot::class)->orderBy('position');
    }

    public function dailyPolicyRuleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class, 'daily_policy_rule_revision_id');
    }
}
