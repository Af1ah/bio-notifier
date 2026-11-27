<?php

namespace App\Console\Commands;

use App\Models\GatewayConnection;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Devices\DeviceIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ProvisionDesktop extends Command
{
    protected $signature = 'desktop:provision {--name=Local Company} {--email=admin@bio-notifier.local}';

    protected $description = 'Provision the single local company and its ADMS gateway';

    public function handle(): int
    {
        if (! config('desktop.enabled') || Organisation::where('shortname', '!=', 'company')->orWhereNull('shortname')->exists()) {
            $this->error('Desktop provisioning requires a dedicated desktop database.');

            return self::FAILURE;
        }

        $gateway = [
            'management_url' => config('desktop.gateway_management_url'),
            'device_server_url' => config('desktop.gateway_device_url'),
            'management_token' => config('desktop.gateway_management_token'),
            'events_token' => config('desktop.gateway_events_token'),
        ];
        $password = getenv('BIO_DESKTOP_INITIAL_PASSWORD');
        if (in_array(null, $gateway, true) || in_array('', $gateway, true)
            || ! filter_var($this->option('email'), FILTER_VALIDATE_EMAIL)
            || ! is_string($password) || strlen($password) < 16) {
            $this->error('Supply the local gateway settings and a strong initial administrator password.');

            return self::FAILURE;
        }

        $company = Organisation::firstOrCreate(['shortname' => 'company'], [
            'name' => $this->option('name'),
            'timezone' => config('app.timezone'),
            'device_integration' => DeviceIntegration::GATEWAY,
        ]);

        $company->run(function () use ($password): void {
            User::firstOrCreate(['email' => $this->option('email')], [
                'name' => 'Company Administrator',
                'pin' => (string) random_int(100000000, 999999999),
                'role' => 'admin',
                'privilege' => 14,
                'password' => Hash::make($password),
            ]);
        });

        GatewayConnection::updateOrCreate(['name' => 'Local ADMS'], $gateway + ['enabled' => true]);
        $this->info('Local company and ADMS connection are ready.');

        return self::SUCCESS;
    }
}
