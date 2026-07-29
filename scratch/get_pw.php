<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Models\Tenant::first();
tenancy()->initialize($tenant);
echo "PW: " . \App\Models\Device::where('ip_address', '192.168.1.50')->value('password') . "\n";
