<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\Organisation::find('6a404f7f-6f5a-49ff-9d39-2244fe9f0a90');
\Stancl\Tenancy\Jobs\CreateDatabase::dispatchSync($t);
\Stancl\Tenancy\Jobs\MigrateDatabase::dispatchSync($t);
echo "DB created and migrated\n";
