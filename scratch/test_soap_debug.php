<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$org = App\Models\Organisation::first();
$service = new App\Services\EbioSoapService();
try {
    $res = $service->syncUsers($org);
    print_r($res);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
