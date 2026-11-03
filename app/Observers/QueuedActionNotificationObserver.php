<?php

namespace App\Observers;

use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\Support\QueuedActionNotifications;
use Illuminate\Database\Eloquent\Model;

final class QueuedActionNotificationObserver
{
    public function creating(Model $record): void
    {
        // Do not initialize a tenant session guard from central provisioning or queue startup.
        if (! array_key_exists('requested_by_user_id', $record->getAttributes()) && tenancy()->initialized) {
            $actor = auth('web')->user();
            if ($actor instanceof User && $actor->has_login) {
                $record->requested_by_user_id = $actor->id;
            }
        }
    }

    public function updating(Model $record): void
    {
        if ($record instanceof DeviceCommand && $record->isDirty('status') && $record->status === 'pending') {
            $record->notification_outcome = null;
            $this->creating($record);
        }
    }

    public function created(Model $record): void
    {
        $this->updated($record);
    }

    public function updated(Model $record): void
    {
        app(QueuedActionNotifications::class)->send($record);
    }
}
