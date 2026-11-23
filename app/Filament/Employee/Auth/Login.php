<?php

namespace App\Filament\Employee\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Schemas\Components\Component;

class Login extends \Filament\Auth\Pages\Login
{
    public function getHeading(): string
    {
        return 'Employee sign in';
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->minLength(6);
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();
        if ($response) {
            session()->forget('url.intended');
        }

        return $response;
    }
}
