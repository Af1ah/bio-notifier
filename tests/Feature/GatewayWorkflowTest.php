<?php

namespace Tests\Feature;

use App\Casts\ProtectedArray;
use App\Filament\Master\Pages\DeviceGateway;
use App\Filament\Tenant\Pages\OrganisationSettings;
use App\Filament\Tenant\Resources\DeviceCommandResource\Pages\ListDeviceCommands;
use App\Filament\Tenant\Resources\DeviceResource\Pages\EditDevice;
use App\Filament\Tenant\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers\AccessGroupsRelationManager;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers\DeviceUsersRelationManager;
use App\Filament\Tenant\Resources\UserResource\Pages\ListUsers;
use App\Filament\Tenant\Resources\UserResource\Pages\ViewUser;
use App\Jobs\Gateway\ApplyDomainEffect;
use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Jobs\Gateway\SyncGatewayBinding;
use App\Jobs\ProcessEbioWebhookJob;
use App\Jobs\RefreshAttendanceDay;
use App\Models\Admin;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceAccessSetting;
use App\Models\DeviceCommand;
use App\Models\DeviceRosterEntry;
use App\Models\GatewayBindingVersion;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Devices\BiometricCredentials;
use App\Services\Devices\DeviceAccessSettings;
use App\Services\Devices\DeviceAccessValidity;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\DeviceProfilePolicy;
use App\Services\Devices\DeviceUserAccess;
use App\Services\Devices\EmployeeBiometrics;
use App\Services\Devices\Gateway\DeviceDiscovery;
use App\Services\Devices\Gateway\GatewayClient;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Devices\Gateway\ProvisionGatewayBinding;
use App\Services\Devices\Gateway\ReceiptApplier;
use App\Services\Devices\PrivateBiometricPhoto;
use App\Services\Devices\TenantDeviceRegistration;
use App\Services\EbioSoapService;
use App\Services\Payroll\PayrollFreshnessService;
use App\Services\Support\TenantTimezone;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
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
        Device::firstOrFail()->update(['model' => 'iFace', 'options' => ['observed_metadata' => ['model' => 'iFace', 'fp_algorithm_version' => '10']]]);
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

    public function test_device_metadata_preserves_identity_and_separates_platform(): void
    {
        [$company] = $this->company('METADATA01');
        $event = ['id' => (string) Str::uuid(), 'serial' => 'METADATA01', 'binding_version' => 1,
            'kind' => 'device_info', 'received_at' => now()->toIso8601String(),
            'device_info' => ['model' => 'F22', 'platform' => 'ZMM220', 'serial' => 'OBSERVED-OTHER',
                'user_count' => '23', 'fp_count' => '7', 'max_user_count' => '3000', 'fp_algorithm_version' => '10']];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $this->assertSame('METADATA01', $device->serial_number);
        $this->assertSame('F22', $device->model);
        $this->assertSame('23', $device->options['observed_metadata']['user_count']);
        $this->assertSame('ZMM220', $device->options['observed_metadata']['platform']);
        $this->assertSame('legacy-fp', DeviceProfilePolicy::name($device));
        $this->assertSame(['rfid', 'finger'], $device->verificationFormats());
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['device_info']['untrusted'] = 'extra';
        $this->deliver($event)->assertUnprocessable();
    }

    public function test_read_receipt_application_is_distinct_from_device_acknowledgement(): void
    {
        [$company] = $this->company('APPLICATION01');
        tenancy()->initialize($company);
        $command = app(DeviceCommandService::class)->request(Device::firstOrFail(), 'query_users');
        $command->update(['status' => 'acknowledged']);
        $this->assertSame('Device confirmed; no saved data', $command->completionLabel());
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'APPLICATION01', 'binding_version' => 1,
            'kind' => 'users', 'received_at' => now()->toIso8601String(), 'command_id' => $command->gateway_command_id,
            'users' => [['pin' => '0007', 'name' => 'Updated employee']]];
        $this->deliver($event)->assertAccepted();
        tenancy()->initialize($company);
        app(ReceiptApplier::class)->apply(GatewayIngressReceipt::findOrFail($event['id']));
        $this->assertFalse($command->fresh()->data_applied);
        $this->assertSame('Device data received; saving pending', $command->fresh()->completionLabel());
        tenancy()->end();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertTrue($command->fresh()->data_applied);
        $this->assertSame('Data saved; inventory completeness unconfirmed', $command->fresh()->completionLabel());
        tenancy()->end();
        tenancy()->initialize($company);
        $command->update(['data_applied' => false]);
        tenancy()->end();
        // A restarted worker must repair the projection after the central receipt committed.
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertTrue($command->fresh()->data_applied);
        tenancy()->end();

    }

    public function test_non_ist_punch_uses_tenant_calendar_and_retains_original_instant(): void
    {
        [$company] = $this->company('DUBAI01');
        $company->update(['timezone' => 'Asia/Dubai']);
        $event = $this->event('DUBAI01');
        $event['punches'][0]['at'] = '2026-10-03T22:30:00+00:00';
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $log = AttendanceLog::firstOrFail();
        $this->assertSame('2026-10-04 02:30:00', $log->punched_at->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Dubai', $log->punched_at->timezoneName);
        $this->assertSame('2026-10-03T22:30:00+00:00', $log->raw_data['original_time']);
        $this->assertSame('2026-10-03T22:30:00+00:00', $log->raw_data['utc_time']);
        tenancy()->end();
        try {
            $company->update(['timezone' => 'Asia/Kolkata']);
            $this->fail('Changing timezone silently reinterpreted attendance history.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timezone', $exception->errors());
        }

    }

    public function test_credential_storage_is_encrypted_and_keeps_source_index_and_flags(): void
    {
        [$company] = $this->company('IDENTITY01');
        tenancy()->initialize($company);
        $row = ['type' => 'finger', 'slot' => '1', 'bio_type' => '1', 'index' => '0',
            'major_ver' => '10', 'valid' => '3', 'duress' => '1', 'tmp' => 'private-template'];
        $credentials = BiometricCredentials::remember([], $row, 'SOURCE1', now()->toIso8601String());
        $row['index'] = '1';
        $credentials = BiometricCredentials::remember($credentials, $row, 'SOURCE1', now()->toIso8601String());
        $credentials = BiometricCredentials::remember($credentials, $row, 'SOURCE2', now()->toIso8601String());
        $this->assertCount(3, $credentials);
        $legacy = ['1' => ['tmp' => 'old', 'observed_at' => '2026-10-01T00:00:00+00:00'],
            'new' => ['bio_type' => '1', 'slot' => '1', 'index' => '0', 'tmp' => 'new', 'observed_at' => '2026-10-02T00:00:00+00:00']];
        $this->assertCount(1, BiometricCredentials::latest($legacy, '1'));
        $this->assertSame('new', BiometricCredentials::latest($legacy, '1')[0]['tmp']);

        $employee = User::firstOrFail();
        $employee->update(['fingerprints' => $credentials]);
        $raw = DB::table('users')->where('id', $employee->id)->value('fingerprints');
        $this->assertStringNotContainsString('private-template', $raw);
        $this->assertStringContainsString('_encrypted_v1', $raw);
        $this->assertSame($credentials, $employee->fresh()->fingerprints);
        $this->assertSame('1', array_values($employee->fresh()->fingerprints)[0]['duress']);
        $this->assertSame(['old' => ['tmp' => 'legacy']], ProtectedArray::decode('{"old":{"tmp":"legacy"}}'));
        tenancy()->end();
    }

    public function test_non_ist_overnight_shift_keeps_next_day_checkout_on_start_day(): void
    {
        [$company] = $this->company('NIGHTDUBAI01');
        $company->update(['timezone' => 'Asia/Dubai']);
        tenancy()->initialize($company);
        $shift = Schedule::create(['name' => 'Dubai night', 'type' => 'regular', 'valid_from' => '2026-10-01', 'status' => true,
            'rules' => ['start_time' => '22:00', 'end_time' => '06:00', 'end_day_offset' => 1, 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'half_day_minutes' => 240, 'full_day_minutes' => 480, 'punch_method' => 'first_last',
                'overtime_basis' => 'after_required_hours', 'checkout_cutoff_minutes' => 180]]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->assign('user', User::where('pin', '0007')->firstOrFail(), ['schedule_ids' => [$shift->id], 'effective_from' => '2026-10-01']);
        tenancy()->end();
        $event = $this->event('NIGHTDUBAI01');
        $event['punches'] = [
            ['pin' => '0007', 'at' => '2026-10-03T18:00:00+00:00', 'status' => 0, 'verify' => 1],
            ['pin' => '0007', 'at' => '2026-10-04T02:00:00+00:00', 'status' => 1, 'verify' => 1],
        ];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $day = app(AttendanceCalculationService::class)->calculate(User::where('pin', '0007')->firstOrFail(), TenantTimezone::parse('2026-10-03'));
        $this->assertSame('2026-10-03', $day->work_date->toDateString());
        $this->assertSame(480, $day->candidate_worked_minutes);
        $this->assertSame('2026-10-04 06:00', $day->occurrences->first()->last_out_at->format('Y-m-d H:i'));
        tenancy()->end();
    }

    public function test_plaintext_credential_backfill_preserves_data_and_is_idempotent(): void
    {
        [$company] = $this->company('BACKFILL01');
        tenancy()->initialize($company);
        Storage::fake('local');
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $path = 'biometric-photos/legacy.jpg';
        Storage::disk('local')->put($path, $jpeg);
        $employee = User::firstOrFail();
        DB::table('users')->where('id', $employee->id)->update(['fingerprints' => json_encode(['2' => ['tmp' => 'legacy-credential']]), 'ai_face_photo_path' => $path]);
        $migration = require database_path('migrations/tenant/2026_10_19_140000_protect_biometric_credentials.php');
        $migration->up();
        $encrypted = DB::table('users')->where('id', $employee->id)->value('fingerprints');
        $this->assertStringNotContainsString('legacy-credential', $encrypted);
        $this->assertSame('legacy-credential', $employee->fresh()->fingerprints[2]['tmp']);
        $this->assertNotSame($jpeg, Storage::disk('local')->get($path));
        $this->assertSame($jpeg, PrivateBiometricPhoto::read($path));
        $migration->up();
        $this->assertSame($encrypted, DB::table('users')->where('id', $employee->id)->value('fingerprints'));
        $this->assertSame($jpeg, PrivateBiometricPhoto::read($path));
        tenancy()->end();
    }

    public function test_device_discovery_is_automatic_bounded_and_stops_after_info(): void
    {
        [$company] = $this->company('DISCOVERY01');
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $discovery = app(DeviceDiscovery::class);
        $discovery->request($device);
        $discovery->request($device);
        $this->assertSame(1, DeviceCommand::where('command_type', 'info')->count());
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'DISCOVERY01', 'binding_version' => 1,
            'kind' => 'device_info', 'received_at' => now()->toIso8601String(), 'device_info' => ['model' => 'F22', 'fp_algorithm_version' => '10']];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $discovery->request($device->fresh());
        $this->assertSame(1, DeviceCommand::where('command_type', 'info')->count());
        $this->assertSame(1, $device->fresh()->options['profile_policy_version']);
        tenancy()->end();
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
        $this->deliver($changed)->assertAccepted();
        $changed['id'] = (string) Str::uuid();
        $changed['source_ip'] = '192.168.10.5';
        $changed['binding_version'] = 2;
        $this->deliver($changed)->assertUnprocessable();
        $changed['binding_version'] = 1;
        $changed['punches'][0]['pin'] = 7;
        $this->deliver($changed)->assertUnprocessable();
        $this->assertSame(2, GatewayIngressReceipt::count());
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
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"parameters":{}'));
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

    public function test_ebio_employee_photo_is_validated_and_kept_private(): void
    {
        [$company] = $this->company('EBIOPHOTO01');
        $company->update(['device_integration' => DeviceIntegration::EBIO, 'ebio_url' => 'https://ebio.example.test',
            'ebio_soap_username' => 'photo-api', 'ebio_soap_password' => 'test-password']);
        tenancy()->initialize($company->fresh());
        Storage::fake('local');
        $employee = User::where('pin', '0007')->firstOrFail();
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $reply = fn (string $value): string => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><GetEmployeePhotoResponse xmlns="http://tempuri.org/"><GetEmployeePhotoResult>'.$value.'</GetEmployeePhotoResult></GetEmployeePhotoResponse></s:Body></s:Envelope>';
        Http::fake(['*' => Http::sequence()->push($reply(base64_encode($jpeg)))
            ->push('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><UpdateEmployeeExResponse><UpdateEmployeeExResult>success</UpdateEmployeeExResult></UpdateEmployeeExResponse></s:Body></s:Envelope>')
            ->push($reply(''))->push($reply(base64_encode('a face template is not a JPEG')))]);
        $this->assertTrue(app(EmployeeBiometrics::class)->refreshEbioPhoto($employee));
        Http::assertSent(fn ($request): bool => $request->hasHeader('SOAPAction', '"http://tempuri.org/GetEmployeePhoto"') && str_contains($request->body(), '<EmployeeCode>0007</EmployeeCode>'));
        $path = $employee->fresh()->ai_face_photo_path;
        $this->assertSame($jpeg, PrivateBiometricPhoto::read($path));
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Photo administrator', 'pin' => '900', 'privilege' => 14, 'email' => 'photo-admin@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Livewire::test(ViewUser::class, ['record' => $employee->getRouteKey()])
            ->assertSee('Refresh photo')->assertSee('data:image/jpeg;base64,'.base64_encode($jpeg), escape: false);
        $employee->update(['face_templates' => [['tmp' => 'not-a-photograph']]]);
        $this->assertTrue(app(EbioSoapService::class)->pushUser($company->fresh(), $employee));
        Http::assertSent(fn ($request): bool => str_contains($request->body(), '<EmployeePhoto>'.base64_encode($jpeg).'</EmployeePhoto>') && ! str_contains($request->body(), 'not-a-photograph'));
        $this->assertFalse(app(EmployeeBiometrics::class)->refreshEbioPhoto($employee));
        $this->assertSame($path, $employee->fresh()->ai_face_photo_path);
        try {
            app(EmployeeBiometrics::class)->refreshEbioPhoto($employee);
            $this->fail('Non-image template accepted as employee photo.');
        } catch (\RuntimeException) {
            $this->assertSame($path, $employee->fresh()->ai_face_photo_path);
        }
        $company->update(['device_integration' => DeviceIntegration::GATEWAY]);
        tenancy()->initialize($company->fresh());
        $this->expectException(AuthorizationException::class);
        app(EmployeeBiometrics::class)->refreshEbioPhoto($employee);
    }

    public function test_cancel_before_submission_prevents_worker_delivery(): void
    {
        [$company] = $this->company('CANCEL01');
        tenancy()->initialize($company);
        Queue::fake();
        $command = app(DeviceCommandService::class)->request(Device::firstOrFail(), 'info');
        app(GatewayCommands::class)->cancel($command);
        $this->assertSame('cancelled', $command->fresh()->status);
        $id = $command->gateway_command_id;
        tenancy()->end();
        Http::fake();
        (new SubmitGatewayCommand($id))->handle(app(GatewayClient::class));
        Http::assertNothingSent();
        $this->assertSame('cancelled', GatewayCommandSubmission::findOrFail($id)->state);
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
        $upsertParameters = ProtectedArray::decode((string) DB::table('gateway_command_outbox')->where('id', $upsert->gateway_command_id)->value('parameters'));
        $deleteParameters = ProtectedArray::decode((string) DB::table('gateway_command_outbox')->where('id', $delete->gateway_command_id)->value('parameters'));
        $biometricParameters = ProtectedArray::decode((string) DB::table('gateway_command_outbox')->where('id', $biometric->gateway_command_id)->value('parameters'));
        $fetchParameters = ProtectedArray::decode((string) DB::table('gateway_command_outbox')->where('id', $fetch->gateway_command_id)->value('parameters'));
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
        $this->assertSame('AwAAAAEAAABCAAA=', collect($employee->fingerprints)->firstWhere('slot', '4')['tmp']);
        $this->assertSame('face-template', collect($employee->face_templates)->firstWhere('slot', '0')['tmp']);
        $this->assertTrue($employee->face_enrolled);
        tenancy()->end();
    }

    public function test_read_timeout_receipt_finishes_command_with_clear_result(): void
    {
        [$company] = $this->company('READTIMEOUT01');
        tenancy()->initialize($company);
        $command = app(DeviceCommandService::class)->request(Device::firstOrFail(), 'info');
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'READTIMEOUT01', 'binding_version' => 1, 'kind' => 'command_result',
            'received_at' => now()->toIso8601String(), 'command_id' => $command->gateway_command_id, 'command_state' => 'failed', 'error_code' => 'read_timeout'];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('failed', $command->fresh()->status);
        $this->assertSame('Read timed out: no matching device data returned within 60 seconds.', $command->fresh()->response);
        tenancy()->end();
    }

    public function test_tenant_timezone_setting_is_scoped_and_preserves_utc_storage(): void
    {
        [$company] = $this->company('TIMEZONE01');
        tenancy()->initialize($company);
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Admin', 'pin' => '900', 'privilege' => 14, 'email' => 'timezone@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->assertSame('Asia/Kolkata', TenantTimezone::get());
        Livewire::test(OrganisationSettings::class)
            ->set('data.timezone', 'Asia/Dubai')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Asia/Dubai', $company->fresh()->timezone);
        $this->assertSame('Asia/Dubai', TenantTimezone::get());
        $command = DeviceCommand::create(['device_id' => Device::firstOrFail()->id, 'command_type' => 'check', 'command_content' => 'Check', 'status' => 'acknowledged', 'sent_at' => '2026-10-19 10:00:00']);
        $component = Livewire::test(ListDeviceCommands::class);
        $column = $component->instance()->getTable()->getColumn('sent_at')->record($command);
        $this->assertStringContainsString('14:00:00', strip_tags($column->formatState($column->getState())));
        $this->assertSame('2026-10-19 10:00:00', $command->fresh()->getRawOriginal('sent_at'));
        $this->assertSame('UTC', config('app.timezone'));
        Livewire::test(OrganisationSettings::class)
            ->set('data.timezone', 'Invalid/Timezone')->call('save')->assertHasFormErrors(['timezone']);
        tenancy()->end();
    }

    public function test_manual_fetch_requests_ai_photo_and_received_jpeg_replaces_preview(): void
    {
        [$company, $binding] = $this->company('FETCHPHOTO01');
        $binding->update(['capabilities' => ['query_biodata']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['model' => 'x 2008', 'options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510'], 'supported_verification_formats' => ['rfid', 'finger', 'ai_face']]]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $employee->update(['ai_face_photo_path' => 'biometric-photos/old.jpg']);
        app(EmployeeBiometrics::class)->fetch($employee, $device);
        $types = DB::table('gateway_command_outbox')->get()->map(fn ($row) => ProtectedArray::decode($row->parameters)['type'])->all();
        $this->assertSame(['finger', 'ai_face'], $types);
        tenancy()->end();
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $event = ['id' => (string) Str::uuid(), 'serial' => 'FETCHPHOTO01', 'binding_version' => 1, 'kind' => 'biometric_photos',
            'source_ip' => '192.168.10.5', 'received_at' => now()->toIso8601String(), 'photos' => [['pin' => '0007', 'content' => base64_encode($jpeg)]]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $employee = User::where('pin', '0007')->firstOrFail();
        $path = 'biometric-photos/'.$event['id'].'-0.jpg';
        $this->assertSame($path, $employee->ai_face_photo_path);
        $this->assertSame($jpeg, PrivateBiometricPhoto::read($path));
        $this->assertEmpty($employee->face_templates);
        Storage::disk('local')->delete($path);
        tenancy()->end();
    }

    public function test_first_fingerprint_punch_fetches_once_and_reuses_received_template(): void
    {
        [$company, $binding] = $this->company('AUTOBIO01');
        $binding->update(['capabilities' => ['query_biodata']]);
        tenancy()->initialize($company);
        Device::firstOrFail()->update(['options' => ['supported_verification_formats' => ['rfid', 'finger']]]);
        tenancy()->end();
        for ($i = 0; $i < 2; $i++) {
            $event = $this->event('AUTOBIO01');
            $event['punches'][0]['verify'] = 1;
            $event['punches'][0]['at'] = '2026-10-03T10:0'.$i.':00+05:30';
            $this->deliver($event)->assertAccepted();
            (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        }
        tenancy()->initialize($company);
        $this->assertSame(1, DB::table('gateway_domain_effects')->where('type', 'biometric_finger')->count());
        $effect = DB::table('gateway_domain_effects')->where('type', 'biometric_finger')->first();
        $id = User::where('pin', '0007')->firstOrFail()->biometric_fetch_requests['finger']['command_id'];
        tenancy()->end();
        $job = new ApplyDomainEffect($company->id, $effect->id);
        $job->handle(app(AttendanceCalculationService::class), app(PayrollFreshnessService::class), app(WhatsAppService::class));
        tenancy()->initialize($company);
        $this->assertSame(1, DB::table('gateway_command_outbox')->where('operation', 'query_biodata')->count());
        $this->assertSame($id, DB::table('gateway_command_outbox')->value('id'));
        // Simulate recovery after the command committed but before effect completion.
        DB::table('gateway_domain_effects')->where('id', $effect->id)->update(['state' => 'pending']);
        tenancy()->end();
        $job->handle(app(AttendanceCalculationService::class), app(PayrollFreshnessService::class), app(WhatsAppService::class));
        tenancy()->initialize($company);
        $this->assertSame(1, DB::table('gateway_command_outbox')->count());
        tenancy()->end();
        $templates = ['id' => (string) Str::uuid(), 'serial' => 'AUTOBIO01', 'binding_version' => 1, 'kind' => 'biometrics', 'source_ip' => '192.168.10.5',
            'received_at' => now()->toIso8601String(), 'biometrics' => [['pin' => '0007', 'type' => 'finger', 'slot' => '6', 'tmp' => 'AwAAAAEAAABCAAA=']]];
        $this->deliver($templates)->assertAccepted();
        (new ApplyGatewayReceipt($templates['id']))->handle(app(ReceiptApplier::class));
        $later = $this->event('AUTOBIO01');
        $later['punches'][0]['verify'] = 1;
        $later['punches'][0]['at'] = '2026-10-03T11:00:00+05:30';
        $this->deliver($later)->assertAccepted();
        (new ApplyGatewayReceipt($later['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('AwAAAAEAAABCAAA=', collect(User::where('pin', '0007')->firstOrFail()->fingerprints)->firstWhere('slot', '6')['tmp']);
        $this->assertSame(1, DB::table('gateway_domain_effects')->where('type', 'biometric_finger')->count());
        $this->assertSame(1, DB::table('gateway_command_outbox')->count());
        tenancy()->end();
    }

    public function test_device_connection_uses_recent_activity_not_saved_status(): void
    {
        [$organisation] = $this->company('CONNECTION01');
        tenancy()->initialize($organisation);
        $device = Device::firstOrFail();

        $device->update(['status' => 'online', 'last_activity_at' => now()->subMinutes(Device::CONNECTION_WINDOW_MINUTES + 1)]);
        $this->assertFalse($device->fresh()->isOnline());
        $this->assertSame(0, Device::connected()->count());

        $device->update(['status' => 'offline', 'last_activity_at' => now()->subMinute()]);
        $this->assertTrue($device->fresh()->isOnline());
        $this->assertSame(1, Device::connected()->count());
        tenancy()->end();
    }

    public function test_unknown_gateway_profile_allows_card_only_user_push(): void
    {
        [$organisation, $binding] = $this->company('UNKNOWNCARD01');
        $binding->update(['capabilities' => ['upsert_user', 'update_biodata']]);
        tenancy()->initialize($organisation);
        $device = Device::firstOrFail();
        $device->update([
            'model' => 'unclassified terminal',
            'options' => ['supported_verification_formats' => ['rfid', 'finger']],
        ]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $employee->update(['card_number' => '1234']);

        $commands = app(DeviceCommandService::class);
        $biometrics = app(EmployeeBiometrics::class);
        $this->assertTrue($commands->available($device, 'upsert_user'));
        $this->assertFalse($commands->available($device, 'update_biodata'));
        $this->assertSame(['rfid'], array_keys($biometrics->formats($device)));
        $this->assertSame(1, $biometrics->push($employee, $device, ['rfid']));
        $this->assertSame('upsert_user', DB::table('gateway_command_outbox')->value('operation'));
        $this->assertSame('1234', ProtectedArray::decode(DB::table('gateway_command_outbox')->value('parameters'))['card']);
        tenancy()->end();
    }

    public function test_k90_zlm60_profile_allows_fingerprint_not_face(): void
    {
        $device = new Device([
            'model' => 'x 2008',
            'options' => [
                'observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZLM60_TFT'],
                'supported_verification_formats' => ['rfid', 'finger', 'face'],
            ],
        ]);

        $this->assertSame('legacy-fp', DeviceProfilePolicy::name($device));
        $this->assertTrue(DeviceProfilePolicy::format($device, 'finger'));
        $this->assertFalse(DeviceProfilePolicy::format($device, 'face'));
    }

    public function test_combined_push_checks_formats_and_queues_user_before_templates(): void
    {
        [$organisation, $binding] = $this->company('PUSHBIO01');
        $binding->update(['capabilities' => ['upsert_user', 'update_biodata', 'update_biophoto', 'query_biodata']]);
        tenancy()->initialize($organisation);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['fp_algorithm_version' => '10'], 'supported_verification_formats' => ['rfid', 'finger']]]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $employee->update(['card_number' => '1234']);
        $service = app(EmployeeBiometrics::class);
        $this->assertSame(['rfid', 'finger'], array_keys($service->formats($device)));
        $service->registerFingerprint($employee, '6', 'AwAAAAEAAABCAAA=', '10');
        $this->assertSame('AwAAAAEAAABCAAA=', $employee->fresh()->fingerprints[6]['tmp']);
        $this->assertSame(0, DB::table('gateway_command_outbox')->count());
        foreach ([['face'], ['ai_face']] as $unsupported) {
            try {
                $service->push($employee, $device, $unsupported);
                $this->fail('Unsupported format was accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('gateway_command_outbox')->count());
            }
        }
        $this->assertSame(2, $service->push($employee, $device, ['rfid', 'finger']));
        $rows = DB::table('gateway_command_outbox')->orderBy('created_at')->get();
        $this->assertSame(['upsert_user', 'update_biodata'], $rows->pluck('operation')->all());
        $this->assertSame('1234', ProtectedArray::decode($rows[0]->parameters)['card']);
        $this->assertSame('6', ProtectedArray::decode($rows[1]->parameters)['no']);
        $this->assertSame($rows[0]->id, ProtectedArray::decode($rows[1]->parameters)['__depends_on']);
        $this->assertSame(1, $service->push($employee, $device, []));
        $last = DB::table('gateway_command_outbox')->whereNotIn('id', $rows->pluck('id')->all())->first();
        $this->assertArrayNotHasKey('card', ProtectedArray::decode($last->parameters));
        $device->update(['options' => ['supported_verification_formats' => ['rfid', 'finger', 'face']]]);
        $this->assertSame(['rfid', 'finger', 'face'], array_keys($service->formats($device)));
        try {
            $service->push($employee, $device, ['face']);
            $this->fail('Missing face data was accepted.');
        } catch (ValidationException) {
            $this->assertSame(3, DB::table('gateway_command_outbox')->count());
        }
        tenancy()->end();
    }

    public function test_push_fingerprint_infers_v10_algorithm_when_major_ver_omitted(): void
    {
        [$organisation, $binding] = $this->company('INFEPUSH01');
        $binding->update(['capabilities' => ['upsert_user', 'update_biodata']]);
        tenancy()->initialize($organisation);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['fp_algorithm_version' => '10'], 'supported_verification_formats' => ['finger']]]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $employee->update(['fingerprints' => [
            '5' => ['tmp' => 'TQ9TUzIxAAAETFAECAUHCc7QAAAcTWkBAAA=', 'valid' => '1', 'size' => '32'],
        ]]);
        $service = app(EmployeeBiometrics::class);
        $this->assertSame(2, $service->push($employee, $device, ['finger']));
        $row = DB::table('gateway_command_outbox')->where('operation', 'update_biodata')->first();
        $this->assertSame('10', ProtectedArray::decode($row->parameters)['major_ver']);
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

    public function test_device_user_access_readback_stays_device_specific_and_missing_fields_are_unknown(): void
    {
        [$organisation] = $this->company('ACCESSREAD01');
        $event = ['id' => (string) Str::uuid(), 'serial' => 'ACCESSREAD01', 'binding_version' => 1, 'kind' => 'users',
            'received_at' => now()->toIso8601String(), 'users' => [['pin' => '0007', 'name' => 'Device user',
                'access' => ['StartDatetime' => '20261023', 'EndDatetime' => '20261025', 'TZ' => '0001003200000000', 'Grp' => '1']]]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $record = DeviceRosterEntry::firstOrFail();
        $this->assertSame('20261025', $record->access_settings['EndDatetime']);
        $this->assertStringNotContainsString('20261025', $record->getRawOriginal('access_settings'));
        $this->assertSame('Not reported', DeviceUserAccess::expiry($record));
        $this->assertSame('Not reported', DeviceUserAccess::entries($record));
        $this->assertSame('Personal periods: 50', DeviceUserAccess::periods($record));
        $this->assertSame('2026-10-25', DeviceUserAccess::date($record->access_settings['EndDatetime']));
        $this->assertSame('Unset on device', DeviceUserAccess::date('0'));
        $this->assertSame('Unrecognized date', DeviceUserAccess::date('20260230'));
        $this->assertArrayNotHasKey('access_settings', User::where('pin', '0007')->firstOrFail()->getAttributes());
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['users'][0]['access'] = ['ValidCount' => '0', 'TZ' => '0001000000000000'];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $record->refresh();
        $this->assertSame('0 (device-reported; unverified)', DeviceUserAccess::entries($record));
        $this->assertSame('Personal periods: none reported', DeviceUserAccess::periods($record));
        $this->assertArrayNotHasKey('EndDatetime', $record->access_settings);
        tenancy()->end();
        $oldEvent = $event;
        $oldEvent['id'] = (string) Str::uuid();
        $oldEvent['received_at'] = now()->subHour()->toIso8601String();
        $oldEvent['users'][0]['access'] = ['EndDatetime' => '20200101'];
        $this->deliver($oldEvent)->assertAccepted();
        (new ApplyGatewayReceipt($oldEvent['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($organisation);
        $record->refresh();
        $this->assertSame('0', $record->access_settings['ValidCount']);
        $this->assertArrayNotHasKey('EndDatetime', $record->access_settings);
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['users'][0]['access']['Passwd'] = 'must-not-be-accepted';
        $this->deliver($event)->assertUnprocessable();
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

    public function test_master_status_loads_recovery_on_demand_without_service_secrets(): void
    {
        $admin = Admin::create(['name' => 'Master', 'email' => 'master@test.local', 'password' => bcrypt('test-only'), 'role' => 'master']);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('master'));
        Filament::bootCurrentPanel();
        Http::fake(['*' => Http::response([], 200)]);
        $component = Livewire::test(DeviceGateway::class)->assertSet('detailsLoaded', false)
            ->assertSee('System status')->assertSee('Load recovery details')
            ->assertDontSee('Connection settings')->assertDontSee('Registered devices')
            ->assertDontSee('wire:poll', escape: false)->assertDontSee(str_repeat('e', 32))->assertDontSee(str_repeat('m', 32));
        Http::assertNothingSent();
        $component->call('loadDetails')->assertSet('detailsLoaded', true)->assertSee('No matching receipts');
        Http::assertSentCount(2);
        $this->assertFalse(method_exists(DeviceGateway::class, 'saveConnection'));
        $this->assertFalse(method_exists(DeviceGateway::class, 'retryBinding'));
        $this->assertSame(str_repeat('e', 32), $this->connection->fresh()->events_token);
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
        $device = $registration->register(['name' => 'Reception', 'serial_number' => 'TENANTNEW01', 'direction' => 'OUT', 'device_ip' => '192.168.50.20', 'location' => 'Reception', 'timezone' => 'Asia/Kolkata',
            'organisation_id' => 'forged-company', 'gateway_connection_id' => 999, 'capabilities' => ['reboot']]);
        $this->assertSame($company->id, tenant('id'));
        $binding = GatewayDeviceBinding::where('serial', 'TENANTNEW01')->firstOrFail();
        $this->assertSame($company->id, $binding->organisation_id);
        $this->assertSame($this->connection->id, $binding->gateway_connection_id);
        $this->assertSame(array_keys(GatewayCommands::OPERATIONS), $binding->capabilities);
        $this->assertSame('0.0.0.0/0', $binding->source_cidr);
        $this->assertNull($company->fresh()->gateway_source_cidr);
        $this->assertSame('Reception', $device->options['location']);
        $this->assertSame('OUT', $device->attendanceDirection());
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

    public function test_employee_biometric_actions_register_then_push_selected_formats(): void
    {
        [$company, $binding] = $this->company('BIOUI01');
        $binding->update(['capabilities' => ['upsert_user', 'update_biodata', 'query_biodata']]);
        tenancy()->initialize($company);
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Company admin', 'pin' => '900', 'privilege' => 14, 'email' => 'bioui@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Queue::fake();
        $employee = User::where('pin', '0007')->firstOrFail();
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['fp_algorithm_version' => '10'], 'supported_verification_formats' => ['rfid', 'finger']]]);
        Livewire::test(ListUsers::class)
            ->callTableAction('registerBiometric', $employee, data: ['source' => 'finger', 'finger_index' => '6', 'template_algorithm' => '10', 'template_data' => 'AwAAAAEAAABCAAA='])
            ->assertHasNoActionErrors();
        $this->assertSame('AwAAAAEAAABCAAA=', $employee->fresh()->fingerprints[6]['tmp']);
        Livewire::test(ListUsers::class)
            ->callTableAction('pushToDevice', $employee, data: ['device_id' => $device->id, 'formats' => ['rfid', 'finger']])
            ->assertHasNoActionErrors();
        $this->assertSame(2, DB::table('gateway_command_outbox')->count());
        Storage::fake('local');
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        Livewire::test(ListUsers::class)
            ->callTableAction('registerBiometric', $employee, data: ['source' => 'ai_face', 'photo_input' => 'camera', 'camera_photo' => 'data:image/jpeg;base64,'.base64_encode($jpeg)])
            ->assertHasNoActionErrors();
        $path = $employee->fresh()->ai_face_photo_path;
        $this->assertStringStartsWith('biometric-photos/', $path);
        $this->assertSame($jpeg, PrivateBiometricPhoto::read($path));
        Livewire::test(ViewUser::class, ['record' => $employee->getRouteKey()])
            ->assertSee('Stored AI Face photo')
            ->assertSee('data:image/jpeg;base64,'.base64_encode($jpeg), escape: false);
        foreach (['data:image/jpeg;base64,'.base64_encode('not an image'), 'data:image/jpeg;base64,'.base64_encode(str_repeat('x', 786433))] as $invalid) {
            try {
                app(EmployeeBiometrics::class)->registerCameraPhoto($employee, $invalid);
                $this->fail('Invalid camera photo was accepted.');
            } catch (ValidationException) {
                $this->assertSame($path, $employee->fresh()->ai_face_photo_path);
            }
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
            ->callAction('addConnectedDevice', data: ['name' => 'Reception', 'serial_number' => 'SCREENNEW01', 'direction' => 'IN', 'device_ip' => '192.168.50.20', 'location' => 'Reception', 'timezone' => 'Asia/Kolkata'])
            ->assertHasNoActionErrors();
        $this->assertSame($company->id, GatewayDeviceBinding::where('serial', 'SCREENNEW01')->value('organisation_id'));
        $this->assertSame('IN', Device::where('serial_number', 'SCREENNEW01')->firstOrFail()->attendanceDirection());
    }

    public function test_tenant_cannot_invoke_master_replay_or_connection_methods(): void
    {
        $admin = Admin::create(['name' => 'Tenant user', 'email' => 'tenant@test.local', 'password' => bcrypt('test-only'), 'role' => 'user']);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('master'));
        Filament::bootCurrentPanel();
        Livewire::test(DeviceGateway::class)->assertForbidden();
    }

    public function test_device_time_sync_uses_owned_registration_and_normal_command_queue(): void
    {
        [$company, $binding] = $this->company('CLOCK001');
        $binding->update(['timezone' => 'Asia/Dubai', 'capabilities' => ['info', 'sync_time']]);
        tenancy()->initialize($company);
        $admin = User::create(['name' => 'Clock admin', 'pin' => 'clock-admin', 'privilege' => 14, 'email' => 'clock@test.local', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => $company->shortname]);
        Queue::fake();
        $device = Device::firstOrFail();
        $this->assertSame('Asia/Dubai', app(GatewayCommands::class)->timezone($device));
        Livewire::test(ListDevices::class)->callTableAction('syncTime', $device)->assertHasNoTableActionErrors();
        $command = DeviceCommand::where('command_type', 'sync_time')->firstOrFail();
        $submission = GatewayCommandSubmission::findOrFail($command->gateway_command_id);
        $this->assertSame([], $submission->parameters);
        $this->assertSame($binding->id, $submission->binding_id);
        $this->assertSame('pending', $command->status);
        Queue::assertPushed(SubmitGatewayCommand::class);
        try {
            app(GatewayCommands::class)->request($device, 'sync_time', ['time' => '2000-01-01']);
            $this->fail('User-supplied clock accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        tenancy()->end();
    }

    public function test_full_ebio_resend_keeps_raw_and_approved_manual_result_and_repairs_unmodified_day(): void
    {
        [$company, $binding] = $this->company('REPLAYCLOCK');
        $company->update(['device_integration' => 'ebio']);
        tenancy()->initialize($company);
        $user = User::where('pin', '0007')->firstOrFail();
        $actor = User::create(['name' => 'Reviewer', 'pin' => 'reviewer', 'privilege' => 14, 'is_system_account' => true]);
        $this->actingAs($actor, 'web');
        Queue::fake();
        $shift = Schedule::create(['name' => 'Replay shift', 'type' => 'regular', 'status' => true, 'valid_from' => '2026-10-01',
            'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480,
                'half_day_minutes' => 240, 'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'auto_checkout_enabled' => false, 'overtime_enabled' => false, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => '2026-10-01']);
        $logs = collect(['2026-10-20 09:00:00', '2026-10-20 17:00:00', '2026-10-21 09:00:00'])->map(fn ($at) => ['EmployeeCode' => '0007', 'SerialNumber' => 'REPLAYCLOCK', 'LogDate' => $at, 'Direction' => str_contains($at, '17:00') ? 'out' : 'in'])->all();
        (new ProcessEbioWebhookJob($company, $logs))->handle();
        tenancy()->initialize($company);
        $calculator = app(AttendanceCalculationService::class);
        $day = $calculator->calculate($user, Carbon::parse('2026-10-20'));
        $missing = $calculator->calculate($user, Carbon::parse('2026-10-21'));
        $correction = app(AttendanceEntryService::class)->correct($day, $actor,
            ['attendance_occurrence_id' => $day->occurrences->first()->id, 'proposed_in_at' => '2026-10-20 09:00', 'proposed_out_at' => '2026-10-20 16:00', 'reason' => 'Verified actual departure']);
        app(ApprovalService::class)->decide($day->approvals()->where('proposal->correction_id', $correction->id)->firstOrFail(), $actor, true);
        $before = $day->fresh();
        $occurrence = $before->occurrences()->firstOrFail();
        $rawBefore = AttendanceLog::whereDate('punched_at', '2026-10-20')->orderBy('id')->get()->map->getAttributes()->all();
        $slip = SalarySlip::create(['user_id' => $user->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'paid', 'calculation_snapshot' => ['manual_result' => true], 'calculated_at' => now(), 'net_pay_minor' => 12345]);
        $slipBefore = $slip->fresh()->getAttributes();
        $logs[] = ['EmployeeCode' => '0007', 'SerialNumber' => 'REPLAYCLOCK', 'LogDate' => '2026-10-20 12:00:00', 'Direction' => 'in'];
        // Replay the protected day first: it must not invalidate any payroll snapshot.
        (new ProcessEbioWebhookJob($company, array_values(array_filter($logs, fn ($log) => str_starts_with($log['LogDate'], '2026-10-20')))))->handle();
        tenancy()->initialize($company);
        $after = $calculator->calculate($user, Carbon::parse('2026-10-20'));
        $this->assertSame(4, AttendanceLog::count());
        $this->assertSame($rawBefore, AttendanceLog::whereIn('id', array_column($rawBefore, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($occurrence->id, $after->occurrences->first()->id);
        $this->assertSame('2026-10-20 16:00:00', $after->occurrences->first()->last_out_at->format('Y-m-d H:i:s'));
        $this->assertSame($before->approved_worked_minutes, $after->approved_worked_minutes);
        $this->assertSame($before->calculation_revision, $after->calculation_revision);
        $this->assertSame('approved', $correction->fresh()->state);
        $this->assertSame($slipBefore, $slip->fresh()->getAttributes());
        // The Go delivery/recalculation path must obey the same protection.
        $company->update(['device_integration' => 'adms_gateway']);
        $receipt = GatewayIngressReceipt::create(['id' => (string) Str::uuid(), 'gateway_connection_id' => $this->connection->id,
            'binding_id' => $binding->id, 'organisation_id' => $company->id, 'device_id' => $binding->device_id, 'binding_version' => 1,
            'kind' => 'attendance', 'payload_hash' => hash('sha256', 'manual-replay'), 'state' => 'pending',
            'payload' => ['serial' => 'REPLAYCLOCK', 'received_at' => now()->utc()->toIso8601String(), 'punches' => [
                ['pin' => '0007', 'at' => '2026-10-20T14:00:00+05:30', 'status' => 0, 'verify' => 1]]]]);
        app(ReceiptApplier::class)->apply($receipt);
        $effectId = DB::table('gateway_domain_effects')->where('receipt_id', $receipt->id)->where('type', 'calculation')->value('id');
        (new ApplyDomainEffect($company->id, $effectId))->handle($calculator, app(PayrollFreshnessService::class), app(WhatsAppService::class));
        tenancy()->initialize($company);
        $this->assertSame($slipBefore, $slip->fresh()->getAttributes());
        $this->assertSame('2026-10-20 16:00:00', $occurrence->fresh()->last_out_at->format('Y-m-d H:i:s'));
        $company->update(['device_integration' => 'ebio']);
        $logs[] = ['EmployeeCode' => '0007', 'SerialNumber' => 'REPLAYCLOCK', 'LogDate' => '2026-10-21 17:00:00', 'Direction' => 'out'];
        (new ProcessEbioWebhookJob($company, $logs))->handle();
        tenancy()->initialize($company);
        $repaired = $calculator->calculate($user, Carbon::parse('2026-10-21'));
        $this->assertSame(480, $repaired->candidate_worked_minutes);
        $this->assertGreaterThan($missing->calculation_revision, $repaired->calculation_revision);
        $this->assertSame(6, AttendanceLog::count());
        $this->assertSame(12345, $slip->fresh()->net_pay_minor);
        $this->assertSame('paid', $slip->fresh()->status);
        tenancy()->end();
    }

    public function test_raw_attendance_cannot_be_updated_or_deleted_through_database_queries(): void
    {
        [$company] = $this->company('IMMUTABLELOG');
        tenancy()->initialize($company);
        $log = AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => Device::firstOrFail()->id, 'pin' => '0007', 'punched_at' => '2026-10-20 09:00:00', 'status' => 0]));
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $log) {
                    $query = DB::table('attendance_logs')->where('id', $log->id);
                    $operation === 'update' ? $query->update(['status' => 1]) : $query->delete();
                });
                $this->fail('Raw punch mutation accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->artisan('seed:attendance')->assertFailed();
        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame(0, (int) $log->fresh()->status);
        tenancy()->end();
    }

    public function test_duplicate_raw_replay_can_recover_missing_derived_attendance_without_rewriting_source(): void
    {
        [$company] = $this->company('RECOVERDAY');
        $company->update(['device_integration' => 'ebio']);
        tenancy()->initialize($company);
        $user = User::where('pin', '0007')->firstOrFail();
        $device = Device::firstOrFail();
        $log = AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => $device->id, 'pin' => $user->pin, 'punched_at' => '2026-10-20 09:00:00', 'status' => 0]));
        $before = $log->fresh()->getAttributes();
        Queue::fake();
        $rows = array_fill(0, 5, ['EmployeeCode' => '0007', 'SerialNumber' => 'RECOVERDAY', 'LogDate' => '2026-10-20 09:00:00', 'Direction' => 'in']);
        (new ProcessEbioWebhookJob($company, $rows))->handle();
        tenancy()->initialize($company);
        $this->assertSame($before, $log->fresh()->getAttributes());
        $this->assertSame(1, AttendanceLog::count());
        Queue::assertPushed(RefreshAttendanceDay::class, 2);
        Queue::assertPushed(RefreshAttendanceDay::class, fn ($job) => $job->workDate === '2026-10-20' && $job->userId === $user->id && $job->connection === 'gateway');
        tenancy()->end();
    }

    public function test_eight_thousand_history_punches_are_bounded_and_older_missing_punch_is_not_skipped(): void
    {
        [$company] = $this->company('HISTORY8000');
        tenancy()->initialize($company);
        User::where('pin', '0007')->firstOrFail()->update(['whatsapp_number' => '919000000001']);
        tenancy()->end();
        Queue::fake();
        $start = Carbon::parse('2026-10-01 08:00:00', 'Asia/Kolkata');
        $firstBatch = [];
        for ($batch = 0; $batch < 16; $batch++) {
            $event = $this->event('HISTORY8000');
            $event['punches'] = [];
            for ($i = 0; $i < 500; $i++) {
                $event['punches'][] = ['pin' => '0007', 'at' => $start->copy()->addSeconds($batch * 500 + $i)->toIso8601String(), 'status' => 255, 'verify' => 1, 'work_code' => 0];
            }
            if ($batch === 0) {
                $firstBatch = $event['punches'];
            }
            $this->deliver($event)->assertAccepted();
            (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
            $this->assertSame('applied', GatewayIngressReceipt::findOrFail($event['id'])->state);
        }
        tenancy()->initialize($company);
        $this->assertSame(8000, AttendanceLog::count());
        $this->assertSame(8000, DB::table('gateway_attendance_evidence')->count());
        $this->assertSame(16, DB::table('gateway_domain_effects')->where('type', 'calculation')->count());
        $this->assertSame(0, DB::table('gateway_domain_effects')->where('type', 'notification')->count());
        $first = AttendanceLog::orderBy('id')->firstOrFail();
        $before = $first->getAttributes();
        tenancy()->end();
        // A different packet layout and an earlier missing timestamp must still reconcile by identity.
        $replay = $this->event('HISTORY8000');
        $replay['punches'] = [...array_slice($firstBatch, 0, 100), ['pin' => '0007', 'at' => '2026-09-01T09:00:00+05:30', 'status' => 0, 'verify' => 1]];
        $this->deliver($replay)->assertAccepted();
        (new ApplyGatewayReceipt($replay['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame(8001, AttendanceLog::count());
        $this->assertSame(8101, DB::table('gateway_attendance_evidence')->count());
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame(17, DB::table('gateway_domain_effects')->where('type', 'calculation')->count());
        tenancy()->end();
        $live = $this->event('HISTORY8000');
        $live['received_at'] = '2026-10-20T12:00:00+05:30';
        $live['punches'] = [['pin' => '0007', 'at' => '2026-10-20T11:59:00+05:30', 'status' => 255, 'verify' => 1]];
        $this->deliver($live)->assertAccepted();
        (new ApplyGatewayReceipt($live['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame(8002, AttendanceLog::count());
        $this->assertSame(1, DB::table('gateway_domain_effects')->where('type', 'notification')->count());
        tenancy()->end();

    }

    public function test_clear_device_attendance_uses_typed_confirmation_and_preserves_server_raw(): void
    {
        [$company, $binding] = $this->company('CLEARSAFE01');
        $binding->update(['capabilities' => ['info', 'clear_logs']]);
        tenancy()->initialize($company);
        $admin = User::create(['name' => 'Device admin', 'pin' => 'clear-admin', 'privilege' => 14, 'email' => 'clear@test.local', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => $company->shortname]);
        Queue::fake();
        $device = Device::firstOrFail();
        $raw = AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => $device->id, 'pin' => '0007', 'punched_at' => '2026-10-01 09:00:00', 'status' => 0]));
        $before = $raw->fresh()->getAttributes();
        Livewire::test(ListDevices::class)->callTableAction('clearDeviceAttendance', $device, data: ['dialect' => 'clear_log', 'confirmed' => false])->assertHasTableActionErrors(['confirmed']);
        $this->assertSame(0, DeviceCommand::where('command_type', 'clear_logs')->count());
        Livewire::test(ListDevices::class)->callTableAction('clearDeviceAttendance', $device, data: ['dialect' => 'delete_attlog', 'confirmed' => true])->assertHasNoTableActionErrors();
        $command = DeviceCommand::where('command_type', 'clear_logs')->firstOrFail();
        $this->assertSame(['dialect' => 'delete_attlog'], GatewayCommandSubmission::findOrFail($command->gateway_command_id)->parameters);
        $this->assertSame($before, $raw->fresh()->getAttributes());
        $this->assertSame(1, AttendanceLog::count());
        tenancy()->end();
        $ack = $this->event('CLEARSAFE01');
        unset($ack['punches']);
        $ack['kind'] = 'command_result';
        $ack['command_id'] = $command->gateway_command_id;
        $ack['command_state'] = 'acknowledged';
        $ack['return_code'] = 0;
        $this->deliver($ack)->assertAccepted();
        (new ApplyGatewayReceipt($ack['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('acknowledged', $command->fresh()->status);
        $this->assertSame($before, $raw->fresh()->getAttributes());
        tenancy()->end();
        $pending = $this->event('CLEARSAFE01');
        $this->deliver($pending)->assertAccepted();
        tenancy()->initialize($company);
        try {
            app(GatewayCommands::class)->request($device, 'clear_logs', ['dialect' => 'clear_log']);
            $this->fail('Clear accepted with unapplied attendance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dialect', $exception->errors());
        }
        try {
            app(GatewayCommands::class)->request($device, 'clear_logs', ['dialect' => 'clear_data']);
            $this->fail('Clear-all-data accepted as an attendance clear.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dialect', $exception->errors());
        }
        $this->assertSame($before, $raw->fresh()->getAttributes());
        tenancy()->end();
    }

    public function test_configured_direction_is_captured_for_new_punches_without_rewriting_raw_status(): void
    {
        [$company] = $this->company('DIRECTION01');
        Queue::fake();
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['options' => array_merge($device->options ?? [], ['direction' => 'IN'])]);
        $shift = Schedule::create(['name' => 'Direction shift', 'type' => 'regular', 'valid_from' => '2026-10-01', 'status' => true,
            'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'end_day_offset' => 0, 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'half_day_minutes' => 240, 'full_day_minutes' => 480, 'punch_method' => 'device', 'checkout_cutoff_minutes' => 180]]);
        app(ShiftRuleService::class)->sync($shift);
        $user = User::where('pin', '0007')->firstOrFail();
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => '2026-10-01']);
        tenancy()->end();
        $in = $this->event('DIRECTION01');
        $in['punches'][0]['status'] = 255;
        $this->deliver($in)->assertAccepted();
        (new ApplyGatewayReceipt($in['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $first = AttendanceLog::firstOrFail();
        $original = $first->getAttributes();
        $this->assertSame(255, (int) $first->status);
        $this->assertSame(0, $first->attendance_status);
        $this->assertSame('INOUT', $first->status_label);
        $device->update(['options' => array_merge($device->fresh()->options ?? [], ['direction' => 'OUT'])]);
        tenancy()->end();
        $out = $this->event('DIRECTION01');
        $out['punches'][0]['at'] = '2026-10-03T17:00:00+05:30';
        $out['punches'][0]['status'] = 255;
        $this->deliver($out)->assertAccepted();
        (new ApplyGatewayReceipt($out['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $day = app(AttendanceCalculationService::class)->calculate($user, TenantTimezone::parse('2026-10-03'));
        $this->assertSame(480, $day->candidate_worked_minutes);
        $revision = $day->calculation_revision;
        $device->update(['options' => array_merge($device->fresh()->options ?? [], ['direction' => 'INOUT'])]);
        $again = app(AttendanceCalculationService::class)->calculate($user, TenantTimezone::parse('2026-10-03'));
        $this->assertSame($revision, $again->calculation_revision);
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertSame(0, $first->fresh()->attendance_status);
        $this->assertSame(1, AttendanceLog::orderByDesc('id')->first()->attendance_status);
        tenancy()->end();
    }

    public function test_device_access_period_action_uses_separate_encrypted_commands(): void
    {
        config(['device_gateway.user_validity_enabled' => true]);
        [$company, $binding] = $this->company('VALIDITY01');
        $binding->update(['capabilities' => ['upsert_user', 'get_option', 'set_user_validity', 'query_users']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['model' => 'x 2008', 'options' => ['observed_metadata' => [
            'model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'ZMM510-NF24VB-Ver2.2.1',
        ]]]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $employee->update(['device_privilege' => 14]);
        $before = $employee->getAttributes();
        DB::table('gateway_device_users')->insert(['device_id' => $device->id, 'pin' => '0007', 'name' => $employee->name,
            'receipt_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Access administrator', 'pin' => '900', 'privilege' => 14, 'email' => 'access-admin@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Livewire::test(DeviceUsersRelationManager::class, [
            'ownerRecord' => $device, 'pageClass' => EditDevice::class,
        ])->callTableAction('deviceAccessPeriod', DeviceRosterEntry::firstOrFail(), data: [
            'request_id' => (string) Str::uuid(), 'mode' => 'dates', 'from' => '2026-10-25', 'until' => '2026-10-26',
        ])->assertHasNoTableActionErrors();
        $command = DeviceCommand::where('command_type', 'set_user_validity')->firstOrFail();
        $row = DB::table('gateway_command_outbox')->where('id', $command->gateway_command_id)->first();
        $this->assertSame(['pin' => '0007', 'mode' => 'dates', 'from' => '2026-10-25', 'until' => '2026-10-26'], ProtectedArray::decode($row->parameters));
        $this->assertStringNotContainsString('2026-10-25', $row->parameters);
        $this->assertSame($before, $employee->fresh()->getAttributes());
        $this->expectException(ValidationException::class);
        app(DeviceAccessValidity::class)->apply($employee, $device, ['mode' => 'unrestricted']);
    }

    public function test_expiration_modes_are_separate_from_daily_periods_and_clear_inactive_limits(): void
    {
        [$company, $binding] = $this->company('EXPIRYMODES01');
        $binding->update(['capabilities' => ['set_user_validity', 'query_users']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'ZMM510-NF24VB-Ver2.2.1']]]);
        $employee = User::where('pin', '0007')->firstOrFail();
        $before = $employee->getAttributes();
        $roster = DeviceRosterEntry::create(['device_id' => $device->id, 'pin' => '0007', 'receipt_id' => (string) Str::uuid(),
            'access_settings' => ['Grp' => '1', 'TZ' => '0001003200000000', 'StartDatetime' => '20261001', 'EndDatetime' => '20261031']]);
        $admin = User::create(['name' => 'Expiration admin', 'pin' => '900', 'privilege' => 14, 'email' => 'expiry-modes@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        URL::defaults(['tenant' => $company->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        foreach (['none' => [], 'dates' => ['from' => '2026-10-28', 'until' => '2026-10-29'], 'entries' => ['entries' => '3'],
            'both' => ['from' => '2026-10-28', 'until' => '2026-10-29', 'entries' => '3']] as $mode => $fields) {
            $id = (string) Str::uuid();
            Livewire::test(DeviceUsersRelationManager::class, ['ownerRecord' => $device, 'pageClass' => EditDevice::class])
                ->callTableAction('deviceAccessPeriod', $roster, data: ['request_id' => $id, 'mode' => $mode] + $fields)->assertHasNoTableActionErrors();
            $outbox = DB::table('gateway_command_outbox')->where('id', $id)->firstOrFail();
            $this->assertSame(['pin' => '0007', 'mode' => $mode] + $fields, ProtectedArray::decode($outbox->parameters));
            $readId = (string) Uuid::uuid5($id, 'user-readback');
            $read = DeviceCommand::where('gateway_command_id', $readId)->firstOrFail();
            $this->assertSame('query_users', $read->command_type);
            $this->assertSame('0001003200000000', $roster->fresh()->access_settings['TZ']);
        }
        $this->assertSame($before, $employee->fresh()->getAttributes());
        $write = DeviceCommand::where('gateway_command_id', $id)->firstOrFail();
        $write->update(['status' => 'acknowledged']);
        $read->update(['status' => 'acknowledged']);
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'EXPIRYMODES01', 'binding_version' => 1, 'kind' => 'users',
            'command_id' => $readId, 'received_at' => now()->toIso8601String(),
            'users' => [['pin' => '0007', 'access' => ['StartDatetime' => '20261028', 'EndDatetime' => '20261029']]]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('Verify on terminal', $write->fresh()->syncStatusLabel());
        $this->assertFalse($write->fresh()->data_applied);
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['users'][0]['access'] += ['Expires' => '3', 'VaildCount' => '3'];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('Synced', $write->fresh()->syncStatusLabel());
        $this->assertSame('Dates and entry limit', DeviceUserAccess::expiry($roster->fresh()));
        foreach ([['pin' => '0007', 'mode' => 'none', 'entries' => '3'], ['pin' => '0007', 'mode' => 'entries', 'entries' => '0'],
            ['pin' => '0007', 'mode' => 'entries', 'entries' => '3', 'from' => '2026-10-28'], ['pin' => '0007', 'mode' => 'both', 'entries' => '3']] as $invalid) {
            try {
                DeviceAccessValidity::parameters($invalid);
                $this->fail('Incomplete/mixed expiration mode accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_device_access_period_rejects_unsupported_firmware_and_unknown_device_user(): void
    {
        [$company, $binding] = $this->company('VALIDITY02');
        $binding->update(['capabilities' => ['upsert_user', 'get_option', 'set_user_validity']]);
        tenancy()->initialize($company);
        $employee = User::where('pin', '0007')->firstOrFail();
        $device = Device::firstOrFail();
        $device->update(['model' => 'x 2008', 'options' => ['observed_metadata' => [
            'model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'unverified',
        ]]]);
        $this->assertFalse(app(DeviceCommandService::class)->available($device, 'set_user_validity'));
        $this->assertTrue(app(DeviceCommandService::class)->available($device, 'upsert_user'));
        try {
            app(DeviceAccessValidity::class)->apply($employee, $device, ['mode' => 'period', 'from' => '2026-10-25', 'until' => '2026-10-26']);
            $this->fail('Unknown destination user accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, DeviceCommand::count());
        }
        try {
            app(DeviceCommandService::class)->request($device, 'set_user_validity', ['pin' => '0007', 'mode' => 'unrestricted']);
            $this->fail('Unverified firmware accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_existing_device_access_groups_can_be_read_edited_and_applied_on_demand(): void
    {
        config(['device_gateway.access_groups_enabled' => true]);
        [$company, $binding] = $this->company('ACCESSGROUP01');
        $binding->update(['capabilities' => [...DeviceAccessSettings::OPERATIONS, 'query_users']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'ZMM510-NF24VB-Ver2.2.1']]]);
        $admin = User::create(['name' => 'Access admin', 'pin' => '900', 'privilege' => 14, 'email' => 'access-group@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        $period = ['number' => '2'];
        foreach (DeviceAccessSettings::DAYS as $day => $label) {
            $period[$day.'Start'] = '0900';
            $period[$day.'End'] = '1700';
        }
        tenancy()->end();
        foreach (['access_periods' => $period, 'access_groups' => ['number' => '2', 'Verify' => '3', 'ValidHoliday' => '1', 'TZ' => '1;2;0;0']] as $kind => $settings) {
            $event = ['id' => (string) Str::uuid(), 'serial' => 'ACCESSGROUP01', 'binding_version' => 1, 'kind' => $kind,
                'received_at' => now()->toIso8601String(), 'access_records' => [$settings]];
            $this->deliver($event)->assertAccepted();
            (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        }
        tenancy()->initialize($company);
        $this->assertSame(2, DeviceAccessSetting::count());
        $group = DeviceAccessSetting::where('kind', 'group')->firstOrFail();
        $this->assertSame('3', $group->reported['Verify']);
        $this->assertStringNotContainsString('ValidHoliday', $group->getRawOriginal('reported'));
        $service = app(DeviceAccessSettings::class);
        $group = $service->save($device, 'group', ['name' => 'Day staff', 'revision' => $group->revision, 'settings' => $group->reported], $group);
        $this->assertSame(0, DeviceCommand::where('command_type', 'set_access_group')->count());
        $command = $service->apply($device, $group, $group->revision);
        $again = $service->apply($device, $group, $group->revision);
        $this->assertSame($command->id, $again->id);
        $outbox = DB::table('gateway_command_outbox')->where('id', $command->gateway_command_id)->firstOrFail();
        $this->assertSame(['number' => '2', 'Verify' => '3', 'ValidHoliday' => '1', 'TZ' => '1;2;0;0'], ProtectedArray::decode($outbox->parameters));
        $command->update(['status' => 'acknowledged']);
        $user = DeviceRosterEntry::create(['device_id' => $device->id, 'pin' => '0007', 'receipt_id' => (string) Str::uuid()]);
        $employeeBefore = User::where('pin', '0007')->firstOrFail()->getAttributes();
        $assignment = $service->assign($device, $user, $group->fresh());
        $row = DB::table('gateway_command_outbox')->where('id', $assignment->gateway_command_id)->firstOrFail();
        $this->assertSame(['pin' => '0007', 'number' => '2'], ProtectedArray::decode($row->parameters));
        $this->assertSame($employeeBefore, User::where('pin', '0007')->firstOrFail()->getAttributes());
        URL::defaults(['tenant' => $company->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Livewire::test(AccessGroupsRelationManager::class, [
            'ownerRecord' => $device, 'pageClass' => EditDevice::class,
        ])->assertCanSeeTableRecords([$group])
            ->callTableAction('editAccessSetting', $group, data: ['name' => 'Day staff revised', 'revision' => $group->revision, 'periods' => ['2']])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Day staff revised', $group->fresh()->name);
        $this->assertSame(1, DeviceCommand::where('command_type', 'set_access_group')->count());
        request()->attributes->remove('device-access-readback:'.tenant('id').':'.$device->id);
        $user->update(['access_settings' => ['Grp' => '2', 'TZ' => '0000000000000000']]);
        $this->assertStringContainsString('Monday 09:00–17:00', DeviceUserAccess::schedule($user->fresh()));
        $requestId = (string) Str::uuid();
        Livewire::test(DeviceUsersRelationManager::class, [
            'ownerRecord' => $device, 'pageClass' => EditDevice::class,
        ])->callTableAction('editDeviceUserAccess', $user, data: ['request_id' => $requestId, 'group_id' => $group->id, 'mode' => 'personal', 'periods' => ['2']])
            ->assertHasNoTableActionErrors();
        $row = DB::table('gateway_command_outbox')->where('id', $requestId)->firstOrFail();
        $this->assertSame(['pin' => '0007', 'number' => '2', 'period1' => '2', 'period2' => '0', 'period3' => '0'], ProtectedArray::decode($row->parameters));
        $read = DeviceCommand::where('command_type', 'query_users')->latest('id')->firstOrFail();
        $row = DB::table('gateway_command_outbox')->where('id', $read->gateway_command_id)->firstOrFail();
        $this->assertSame(['pin' => '0007', '__depends_on' => $requestId], ProtectedArray::decode($row->parameters));
        $this->assertSame('0000000000000000', $user->fresh()->access_settings['TZ']);
        $service->assign($device, $user->fresh(), $group->fresh(), ['2'], $requestId);
        $this->assertSame(1, DeviceCommand::where('gateway_command_id', $requestId)->count());
        try {
            $service->assign($device, $user->fresh(), $group->fresh(), null, $requestId);
            $this->fail('Conflicting repeated access request accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $tracked = DeviceCommand::where('gateway_command_id', $requestId)->firstOrFail();
        $this->assertSame('Queued', $tracked->syncStatusLabel());
        $tracked->update(['status' => 'acknowledged']);
        $tracked = DeviceCommand::with('accessReadback')->findOrFail($tracked->id);
        $this->assertSame($read->id, $tracked->accessReadback->id);
        $this->assertSame('Waiting for readback', $tracked->syncStatusLabel());
        $read->update(['status' => 'acknowledged']);
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'ACCESSGROUP01', 'binding_version' => 1, 'kind' => 'users',
            'command_id' => $read->gateway_command_id, 'received_at' => now()->toIso8601String(),
            'users' => [['pin' => '0007', 'access' => ['Grp' => '2', 'TZ' => '0001000200000000']]]];
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('Synced', $tracked->fresh()->syncStatusLabel());
        Livewire::test(ListDeviceCommands::class)->assertCanSeeTableRecords([$tracked])->assertSee('Synced');
        tenancy()->end();
        $event['id'] = (string) Str::uuid();
        $event['users'][0]['access']['Grp'] = '3';
        $this->deliver($event)->assertAccepted();
        (new ApplyGatewayReceipt($event['id']))->handle(app(ReceiptApplier::class));
        tenancy()->initialize($company);
        $this->assertSame('Readback needs review', $tracked->fresh()->syncStatusLabel());
        $this->assertFalse($tracked->fresh()->data_applied);
    }

    public function test_access_settings_reject_stale_edits_conflicting_revisions_and_cross_device_assignment(): void
    {
        config(['device_gateway.access_groups_enabled' => true]);
        [$company, $binding] = $this->company('ACCESSGUARD01');
        $binding->update(['capabilities' => [...DeviceAccessSettings::OPERATIONS, 'query_users']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'ZMM510-NF24VB-Ver2.2.1']]]);
        $admin = User::create(['name' => 'Access admin', 'pin' => '900', 'privilege' => 14, 'email' => 'access-guard@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        $settings = ['number' => '2', 'Verify' => '3', 'ValidHoliday' => '1', 'TZ' => '1;2;0;0'];
        $record = DeviceAccessSetting::create(['device_id' => $device->id, 'kind' => 'group', 'number' => 2, 'name' => 'Native group',
            'origin' => 'device', 'desired' => $settings, 'reported' => $settings, 'reported_at' => now()->subHour(), 'binding_id' => $binding->id, 'binding_version' => 1]);
        $service = app(DeviceAccessSettings::class);
        foreach ([['revision' => 0], ['revision' => 1]] as $input) {
            try {
                $service->save($device, 'group', $input + ['name' => 'Edit', 'settings' => $settings], $record);
                $this->fail('Stale or conflicting edit accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, $record->fresh()->revision);
            }
        }
        $record->update(['reported_at' => now()]);
        try {
            $service->save($device, 'group', ['revision' => 1, 'name' => 'Edit', 'settings' => array_replace($settings, ['Verify' => '0'])], $record);
            $this->fail('Unrelated verification rule changed.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $user = DeviceRosterEntry::create(['device_id' => $device->id, 'pin' => '0007', 'receipt_id' => (string) Str::uuid()]);
        $user->device_id = $device->id + 1;
        try {
            $service->assign($device, $user, $record);
            $this->fail('Cross-device assignment accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, DeviceCommand::count());
    }

    public function test_access_controls_follow_supported_profiles_and_incomplete_readbacks_are_rejected(): void
    {
        [$company, $binding] = $this->company('ACCESSGROUP02');
        $binding->update(['capabilities' => [...DeviceAccessSettings::OPERATIONS, 'query_users']]);
        tenancy()->initialize($company);
        $device = Device::firstOrFail();
        $device->update(['options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'ZMM510-NF24VB-Ver2.2.1']]]);
        $this->assertTrue(app(DeviceCommandService::class)->available($device, 'set_access_group'));
        $this->assertTrue(app(DeviceCommandService::class)->available($device, 'query_access_groups'));
        $this->assertTrue(AccessGroupsRelationManager::canViewForRecord($device, EditDevice::class));
        $admin = User::create(['name' => 'Access admin', 'pin' => '900', 'privilege' => 14, 'email' => 'supported-access@example.test', 'password' => 'password']);
        $this->actingAs($admin, 'web');
        URL::defaults(['tenant' => $company->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $roster = DeviceRosterEntry::create(['device_id' => $device->id, 'pin' => '0007', 'receipt_id' => (string) Str::uuid()]);
        Livewire::test(DeviceUsersRelationManager::class, ['ownerRecord' => $device, 'pageClass' => EditDevice::class])
            ->assertTableActionVisible('editDeviceUserAccess', $roster);
        $supported = $device->options;
        $device->update(['options' => ['observed_metadata' => ['model' => 'x 2008', 'platform' => 'ZMM510_TFT', 'firmware_version' => 'unknown']]]);
        $this->assertFalse(app(DeviceCommandService::class)->available($device, 'set_access_group'));
        $this->assertFalse(AccessGroupsRelationManager::canViewForRecord($device, EditDevice::class));
        request()->attributes->remove('device-user-access-sync:'.tenant('id').':'.$device->id);
        Livewire::test(DeviceUsersRelationManager::class, ['ownerRecord' => $device->fresh(), 'pageClass' => EditDevice::class])
            ->assertTableActionHidden('editDeviceUserAccess', $roster);
        try {
            app(DeviceCommandService::class)->request($device, 'set_user_access_group', ['pin' => '0007', 'number' => '2']);
            $this->fail('Unsupported firmware accepted an access write.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $device->update(['options' => $supported]);
        config(['device_gateway.access_groups_enabled' => false]);
        $this->assertFalse(app(DeviceCommandService::class)->available($device, 'set_access_group'));
        config(['device_gateway.access_groups_enabled' => true]);
        $binding->update(['synced_at' => null]);
        $this->assertFalse(AccessGroupsRelationManager::canViewForRecord($device, EditDevice::class));
        tenancy()->end();
        $event = ['id' => (string) Str::uuid(), 'serial' => 'ACCESSGROUP02', 'binding_version' => 1, 'kind' => 'access_groups',
            'received_at' => now()->toIso8601String(), 'access_records' => [['number' => '2', 'TZ' => '1;2;0;0']]];
        $this->deliver($event)->assertUnprocessable();
        tenancy()->initialize($company);
        $this->assertSame(0, DeviceAccessSetting::count());
    }

    public function test_device_access_period_validation_rejects_partial_dates_and_identity_fields(): void
    {
        foreach ([
            ['pin' => '7', 'mode' => 'period', 'from' => '2026-10-26', 'until' => '2026-10-25'],
            ['pin' => '7', 'mode' => 'period', 'from' => '2026-02-30', 'until' => '2026-10-25'],
            ['pin' => '7', 'mode' => 'period', 'from' => '2026-10-25 09:00:00', 'until' => '2026-10-26'],
            ['pin' => '7', 'mode' => 'unrestricted', 'privilege' => '0'],
            ['pin' => '7', 'mode' => 'unrestricted', 'from' => '2026-10-25'],
        ] as $input) {
            try {
                DeviceAccessValidity::parameters($input);
                $this->fail('Invalid access-period parameters accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
