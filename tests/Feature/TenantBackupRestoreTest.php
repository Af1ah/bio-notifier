<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantBackupRestoreTest extends TestCase
{
    public function test_disposable_tenant_can_be_backed_up_destroyed_and_restored_without_touching_sibling(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $tenants = [];
        $backup = tempnam(sys_get_temp_dir(), 'bio-backup-test-');
        $assetBackup = tempnam(sys_get_temp_dir(), 'bio-asset-test-');
        $assetPaths = [];
        try {
            $a = Organisation::create(['name' => 'Disposable A', 'shortname' => 'restore-a']);
            $b = Organisation::create(['name' => 'Disposable B', 'shortname' => 'restore-b']);
            $tenants = [$a, $b];
            $central = DB::connection('sqlite');
            $organisationRow = (array) $central->table('organisations')->where('id', $a->id)->first();
            $domainRows = $central->table('domains')->where('tenant_id', $a->id)->get()->map(fn ($row) => (array) $row)->all();

            tenancy()->initialize($a);
            $user = User::create(['name' => 'Restored administrator', 'pin' => 'same-pin', 'email' => 'a@example.test', 'password' => 'test-only-password', 'privilege' => 14]);
            $userId = $user->id;
            Storage::disk('local')->put('restore-test.txt', 'tenant-a-private-asset');
            $assetPaths[] = Storage::disk('local')->path('restore-test.txt');
            copy($assetPaths[0], $assetBackup);
            // SQLite VACUUM INTO produces a consistent backup, not a live-file
            // copy that could omit uncheckpointed WAL transactions.
            $pdo = DB::connection('tenant')->getPdo();
            $pdo->exec('VACUUM INTO '.$pdo->quote($backup));
            $tenantDatabase = config('database.connections.tenant.database');
            tenancy()->end();

            tenancy()->initialize($b);
            User::create(['name' => 'Sibling administrator', 'pin' => 'same-pin', 'email' => 'b@example.test', 'password' => 'sibling-password', 'privilege' => 14]);
            $this->assertDatabaseMissing('users', ['email' => 'a@example.test']);
            tenancy()->end();

            // Only the tenant constructed above is deleted. No supplied IDs,
            // deployment connections, or existing customer files are accepted.
            $a->delete();
            $this->assertFileDoesNotExist($tenantDatabase);
            $this->assertNull(Organisation::find($a->id));
            unlink($assetPaths[0]);
            $this->assertFileDoesNotExist($assetPaths[0]);

            copy($backup, $tenantDatabase);
            $central->table('organisations')->insert($organisationRow);
            if ($domainRows) {
                $central->table('domains')->insert($domainRows);
            }
            $restored = Organisation::findOrFail($a->id);
            $tenants[0] = $restored;
            tenancy()->initialize($restored);
            copy($assetBackup, Storage::disk('local')->path('restore-test.txt'));
            $restoredUser = User::findOrFail($userId);
            $this->assertTrue(Hash::check('test-only-password', $restoredUser->password));
            $this->assertTrue($restoredUser->has_login);
            $this->assertSame('tenant-a-private-asset', Storage::disk('local')->get('restore-test.txt'));
            $this->assertDatabaseMissing('users', ['email' => 'b@example.test']);
            $this->assertSame($organisationRow['ebio_webhook_token'], $restored->ebio_webhook_token);
            tenancy()->end();

            tenancy()->initialize($b);
            $this->assertTrue(Hash::check('sibling-password', User::where('email', 'b@example.test')->firstOrFail()->password));
            $this->assertDatabaseMissing('users', ['email' => 'a@example.test']);
            tenancy()->end();
        } finally {
            tenancy()->end();
            foreach ($tenants as $tenant) {
                if (Organisation::find($tenant->id)) {
                    $tenant->delete();
                }
            }
            foreach (array_merge([$backup, $assetBackup], $assetPaths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
