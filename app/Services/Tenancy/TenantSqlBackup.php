<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

final class TenantSqlBackup
{
    public function create(): string
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Tenant context is required.');
        }

        $database = DB::connection('tenant');
        $config = $database->getConfig();
        $name = $database->getDatabaseName();
        $expectedName = tenant()->database()->getName();
        if ($database->getDriverName() === 'sqlite') {
            $expectedName = database_path($expectedName);
        }
        if ($name !== $expectedName) {
            throw new RuntimeException('The tenant database does not match its configured name.');
        }

        $process = match ($database->getDriverName()) {
            'sqlite' => new Process(['sqlite3', '-readonly', '-batch', '-bail', $this->sqlitePath($name), '.timeout 5000', '.dump']),
            'pgsql' => new Process(['pg_dump', '--format=plain', '--no-owner', '--no-privileges', '--no-password', '--lock-wait-timeout=5s'], env: [
                'PGDATABASE' => $name,
                'PGHOST' => (string) ($config['host'] ?? ''),
                'PGPORT' => (string) ($config['port'] ?? 5432),
                'PGUSER' => (string) ($config['username'] ?? ''),
                'PGPASSWORD' => (string) ($config['password'] ?? ''),
                'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
                'PGSERVICE' => false,
                'PGHOSTADDR' => false,
            ]),
            default => throw new RuntimeException('Unsupported database driver.'),
        };

        $file = tempnam(sys_get_temp_dir(), 'bio-sql-');
        if ($file === false) {
            throw new RuntimeException('Could not allocate backup file.');
        }

        $stream = fopen($file, 'wb');
        try {
            if ($stream === false) {
                throw new RuntimeException('Could not open backup file.');
            }

            $process->setTimeout(90)->start();
            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write backup file.');
                }
            }

            if (! $process->isSuccessful() || ftell($stream) <= 0 || ! fflush($stream)) {
                throw new RuntimeException('SQL dump failed.');
            }
        } catch (\Throwable $exception) {
            $process->stop(0);
            unlink($file);
            throw new RuntimeException('Could not generate tenant SQL backup.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        clearstatcache(true, $file);

        return $file;
    }

    private function sqlitePath(string $database): string
    {
        $path = realpath($database);
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException('Only file-backed SQLite databases can be exported.');
        }

        return $path;
    }
}
