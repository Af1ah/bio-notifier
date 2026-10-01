<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AttendanceRateLimiter
{
    public function ensure(string $action, int $max, int $decay = 60): void
    {
        $key = 'attendance:'.(tenant()?->getTenantKey() ?? 'none').':'.(auth()->id() ?? 'system').':'.$action;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([$action => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }RateLimiter::hit($key, $decay);
    }
}
