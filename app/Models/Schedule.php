<?php

namespace App\Models;

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

    public function getUsersCountAttribute()
    {
        if (! $this->target_type) {
            return User::count();
        }

        if ($this->target) {
            return $this->target->users()->count();
        }

        return 0;
    }
}
