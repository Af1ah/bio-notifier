<?php

namespace App\Http\Middleware\Concerns;

trait ScopesTenantSession
{
    protected function scopeTenantSession(): void
    {
        // The same name must be used for page, login, logout and Livewire requests.
        // Keep the root path because Livewire posts to /livewire/update.
        config([
            'session.cookie' => 'bio-notifier-tenant-'.hash('sha256', (string) tenant('id')),
            'session.path' => '/',
        ]);
        app('session')->driver()->setName(config('session.cookie'));
    }
}
