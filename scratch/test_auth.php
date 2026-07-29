<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$guard = Filament\Facades\Filament::auth();
echo "Guard class: " . get_class($guard) . "\n";
echo "Attempt result: " . ($guard->attempt(['email' => 'ariise@gmail.com', 'password' => '987654']) ? 'true' : 'false') . "\n";
