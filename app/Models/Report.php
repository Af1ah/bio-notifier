<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'name',
        'type',
        'date_range',
        'group_by',
        'filters',
        'user_ids',
        'is_template',
        'status',
        'last_calculated_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'user_ids' => 'array',
        'is_template' => 'boolean',
        'last_calculated_at' => 'datetime',
    ];
}
