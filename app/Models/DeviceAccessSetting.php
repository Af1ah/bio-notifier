<?php

namespace App\Models;

use App\Casts\ProtectedArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAccessSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['desired' => ProtectedArray::class, 'reported' => ProtectedArray::class,
            'reported_at' => 'datetime', 'revision' => 'integer', 'submitted_revision' => 'integer',
            'number' => 'integer', 'binding_id' => 'integer', 'binding_version' => 'integer'];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'command_id', 'gateway_command_id');
    }
}
