<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftRuleRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'auto_checkout_enabled' => 'boolean', 'snapshot' => 'array'];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function weekdays()
    {
        return $this->hasMany(ShiftRuleWeekday::class);
    }

    public function breaks()
    {
        return $this->hasMany(ShiftBreak::class);
    }
}
