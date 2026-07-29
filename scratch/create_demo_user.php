<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\Organisation::find('6a404f7f-6f5a-49ff-9d39-2244fe9f0a90');
tenancy()->initialize($t);

App\Models\User::create([
    'name' => 'Demo Admin',
    'email' => 'admin@demo.com',
    'password' => bcrypt('password'),
    'role' => 'admin'
]);
echo "Tenant user created\n";
