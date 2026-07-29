<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = [
        'organisation_id',
        'branch_id',
        'name',
    ];

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
