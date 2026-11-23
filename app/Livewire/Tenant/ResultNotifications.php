<?php

namespace App\Livewire\Tenant;

use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

class ResultNotifications extends DatabaseNotifications
{
    #[Locked]
    public string $seenAt;

    #[Locked]
    public array $seenIds = [];

    public function mount(): void
    {
        $this->seenAt = now()->toDateTimeString();
        $this->seenIds = $this->getNotificationsQuery()->where('created_at', $this->seenAt)->pluck('id')->all();
    }

    public function render(): View
    {
        $fresh = $this->getUnreadNotificationsQuery()->where(function ($query) {
            $query->where('created_at', '>', $this->seenAt)
                ->orWhere(fn ($query) => $query->where('created_at', $this->seenAt)->whereNotIn('id', $this->seenIds));
        })->reorder()->orderBy('created_at')->orderBy('id')->limit(20)->get();
        foreach ($fresh as $record) {
            $this->dispatch('notificationSent', notification: Notification::fromDatabase($record)->duration(6000)->toArray());
            $timestamp = $record->created_at->toDateTimeString();
            if ($timestamp !== $this->seenAt) {
                $this->seenAt = $timestamp;
                $this->seenIds = [];
            }
            $this->seenIds[] = $record->id;
        }

        return parent::render();
    }
}
