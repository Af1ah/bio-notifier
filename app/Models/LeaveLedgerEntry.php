<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveLedgerEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Leave evidence is immutable; use a reviewed reversal.'));
        static::deleting(fn () => throw new \LogicException('Leave evidence must be retained.'));
    }
}
