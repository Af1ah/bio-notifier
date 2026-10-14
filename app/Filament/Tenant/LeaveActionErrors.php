<?php

namespace App\Filament\Tenant;

use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class LeaveActionErrors
{
    public static function run(Schema $schema, callable $callback, string $fallback = 'reason'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$schema->getStatePath().'.'.($key === 'users' ? 'user_ids' : $key)] = $messages;
            }
            $errors[$schema->getStatePath().'.'.$fallback] = collect($exception->errors())->flatten()->all();
            throw ValidationException::withMessages($errors);
        }
    }

    public static function form(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
    }
}
