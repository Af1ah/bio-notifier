<?php

namespace Tests\Feature;

use App\Jobs\SyncEbioUsersJob;
use App\Models\DeviceCommand;
use App\Models\Organisation;
use App\Services\EbioSoapService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class DeviceCommandTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(static fn () => true);
        try {
            Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        } finally {
            restore_error_handler();
        }
    }

    public function test_user_sync_is_tracked_as_a_server_command_until_completion(): void
    {
        $command = DeviceCommand::create([
            'device_id' => null,
            'command_type' => 'sync_users',
            'command_content' => 'eBioServer SOAP Command: sync users',
            'status' => 'pending',
        ]);
        $organisation = (new Organisation)->forceFill(['id' => 'tenant-id']);
        $tenancy = Mockery::mock(Tenancy::class);
        $tenancy->shouldReceive('initialize')->once()->with($organisation);
        $tenancy->shouldReceive('end')->once();
        $this->app->instance(Tenancy::class, $tenancy);
        $service = Mockery::mock(EbioSoapService::class);
        $service->shouldReceive('syncUsers')->once()->with($organisation)->andReturn(['synced' => 7, 'errors' => 0]);

        (new SyncEbioUsersJob($organisation, $command->id))->handle($service);

        $command->refresh();
        $this->assertSame('acknowledged', $command->status);
        $this->assertNotNull($command->sent_at);
        $this->assertNotNull($command->acknowledged_at);
        $this->assertSame('7 user(s) synced; 0 skipped.', $command->response);
    }

    public function test_user_sync_failure_is_visible_on_the_command(): void
    {
        $command = DeviceCommand::create([
            'device_id' => null,
            'command_type' => 'sync_users',
            'command_content' => 'eBioServer SOAP Command: sync users',
            'status' => 'pending',
        ]);
        $organisation = (new Organisation)->forceFill(['id' => 'tenant-id']);
        $tenancy = Mockery::mock(Tenancy::class);
        $tenancy->shouldReceive('initialize')->once()->with($organisation);
        $tenancy->shouldReceive('end')->once();
        $this->app->instance(Tenancy::class, $tenancy);
        $service = Mockery::mock(EbioSoapService::class);
        $service->shouldReceive('syncUsers')->once()->andThrow(new RuntimeException('Connection refused'));

        try {
            (new SyncEbioUsersJob($organisation, $command->id))->handle($service);
            $this->fail('The failed sync should be rethrown to Laravel queue handling.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection refused', $exception->getMessage());
        }

        $command->refresh();
        $this->assertSame('failed', $command->status);
        $this->assertSame('Connection refused', $command->response);
    }

    public function test_access_sync_distinguishes_acknowledgement_readback_failure_and_verified_sync(): void
    {
        $id = (string) Str::uuid();
        $write = DeviceCommand::create(['device_id' => null, 'command_type' => 'set_user_access_group', 'command_content' => 'Sync user access',
            'gateway_command_id' => $id, 'status' => 'acknowledged']);
        $this->assertSame('Acknowledged', $write->syncStatusLabel());
        $read = DeviceCommand::create(['device_id' => null, 'command_type' => 'query_users', 'command_content' => 'Read user',
            'gateway_command_id' => (string) Uuid::uuid5($id, 'user-readback'), 'status' => 'pending']);
        $write = DeviceCommand::with('accessReadback')->findOrFail($write->id);
        $this->assertSame('Waiting for readback', $write->syncStatusLabel());
        $read->update(['status' => 'failed']);
        $this->assertSame('Readback failed', $write->fresh()->syncStatusLabel());
        $this->assertSame('danger', $write->fresh()->syncStatusColor());
        $read->update(['status' => 'acknowledged', 'data_applied' => true]);
        $this->assertSame('Readback needs review', $write->fresh()->syncStatusLabel());
        $write->update(['data_applied' => true]);
        $this->assertSame('Synced', $write->fresh()->syncStatusLabel());
        $this->assertSame('success', $write->fresh()->syncStatusColor());
        $group = new DeviceCommand(['command_type' => 'set_access_group', 'status' => 'acknowledged']);
        $this->assertSame('Acknowledged', $group->syncStatusLabel());
    }
}
