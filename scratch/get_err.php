<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = \App\Models\Organisation::first();
tenancy()->initialize($tenant);
$cmd = \App\Models\DeviceCommand::latest('id')->first();
echo "Status: " . $cmd->status . "\nResponse: " . $cmd->response . "\nContent: " . $cmd->command_content . "\n";
