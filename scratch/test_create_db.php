<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\Organisation::first();
\Stancl\Tenancy\Jobs\CreateDatabase::dispatchSync($t);
echo "DB created manually\n";
