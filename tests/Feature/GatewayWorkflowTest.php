<?php

namespace Tests\Feature;

use App\Filament\Master\Pages\DeviceGateway;
use App\Filament\Tenant\Resources\DeviceResource\Pages\ListDevices;
use App\Jobs\Gateway\ApplyDomainEffect;
use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Jobs\Gateway\SyncGatewayBinding;
use App\Models\Admin;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\GatewayBindingVersion;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\Gateway\GatewayClient;
use App\Services\Devices\Gateway\ProvisionGatewayBinding;
use App\Services\Devices\Gateway\ReceiptApplier;
use App\Services\Devices\TenantDeviceRegistration;
use App\Services\Payroll\PayrollFreshnessService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GatewayWorkflowTest extends TestCase
{
    private array $organisations = [];

    private GatewayConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->connection = GatewayConnection::create(['name' => 'Test', 'management_url' => 'http://127.0.0.1:8081',
            'management_token' => str_repeat('m', 32), 'events_token' => str_repeat('e', 32), 'enabled' => true]);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite', 'queue.connections.gateway.connection' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->organisations as $organisation) {
            DB::table('gateway_ingress_receipts')->where('organisation_id', $organisation->id)->delete();
            DB::table('gateway_command_submissions')->where('organisation_id', $organisation->id)->delete();
            GatewayBindingVersion::where('organisation_id', $organisation->id)->delete();
            DB::table('gateway_device_bindings')->where('organisation_id', $organisation->id)->delete();
            $organisation->delete();
        }
        parent::tearDown();
    }

    private function company(string $serial): array
    {
        $organisation = Organisation::create(['name' => 'Test '.$serial, 'shortname' => strtolower($serial)]);
        $this->organisations[] = $organisation;
        $binding = app(ProvisionGatewayBinding::class)->provision([
            'gateway_connection_id' => $this->connection->id, 'organisation_id' => $organisation->id,
            'serial' => $serial, 'device_name' => $serial, 'source_cidr' => '192.168.10.0/24', 'timezone' => 'Asia/Kolkata',
            'enabled' => true, 'capabilities' => ['info', 'check', 'query_users'],
        ]);
        $this->assertSame(1, $binding->version);
        $binding->update(['synced_at' => now()]);
        GatewayBindingVersion::create(['binding_id' => $binding->id, 'version' => 1, 'organisation_id' => $organisation->id,
            'device_id' => $binding->device_id, 'gateway_connection_id' => $this->connection->id,
            'source_cidr' => $binding->source_cidr, 'activated_at' => now()]);
        tenancy()->initialize($organisation);
        User::create(['name' => 'Payroll employee', 'pin' => '0007']);
        tenancy()->end();

        return [$organisation, $binding];
    }

    private function event(string $serial): array
    {
        return ['id' => (string) Str::uuid(), 'serial' => $serial, 'binding_version' => 1,
            'source_ip' => '192.168.10.5', 'received_at' => now()->toIso8601String(), 'kind' => 'attendance',
            'punches' => [['pin' => '0007', 'at' => '2026-10-03T09:00:00+05:30', 'status' => 0, 'verify' => 15, 'work_code' => 0]]];
    }

    private function deliver(array $event)
    {
        return $this->withToken(str_repeat('e', 32))->postJson('/api/internal/v1/device-events', $event);
    }

    public function test_queue_signals_are_deduplicated_while_waiting_reserved_and_released(): void
    {
        $id = (string) Str::uuid();
        $queue = Queue::connection('gateway');
        for ($i = 0; $i < 5; $i++) {
            $queue->push(new ApplyGatewayReceipt($id), '', 'device-ingest');
        }
        $this->assertSame(1, DB::table('jobs')->count());
        $job = $queue->pop('device-ingest');
        $this->assertNotNull($job);
        $queue->push(new ApplyGatewayReceipt($id), '', 'device-ingest');
        $this->assertSame(1, DB::table('jobs')->count());
        $job->release(120);
        $availableAt = DB::table('jobs')->value('available_at');
        $queue->push(new ApplyGatewayReceipt($id), '', 'device-ingest');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame($availableAt, DB::table('jobs')->value('available_at'));
        $this->assertSame('receipt:'.$id, DB::table('jobs')->value('gateway_signal_key'));
        DB::table('jobs')->delete();
        $queue->push(new ApplyGatewayReceipt($id), '', 'device-ingest');
        $this->assertSame(1, DB::table('jobs')->count());
        $queue->push(new ApplyDomainEffect('company-a', 1), '', 'attendance-live');
        $queue->push(new ApplyDomainEffect('company-b', 1), '', 'attendance-live');
        $this->assertSame(3, DB::table('jobs')->count());
        Queue::connection('database')->push(new ApplyGatewayReceipt($id), '', 'default');
        $this->assertSame(1, DB::table('jobs')->whereNull('gateway_signal_key')->count());
    }

    public function test_service_authentication_ownership_bounds_and_immutable_duplicate_acceptance(): void
    {
        [, $binding] = $this->company('AUTH01');
        $event = $this->event('AUTH01');
        $this->postJson('/api/internal/v1/device-events', $event)->assertUnauthorized();
        $this->deliver($event)->assertAccepted()->assertJson(['accepted' => true, 'id' => $event['id']]);
        $this->deliver($event)->assertAccepted();
        $this->assertSame(1, GatewayIngressReceipt::count());
        $this->assertStringNotContainsString('0007', DB::table('gateway_ingress_receipts')->value('payload'));
        $changed = $event;
        $changed['punches'][0]['status'] = 1;
        $this->deliver($changed)->assertConflict();
        $changed['id'] = (string) Str::uuid();
        $changed['source_ip'] = '10.0.0.1';
        $this->deliver($changed)->assertUnprocessable();
        $changed['source_ip'] = '192.168.10.5';
        $changed['binding_version'] = 2;
        $this->deliver($changed)->assertUnprocessable();
        $changed['binding_version'] = 1;
        $changed['punches'][0]['pin'] = 7;
        $this->deliver($changed)->assertUnprocessable();
        $this->assertSame(1, GatewayIngressReceipt::count());
    }

    public function test_overlapping_tenant_device_ids_and_pins_cannot_cross_company_boundaries(): void
    {
        [$first, $a] = $this->company('COMPANYA');
        [$second, $b] = $this->company('COMPANYB');
        $this->assertSame($a->device_id, $b->device_id);
        $event = $this->event('COMPANYA');
        $event['organisation_id'] = $second->id; // This value must never select the owner.
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        $this->assertFalse(tenancy()->initialized);
        tenancy()->initialize($first);
        $this->assertSame(1, AttendanceLog::count());
        $log = AttendanceLog::first();
        $this->assertSame('0007', $log->pin);
        $this->assertSame('2026-10-03 09:00:00', $log->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('gateway_domain_effects')->count());
        tenancy()->end();
        tenancy()->initialize($second);
        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(0, DB::table('gateway_applied_receipts')->count());
        tenancy()->end();
    }

    public function test_replay_after_tenant_commit_and_duplicate_upload_do_not_repeat_punch_effects(): void
    {
        [$organisation] = $this->company('REPLAY01');
        $event = $this->event('REPLAY01');
        $this->deliver($event)->assertAccepted();
        $job = new ApplyGatewayReceipt($event['id']);
        $job->handle(app(ReceiptApplier::class));
        GatewayIngressReceipt::findOrFail($event['id'])->update(['state' => 'pending']);
        $job->handle(app(ReceiptApplier::class));
        $duplicate = $event;
        $duplicate['id'] = (string) Str::uuid();
        $this->deliver($duplicate)->assertAccepted();
        (new ApplyGatewayReceipt($duplicate['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame(2, DB::table('gateway_attendance_evidence')->count());
        $this->assertSame(2, DB::table('gateway_applied_receipts')->count());
        $this->assertSame(1, DB::table('gateway_domain_effects')->count());
        tenancy()->end();
    }

    public function test_submission_acceptance_does_not_claim_device_ack_and_terminal_events_are_monotonic(): void
    {
        [$organisation] = $this->company('COMMAND01');
        tenancy()->initialize($organisation);
        $command = app(DeviceCommandService::class)->request(Device::firstOrFail(), 'info');
        $id = $command->gateway_command_id;
        $this->assertSame(1, DB::table('gateway_command_outbox')->count());
        tenancy()->end();
        $this->assertSame([], GatewayCommandSubmission::findOrFail($id)->parameters);
        Http::fake(['*' => Http::response(['id' => $id, 'wire_id' => 7, 'state' => 'queued'])]);
        (new SubmitGatewayCommand($id))->handle(app(GatewayClient::class));
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains($request->body(), '"parameters":{}'));
        $this->assertSame('submitted', GatewayCommandSubmission::findOrFail($id)->state);
        tenancy()->initialize($organisation);
        $this->assertSame('pending', $command->fresh()->status);
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'COMMAND01', 'binding_version' => 1, 'kind' => 'command_result',
            'received_at' => '2026-10-03T10:00:00+05:30', 'command_id' => $id, 'command_state' => 'acknowledged'];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        $event['id'] = (string) Str::uuid();
        $event['received_at'] = '2026-10-03T10:01:00+05:30';
        $event['command_state'] = 'delivered';
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $this->assertSame('acknowledged', $command->fresh()->status);
        tenancy()->end();
    }

    public function test_gateway_user_mutation_commands_keep_their_typed_parameters(): void
    {
        [$organisation, $binding] = $this->company('REMOTEUSER01');
        $binding->update(['capabilities' => ['info', 'check', 'query_users', 'query_biodata', 'upsert_user', 'delete_user', 'update_biodata']]);
        tenancy()->initialize($organisation);
        $device = Device::firstOrFail();
        $commands = app(DeviceCommandService::class);
        $upsert = $commands->request($device, 'upsert_user', ['pin' => '0007', 'name' => 'Payroll employee', 'privilege' => '14', 'card' => '']);
        $delete = $commands->request($device, 'delete_user', ['pin' => '0007']);
        $biometric = $commands->request($device, 'update_biodata', ['pin' => '0007', 'no' => '4', 'type' => '1', 'tmp' => 'AwAAAAEAAABCAAA=']);
        $fetch = $commands->request($device, 'query_biodata', ['pin' => '0007', 'type' => 'finger']);
        $upsertParameters = json_decode((string) DB::table('gateway_command_outbox')->where('id', $upsert->gateway_command_id)->value('parameters'), true);
        $deleteParameters = json_decode((string) DB::table('gateway_command_outbox')->where('id', $delete->gateway_command_id)->value('parameters'), true);
        $biometricParameters = json_decode((string) DB::table('gateway_command_outbox')->where('id', $biometric->gateway_command_id)->value('parameters'), true);
        $fetchParameters = json_decode((string) DB::table('gateway_command_outbox')->where('id', $fetch->gateway_command_id)->value('parameters'), true);
        $this->assertSame(['pin' => '0007', 'name' => 'Payroll employee', 'privilege' => '14', 'card' => ''], $upsertParameters);
        $this->assertSame(['pin' => '0007'], $deleteParameters);
        $this->assertSame(['pin' => '0007', 'no' => '4', 'type' => '1', 'tmp' => 'AwAAAAEAAABCAAA='], $biometricParameters);
        $this->assertSame(['pin' => '0007', 'type' => 'finger'], $fetchParameters);
        $this->assertSame('Update Biometric Template (Employee: 0007)', $biometric->command_content);
        tenancy()->end();
    }

    public function test_gateway_biometric_receipt_stores_raw_templates_for_the_matching_employee(): void
    {
        [$organisation] = $this->company('BIODATA01');
        tenancy()->initialize($organisation);
        $employee = User::updateOrCreate(['pin' => '0007'], ['name' => 'Template employee', 'is_enabled' => true]);
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'BIODATA01', 'binding_version' => 1, 'kind' => 'biometrics', 'source_ip' => '192.168.10.5',
            'received_at' => now()->toIso8601String(), 'biometrics' => [
                ['pin' => '0007', 'type' => 'finger', 'slot' => '4', 'size' => '16', 'valid' => '1', 'tmp' => 'AwAAAAEAAABCAAA='],
                ['pin' => '0007', 'type' => 'face', 'slot' => '0', 'tmp' => 'face-template'],
            ]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $employee->refresh();
        $this->assertSame('AwAAAAEAAABCAAA=', $employee->fingerprints['4']['tmp']);
        $this->assertSame('face-template', $employee->face_templates['0']['tmp']);
        $this->assertTrue($employee->face_enrolled);
        tenancy()->end();
    }

    public function test_roster_evidence_creates_or_updates_employees_without_payroll_setup(): void
    {
        [$organisation] = $this->company('ROSTER01');
        $event = ['id' => (string) Str::uuid(), 'serial' => 'ROSTER01', 'binding_version' => 1, 'kind' => 'users', 'source_ip' => '192.168.10.5',
            'received_at' => now()->toIso8601String(), 'users' => [['pin' => '0007', 'name' => 'Device name', 'card' => '123'], ['pin' => '0008', 'name' => 'Unknown payroll employee', 'card' => '456']]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $this->assertSame(2, User::employees()->count());
        $this->assertSame('Device name', User::where('pin', '0007')->firstOrFail()->name);
        $this->assertSame('Unknown payroll employee', User::where('pin', '0008')->firstOrFail()->name);
        $this->assertTrue(User::where('pin', '0008')->firstOrFail()->is_enabled);
        $this->assertSame(2, DB::table('gateway_device_users')->count());
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['users'] = [];
        $this->deliver($event)->assertAccepted();
    }

    public function test_provider_switch_blocks_new_commands_but_retains_accepted_attendance(): void
    {
        [$organisation] = $this->company('SWITCH01');
        $event = $this->event('SWITCH01');
        $this->deliver($event)->assertAccepted();
        $organisation->update(['device_integration' => DeviceIntegration::EBIO]);
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $this->assertSame(1, AttendanceLog::count());
        $this->assertFalse(app(DeviceCommandService::class)->available(Device::firstOrFail(), 'info'));
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $this->deliver($event)->assertUnprocessable();
    }

    public function test_recovery_recreates_a_lost_receipt_queue_signal(): void
    {
        $this->company('RECOVER01');
        $event = $this->event('RECOVER01');
        $this->deliver($event)->assertAccepted();
        DB::table('jobs')->delete();
        Queue::fake();
        $this->artisan('gateway:recover')->assertSuccessful();
        Queue::assertPushed(ApplyGatewayReceipt::class, fn ($job) => $job->receiptId === $event['id'] && $job->queue === 'device-ingest');
    }

    public function test_punch_commit_invalidates_payroll_attendance_before_effect_worker_runs(): void
    {
        [$organisation] = $this->company('FRESH01');
        tenancy()->initialize($organisation);
        $user = User::where('pin', '0007')->firstOrFail();
        $calculator = app(AttendanceCalculationService::class);
        $day = $calculator->calculate($user, Carbon::parse('2026-10-03'));
        $this->assertNull($day->stale_at);
        tenancy()->end();
        $event = $this->event('FRESH01');
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $this->assertNotNull($day->fresh()->stale_at);
        $effect = DB::table('gateway_domain_effects')->first();
        tenancy()->end();
        (new ApplyDomainEffect($organisation->id, $effect->id))->handle($calculator,
            app(PayrollFreshnessService::class), app(WhatsAppService::class));
        tenancy()->initialize($organisation);
        $this->assertNull($day->fresh()->stale_at);
        $this->assertSame('applied', DB::table('gateway_domain_effects')->value('state'));
        tenancy()->end();
    }

    public function test_master_settings_do_not_dehydrate_saved_service_secrets(): void
    {
        $admin = Admin::create(['name' => 'Master', 'email' => 'master@test.local', 'password' => bcrypt('test-only'), 'role' => 'master']);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('master'));
        Filament::bootCurrentPanel();
        Livewire::test(DeviceGateway::class)->assertSet('connectionData.events_token', null)
            ->assertSet('connectionData.management_token', null)->assertSee('Queue and delivery board')
            ->set('connectionData.name', 'Updated connection')->call('saveConnection')->assertHasNoErrors();
        $this->assertSame(str_repeat('e', 32), $this->connection->fresh()->events_token);
        $this->assertArrayNotHasKey('events_token', $this->connection->toArray());
    }

    public function test_company_registration_uses_current_tenant_and_keeps_server_credentials_private(): void
    {
        [$company] = $this->company('EXISTING01');
        tenancy()->initialize($company);
        $admin = User::create(['name' => 'Company admin', 'pin' => '900', 'privilege' => 14, 'email' => 'tenant-admin@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Queue::fake();
        $this->app['request']->server->set('REMOTE_ADDR', '8.8.8.8');
        $this->connection->update(['device_server_url' => 'http://192.168.50.10:8082']);
        $registration = app(TenantDeviceRegistration::class);
        $device = $registration->register(['name' => 'Reception', 'serial_number' => 'TENANTNEW01', 'device_ip' => '192.168.50.20', 'location' => 'Reception', 'timezone' => 'Asia/Kolkata',
            'organisation_id' => 'forged-company', 'gateway_connection_id' => 999, 'capabilities' => ['reboot']]);
        $this->assertSame($company->id, tenant('id'));
        $binding = GatewayDeviceBinding::where('serial', 'TENANTNEW01')->firstOrFail();
        $this->assertSame($company->id, $binding->organisation_id);
        $this->assertSame($this->connection->id, $binding->gateway_connection_id);
        $this->assertSame(array_keys(\App\Services\Devices\Gateway\GatewayCommands::OPERATIONS), $binding->capabilities);
        $this->assertSame('8.8.8.8/32', $binding->source_cidr);
        $this->assertSame('8.8.8.8/32', $company->fresh()->gateway_source_cidr);
        $this->assertSame('Reception', $device->options['location']);
        Queue::assertPushed(SyncGatewayBinding::class);
        $setup = $registration->setup($device);
        $this->assertSame('192.168.50.10', $setup['server']);
        $this->assertSame(8082, $setup['port']);
        $this->assertStringNotContainsString(str_repeat('m', 32), json_encode($setup));
        $this->assertArrayNotHasKey('management_url', $setup);
    }

    public function test_company_cannot_register_another_company_device_or_use_an_ebio_profile(): void
    {
        [$first] = $this->company('OWNED01');
        [$second] = $this->company('OWNED02');
        tenancy()->initialize($second);
        $admin = User::create(['name' => 'Company admin', 'pin' => '900', 'privilege' => 14, 'email' => 'tenant-admin@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        $input = ['name' => 'Device', 'serial_number' => 'OWNED01', 'device_ip' => '192.168.50.20', 'timezone' => 'Asia/Kolkata'];
        try {
            app(TenantDeviceRegistration::class)->register($input);
            $this->fail('Existing serial reassigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serial_number', $exception->errors());
        }
        $this->assertSame($second->id, tenant('id'));
        $this->assertSame($first->id, GatewayDeviceBinding::where('serial', 'OWNED01')->value('organisation_id'));
        $second->update(['device_integration' => DeviceIntegration::EBIO]);
        try {
            app(TenantDeviceRegistration::class)->register($input);
            $this->fail('Legacy company registered a new connection.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_company_device_screen_registers_a_device_without_showing_service_configuration(): void
    {
        [$company] = $this->company('SCREEN01');
        tenancy()->initialize($company);
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Company admin', 'pin' => '900', 'privilege' => 14, 'email' => 'tenant-admin@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Queue::fake();
        $this->app['request']->server->set('REMOTE_ADDR', '8.8.8.8');
        Livewire::test(ListDevices::class)
            ->assertSee('Add device')->assertDontSee('Management token')->assertDontSee('Office public IP address')->assertDontSee('Allowed source CIDR')
            ->callAction('addConnectedDevice', data: ['name' => 'Reception', 'serial_number' => 'SCREENNEW01', 'device_ip' => '192.168.50.20', 'location' => 'Reception', 'timezone' => 'Asia/Kolkata'])
            ->assertHasNoActionErrors();
        $this->assertSame($company->id, GatewayDeviceBinding::where('serial', 'SCREENNEW01')->value('organisation_id'));
    }

    public function test_tenant_cannot_invoke_master_replay_or_connection_methods(): void
    {
        $admin = Admin::create(['name' => 'Tenant user', 'email' => 'tenant@test.local', 'password' => bcrypt('test-only'), 'role' => 'user']);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('master'));
        Filament::bootCurrentPanel();
        Livewire::test(DeviceGateway::class)->assertForbidden();
    }
}
