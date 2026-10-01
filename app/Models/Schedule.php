<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'name',
        'type',
        'target_type',
        'target_id',
        'valid_from',
        'valid_to',
        'status',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'rules' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function target()
    {
        return $this->morphTo();
    }

    public function ruleRevisions()
    {
        return $this->hasMany(ShiftRuleRevision::class);
    }

    public function latestRule()
    {
        return $this->hasOne(ShiftRuleRevision::class)->latestOfMany('revision');
    }

    public function activeRuleRevision(CarbonInterface $date): ?ShiftRuleRevision
    {
        return $this->ruleRevisions()
            ->where('effective_from', '<=', $date->copy()->endOfDay())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('revision')
            ->first();
    }

    public function getUsersCountAttribute()
    {
        if (! $this->target_type) {
            return User::employees()->count();
        }

        if ($this->target) {
            return $this->target->users()->employees()->count();
        }

        return 0;
    }
}
