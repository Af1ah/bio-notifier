<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['holiday_date' => 'date'];
    }

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }
}
