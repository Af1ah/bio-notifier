<?php

// Run against the local PostgreSQL MVP with Laravel writers stopped.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (! config('desktop.enabled') || DB::getDriverName() !== 'pgsql' || $argc !== 2 || ! is_dir($argv[1])) {
    throw new RuntimeException('Provide an empty staging directory from the local PostgreSQL MVP.');
}

$company = Organisation::sole();
$name = $company->database()->getName();
if (! preg_match('/^tenant[a-zA-Z0-9_-]+$/D', $name)) {
    throw new RuntimeException('Unexpected tenant database name.');
}

function stageDatabase($source, string $path, ?string $migrations = null): void
{
    $file = fopen($path, 'x');
    if ($file === false) {
        throw new RuntimeException('SQLite staging requires a fresh destination.');
    }
    fclose($file);
    config(['database.connections.desktop_stage' => array_replace(config('database.connections.sqlite'), [
        'database' => $path, 'url' => null,
    ])]);
    $options = ['--database' => 'desktop_stage', '--force' => true];
    if ($migrations !== null) {
        $options['--path'] = $migrations;
    }
    if (Artisan::call('migrate', $options) !== 0) {
        throw new RuntimeException(Artisan::output());
    }

    $target = DB::connection('desktop_stage');
    $target->statement('PRAGMA foreign_keys = OFF');
    $target->transaction(function () use ($source, $target): void {
        foreach ($source->getSchemaBuilder()->getTableListing('public', false) as $table) {
            if ($table === 'migrations') {
                continue;
            }
            $count = $source->table($table)->count();
            $columns = $target->getSchemaBuilder()->getColumnListing($table);
            if ($columns === [] || array_diff($source->getSchemaBuilder()->getColumnListing($table), $columns)) {
                throw new RuntimeException("Schema mismatch for {$table}.");
            }
            try {
                // Replace any rows inserted by the fresh schema migrations.
                if ($target->table($table)->exists()) {
                    $target->table($table)->delete();
                }
                foreach ($source->table($table)->cursor() as $row) {
                    $target->table($table)->insert((array) $row);
                }
            } catch (Illuminate\Database\QueryException $error) {
                throw new RuntimeException("Copy failed for {$table} (SQLSTATE {$error->getCode()}).");
            }
            if ($target->table($table)->count() !== $count) {
                throw new RuntimeException("Row count mismatch for {$table}.");
            }
        }
        if ($target->select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('SQLite foreign key validation failed.');
        }
    });
    $target->statement('PRAGMA wal_checkpoint(TRUNCATE)');
    DB::purge('desktop_stage');
}

$central = DB::connection();
$tenantConfig = $central->getConfig();
$tenantConfig['database'] = $name;
config(['database.connections.desktop_source_tenant' => $tenantConfig]);
stageDatabase($central, $argv[1].'/central.sqlite');
stageDatabase(DB::connection('desktop_source_tenant'), $argv[1].'/'.$name, 'database/migrations/tenant');
echo $name.PHP_EOL;
