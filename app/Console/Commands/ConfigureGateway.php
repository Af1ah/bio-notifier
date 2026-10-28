<?php

namespace App\Console\Commands;

use App\Models\GatewayConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ConfigureGateway extends Command
{
    protected $signature = 'gateway:configure {file : Private JSON configuration file} {--connection= : Existing connection ID} {--rotate-secrets : Explicitly permit changing existing service tokens}';

    protected $description = 'Configure the shared gateway from an operator-owned file without printing secrets';

    public function handle(): int
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file) || filesize($file) > 16384) {
            $this->error('Provide a readable configuration file of at most 16 KB.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->error('Configuration must be valid JSON.');

            return self::FAILURE;
        }
        $keys = ['name', 'management_url', 'device_server_url', 'management_token', 'events_token', 'enabled'];
        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), $keys)) {
            $this->error('Configuration contains unsupported fields.');

            return self::FAILURE;
        }
        $validation = Validator::make($data, [
            'name' => 'required|string|max:255',
            'management_url' => ['required', 'url:http,https', Rule::in(config('device_gateway.allowed_origins'))],
            'device_server_url' => 'nullable|url:http,https|max:255',
            'management_token' => 'nullable|string|min:32|max:255',
            'events_token' => 'nullable|string|min:32|max:255',
            'enabled' => 'required|boolean',
        ]);
        if ($validation->fails()) {
            $this->error('Invalid configuration fields: '.implode(', ', array_keys($validation->errors()->messages())));

            return self::FAILURE;
        }
        if ($this->option('connection') && ! ctype_digit((string) $this->option('connection'))) {
            $this->error('Connection ID must be an integer.');

            return self::FAILURE;
        }
        $connection = $this->option('connection') ? GatewayConnection::find($this->option('connection')) : GatewayConnection::first();
        if (($this->option('connection') && ! $connection) || (! $this->option('connection') && GatewayConnection::count() > 1)) {
            $this->error('Select an existing connection with --connection.');

            return self::FAILURE;
        }
        $connection ??= new GatewayConnection;
        foreach (['management_token', 'events_token'] as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
            if (! filled($data[$key] ?? $connection->{$key})) {
                $this->error('Both service tokens are required for a new connection.');

                return self::FAILURE;
            }
            if ($connection->exists && isset($data[$key]) && ! hash_equals((string) $connection->{$key}, $data[$key]) && ! $this->option('rotate-secrets')) {
                $this->error('Changing service tokens requires --rotate-secrets and coordinated gateway configuration.');

                return self::FAILURE;
            }
        }
        DB::connection(config('tenancy.database.central_connection'))->transaction(function ($db) use ($connection, $data) {
            $connection->fill($data)->save();
            $db->table('gateway_audit_events')->insert(['action' => 'connection_configured_cli', 'subject' => (string) $connection->id,
                'metadata' => json_encode(['fields' => array_keys($data)]), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->info('Gateway configuration saved. Connection ID: '.$connection->id);

        return self::SUCCESS;
    }
}
