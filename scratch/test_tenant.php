<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$t = App\Models\Organisation::create(['id' => 'foo123', 'name' => 'Foo Org', 'shortname' => 'foo']);
echo $t->id;
