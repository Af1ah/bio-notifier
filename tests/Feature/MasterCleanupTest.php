<?php

namespace Tests\Feature;

use App\Filament\Master\Pages\Dashboard;
use App\Filament\Master\Pages\DeviceGateway;
use App\Filament\Master\Resources\Organisations\OrganisationResource;
use App\Filament\Master\Resources\Organisations\Pages\ListOrganisations;
use App\Jobs\Gateway\SyncGatewayBinding;
use App\Livewire\Master\TenantDevicesTable;
use App\Livewire\Master\TenantUsersTable;
use App\Models\Admin;
use App\Models\Device;
use App\Models\GatewayConnection;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Devices\Gateway\ProvisionGatewayBinding;
use App\Services\Devices\TenantDeviceRegistration;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MasterCleanupTest extends TestCase
{
    private array $companies = [];

    private GatewayConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite', 'queue.connections.gateway.connection' => 'sqlite']);
        $this->connection = GatewayConnection::create(['name' => 'Primary', 'management_url' => 'http://127.0.0.1:8081',
            'management_token' => str_repeat('m', 32), 'events_token' => str_repeat('e', 32), 'enabled' => true]);
        $admin = Admin::create(['name' => 'Master', 'email' => 'cleanup@test.local', 'password' => bcrypt('test-only'), 'role' => 'master']);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('master'));
        Filament::bootCurrentPanel();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::table('gateway_ingress_receipts')->delete();
        DB::table('gateway_binding_versions')->delete();
        DB::table('gateway_device_bindings')->delete();
        foreach ($this->companies as $company) {
            $company->delete();
        }
        parent::tearDown();
    }

    private function company(string $serial): array
    {
        $company = Organisation::create(['name' => $serial, 'shortname' => strtolower($serial), 'device_integration' => 'adms_gateway']);
        $this->companies[] = $company;
        $binding = app(ProvisionGatewayBinding::class)->provision(['organisation_id' => $company->id,
            'gateway_connection_id' => $this->connection->id, 'serial' => $serial, 'device_name' => $serial,
            'source_cidr' => '0.0.0.0/0', 'timezone' => 'Asia/Kolkata', 'enabled' => true, 'capabilities' => ['info']]);

        return [$company, $binding];
    }

    public function test_company_list_keeps_admins_and_magic_login_without_creating_grants_on_render(): void
    {
        [$company] = $this->company('KEEP01');
        $this->assertSame([], Filament::getPanel('master')->getWidgets());
        $component = Livewire::test(ListOrganisations::class)->assertCanSeeTableRecords([$company])
            ->assertTableActionExists('manage_admins')->assertTableActionExists('login_as_tenant')
            ->assertTableBulkActionDoesNotExist('delete');
        $component->assertSee('formtarget="_blank"', escape: false);
        $this->assertSame(0, DB::table('quick_login_grants')->count());
        $this->post(route('master.company-login', $company))->assertRedirect();
        $this->assertSame(1, DB::table('quick_login_grants')->count());
        $this->assertFalse(OrganisationResource::canDelete($company));
        $this->assertFalse(OrganisationResource::canDeleteAny());
        auth('admin')->logout();
        $this->post(route('master.company-login', $company))->assertForbidden();
    }

    public function test_retired_resources_and_duplicate_livewire_components_are_unavailable(): void
    {
        Livewire::test(Dashboard::class)->assertRedirect(OrganisationResource::getUrl('index'));
        $this->get('/master/adms-payloads')->assertNotFound();
        Livewire::test(TenantDevicesTable::class)->assertNotFound();
        Livewire::test(TenantUsersTable::class)->assertNotFound();
        $resources = Filament::getPanel('master')->getResources();
        $this->assertSame([OrganisationResource::class], $resources);
    }

    public function test_recovery_details_are_filtered_paginated_and_do_not_expose_payloads(): void
    {
        [$company, $binding] = $this->company('PAGES01');
        for ($i = 0; $i < 31; $i++) {
            GatewayIngressReceipt::create(['id' => (string) Str::uuid(), 'gateway_connection_id' => $this->connection->id,
                'binding_id' => $binding->id, 'organisation_id' => $company->id, 'device_id' => $binding->device_id,
                'binding_version' => 1, 'kind' => 'heartbeat', 'payload_hash' => hash('sha256', (string) $i),
                'payload' => ['private' => 'never-render-this-payload'], 'state' => $i === 30 ? 'applied' : 'parked']);
        }
        Http::fake(['*' => Http::response([], 200)]);
        $component = Livewire::test(DeviceGateway::class)->assertSet('detailsLoaded', false);
        Http::assertNothingSent();
        $component->call('loadDetails')->assertViewHas('receipts', fn ($rows) => $rows->total() === 30 && $rows->count() === 25)
            ->assertDontSee('never-render-this-payload')->call('changeReceiptPage', 2)
            ->assertViewHas('receipts', fn ($rows) => $rows->count() === 5)
            ->set('receiptState', 'applied')->call('loadDetails')
            ->assertViewHas('receipts', fn ($rows) => $rows->total() === 1);
    }

    public function test_connection_configuration_is_operator_only_and_preserves_secrets_without_explicit_rotation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gateway-config-');
        try {
            file_put_contents($file, json_encode(['name' => 'Deployment configured', 'management_url' => 'http://127.0.0.1:8081', 'enabled' => true]));
            $this->artisan('gateway:configure', ['file' => $file])->assertSuccessful();
            $this->assertSame('Deployment configured', $this->connection->fresh()->name);
            $this->assertSame(str_repeat('e', 32), $this->connection->fresh()->events_token);
            file_put_contents($file, json_encode(['name' => 'Changed', 'management_url' => 'http://127.0.0.1:8081', 'enabled' => true, 'events_token' => str_repeat('n', 32)]));
            $this->artisan('gateway:configure', ['file' => $file])->assertFailed();
            $this->assertSame(str_repeat('e', 32), $this->connection->fresh()->events_token);
            $this->artisan('gateway:configure', ['file' => $file, '--rotate-secrets' => true])->assertSuccessful();
            $this->assertSame(str_repeat('n', 32), $this->connection->fresh()->events_token);
            $this->assertStringNotContainsString(str_repeat('n', 32), DB::table('gateway_audit_events')->pluck('metadata')->implode(''));
        } finally {
            unlink($file);
        }
    }

    public function test_registration_repair_is_company_scoped_and_keeps_the_same_binding_version(): void
    {
        [$first, $binding] = $this->company('REPAIR01');
        [$second, $otherBinding] = $this->company('REPAIR02');
        tenancy()->initialize($second);
        $otherDevice = Device::firstOrFail();
        tenancy()->end();
        tenancy()->initialize($first);
        $admin = User::create(['name' => 'Company admin', 'pin' => 'admin', 'privilege' => 14, 'email' => 'repair@test.local', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        $binding->update(['last_error' => 'binding_sync_failed']);
        Queue::fake();
        $service = app(TenantDeviceRegistration::class);
        $this->assertTrue($service->canRepair(Device::firstOrFail()));
        $this->assertFalse($service->canRepair($otherDevice));
        $service->repair(Device::firstOrFail());
        $this->assertNull($binding->fresh()->last_error);
        $this->assertSame(1, $binding->fresh()->version);
        Queue::assertPushed(SyncGatewayBinding::class, fn ($job) => $job->bindingId === $binding->id);
        try {
            $service->repair($otherDevice);
            $this->fail('Another company registration was repaired.');
        } catch (ModelNotFoundException) {
            $this->assertSame($second->id, $otherBinding->fresh()->organisation_id);
        }
        tenancy()->end();
    }
}
