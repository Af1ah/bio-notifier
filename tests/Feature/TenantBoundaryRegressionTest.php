<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\OrganisationSettings;
use App\Jobs\ProcessEbioWebhookJob;
use App\Jobs\PushEbioUserJob;
use App\Jobs\SyncEbioUsersJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\LeaveType;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WebhookReceipt;
use App\Services\EbioSoapService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

class TenantBoundaryRegressionTest extends TestCase
{
    private array $organisations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => storage_path('app')],
            'tenancy.filesystem.disks' => ['local', 'public', 'tmp-for-tests'],
            'tenancy.filesystem.root_override.tmp-for-tests' => '%storage_path%/app/']);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->organisations as $organisation) {
            $organisation->delete();
            File::deleteDirectory(base_path('storage/tenant'.$organisation->id));
        }
        parent::tearDown();
    }

    private function organisation(string $name): Organisation
    {
        $organisation = Organisation::create(['name' => $name, 'shortname' => $name, 'device_integration' => 'ebio',
            'ebio_url' => 'https://ebio.example.invalid', 'ebio_soap_username' => 'name&user', 'ebio_soap_password' => 'pass<&"word']);
        $this->organisations[] = $organisation;

        return $organisation;
    }

    public function test_native_command_notifications_stay_private_with_overlapping_tenant_user_ids(): void
    {
        $saved = [];
        foreach (['notice-a', 'notice-b'] as $name) {
            $company = $this->organisation($name);
            tenancy()->initialize($company);
            $actor = User::create(['id' => 77, 'name' => 'Operator', 'pin' => 'operator', 'email' => 'operator@example.test', 'password' => 'password', 'privilege' => 14]);
            auth('web')->setUser($actor);
            $device = Device::create(['serial_number' => 'SAME-SERIAL', 'name' => $name]);
            $command = DeviceCommand::create(['device_id' => $device->id, 'command_type' => 'reboot', 'command_content' => 'Reboot', 'status' => 'pending']);
            $command->markAsAcknowledged();
            $notification = $actor->notifications()->firstOrFail();
            $this->assertStringContainsString('/'.$name.'/admin/device-commands/', $notification->data['actions'][0]['url']);
            $saved[] = [$company, $notification->id];
            tenancy()->end();
            auth()->forgetGuards();
        }
        foreach ($saved as $index => [$company, $notificationId]) {
            tenancy()->initialize($company);
            $actor = User::findOrFail(77);
            $this->assertSame([$notificationId], $actor->notifications()->pluck('id')->all());
            $foreignId = $saved[1 - $index][1];
            $this->assertFalse($actor->notifications()->where('id', $foreignId)->exists());
            tenancy()->end();
        }
    }

    public function test_saved_leave_summaries_stay_in_the_owning_tenant_database(): void
    {
        $expected = [];
        foreach (['summary-a' => 4, 'summary-b' => 8] as $name => $units) {
            $company = $this->organisation($name);
            tenancy()->initialize($company);
            $employee = User::create(['pin' => 'same-pin', 'name' => 'Employee']);
            $actor = User::create(['pin' => 'hr', 'name' => 'HR', 'privilege' => 14]);
            $type = LeaveType::create(['name' => 'Summary leave', 'code' => 'SUM', 'is_paid' => true, 'active' => true]);
            app(LeaveBalanceService::class)->opening($employee, $type, $units, today()->subDay(), today()->addMonth(), 'Reviewed opening', $actor, 'same-key');
            $balance = app(LeaveBalanceSummaryService::class)->get($employee, $type->id, today());
            $this->assertSame($units, $balance['available_half_units']);
            $expected[] = [$company, $employee->id, $type->id, $units];
            tenancy()->end();
        }
        foreach ($expected as [$company, $employeeId, $typeId, $units]) {
            tenancy()->initialize($company);
            $balance = app(LeaveBalanceSummaryService::class)->get(User::findOrFail($employeeId), $typeId, today());
            $this->assertSame($units, $balance['available_half_units']);
            tenancy()->end();
        }
    }

    public function test_device_sync_preserves_the_panel_transaction_and_commits_only_to_its_tenant(): void
    {
        $a = $this->organisation('sync-a');
        $b = $this->organisation('sync-b');
        TenantContext::run($b, fn () => Device::create(['serial_number' => 'OTHER-1', 'name' => 'Other company']));
        $list = collect(range(1, 21))->map(fn ($n) => "Device {$n},SYNC-{$n},Branch {$n}")->implode(';').';';
        Http::fake(fn ($request) => Http::response(
            str_contains($request->body(), '<GetDeviceList ')
                ? '<GetDeviceListResult>'.$list.'</GetDeviceListResult>'
                : '<GetDeviceLastPingResult>2026-10-31 10:00:00</GetDeviceLastPingResult>', 200));
        tenancy()->initialize($a);
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $result = (new EbioSoapService)->syncDevices($a);
            $this->assertSame(21, $result['synced']);
            $this->assertSame($connection, DB::connection());
            $this->assertSame(1, $connection->transactionLevel());
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        tenancy()->end();
        $this->assertSame(21, TenantContext::run($a, fn () => Device::count()));
        TenantContext::run($b, function () {
            $this->assertSame(['OTHER-1'], Device::pluck('serial_number')->all());
            $this->assertSame('Other company', Device::first()->name);
        });
        $this->assertNull(tenant());
    }

    public function test_same_tenant_nested_context_preserves_rollback_and_exception(): void
    {
        $a = $this->organisation('nested-a');
        tenancy()->initialize($a);
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            // A fresh model instance with the same tenant key must not reconnect.
            $copy = Organisation::findOrFail($a->id);
            try {
                TenantContext::run($copy, function () use ($a) {
                    TenantContext::run($a, fn () => Device::create(['serial_number' => 'ROLLBACK-1', 'name' => 'Temporary']));
                    throw new \RuntimeException('expected failure');
                });
                $this->fail('The callback exception must propagate.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('expected failure', $exception->getMessage());
            }
            $this->assertSame($connection, DB::connection());
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame(1, Device::count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $this->assertSame(0, Device::count());
        $this->assertSame($a->id, tenant()->getTenantKey());
    }

    public function test_switching_tenants_during_a_transaction_is_rejected_without_losing_writes(): void
    {
        $a = $this->organisation('guard-a');
        $b = $this->organisation('guard-b');
        tenancy()->initialize($a);
        $connection = DB::connection();
        $connection->beginTransaction();
        $called = false;
        try {
            Device::create(['serial_number' => 'GUARDED-1', 'name' => 'Pending write']);
            try {
                TenantContext::run($b, function () use (&$called) {
                    $called = true;
                });
                $this->fail('Switching with an open tenant transaction must be rejected.');
            } catch (\LogicException $exception) {
                $this->assertSame('Finish the active tenant transaction before switching organisations.', $exception->getMessage());
            }
            $this->assertFalse($called);
            $this->assertSame($a->id, tenant()->getTenantKey());
            $this->assertSame($connection, DB::connection());
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame(1, Device::count());
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        tenancy()->end();
        $this->assertSame(['GUARDED-1'], TenantContext::run($a, fn () => Device::pluck('serial_number')->all()));
        $this->assertSame(0, TenantContext::run($b, fn () => Device::count()));
    }

    public function test_switching_tenants_restores_the_original_context_after_failure(): void
    {
        $a = $this->organisation('restore-a');
        $b = $this->organisation('restore-b');
        tenancy()->initialize($a);
        Device::create(['serial_number' => 'A-1', 'name' => 'Company A']);
        try {
            TenantContext::run($b, function () use ($b) {
                $this->assertSame($b->id, tenant()->getTenantKey());
                $this->assertSame(0, Device::count());
                throw new \RuntimeException('switch failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('switch failure', $exception->getMessage());
        }
        $this->assertSame($a->id, tenant()->getTenantKey());
        $this->assertSame(['A-1'], Device::pluck('serial_number')->all());
    }

    public function test_ebio_setup_displays_only_the_current_company_credential(): void
    {
        $a = $this->organisation('setup-a');
        $b = $this->organisation('setup-b');
        config(['app.url' => 'https://noti.example.test']);
        tenancy()->initialize($a);
        URL::defaults(['tenant' => $a->shortname]);
        $admin = User::create(['name' => 'Setup admin', 'pin' => 'setup-admin', 'privilege' => 14, 'email' => 'setup@example.test', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        Livewire::test(OrganisationSettings::class)
            ->assertSee('eBio setup')->assertSee('Copy URL')->assertSee('Success')
            ->assertSee('https://noti.example.test/api/ebio/webhook/'.$a->ebio_webhook_token, escape: false)
            ->assertDontSee($b->ebio_webhook_token);
        $a->update(['device_integration' => 'adms_gateway']);
        Livewire::test(OrganisationSettings::class)
            ->assertDontSee('eBio setup')->assertDontSee($a->ebio_webhook_token);
        $a->update(['device_integration' => 'ebio', 'ebio_webhook_token' => null]);
        Livewire::test(OrganisationSettings::class)
            ->assertSee('Webhook setup is not ready.')
            ->assertDontSee('https://noti.example.test/api/ebio/webhook/'.$a->id, escape: false);
        $admin->update(['privilege' => 0]);
        Livewire::test(OrganisationSettings::class)->assertForbidden();
        tenancy()->end();
    }

    public function test_database_cache_reads_locks_and_flush_stay_inside_the_tenant(): void
    {
        config(['cache.default' => 'database', 'cache.stores.database.connection' => 'sqlite']);
        app('cache')->forgetDriver('database');
        Cache::put('overlap', 'central', 60);
        $a = $this->organisation('cache-a');
        $b = $this->organisation('cache-b');
        TenantContext::run($a, function () {
            $this->assertNull(Cache::get('overlap'));
            Cache::put('overlap', 'a', 60);
            $this->assertTrue(Cache::lock('same-lock', 60)->get());
        });
        TenantContext::run($b, function () {
            $this->assertNull(Cache::get('overlap'));
            Cache::put('overlap', 'b', 60);
            $this->assertTrue(Cache::lock('same-lock', 60)->get());
            Cache::flush();
            $this->assertNull(Cache::get('overlap'));
        });
        TenantContext::run($a, fn () => $this->assertSame('a', Cache::store('database')->get('overlap')));
        $this->assertSame('central', Cache::get('overlap'));
    }

    public function test_signed_upload_scope_cannot_be_changed_and_does_not_require_a_referer(): void
    {
        $a = $this->organisation('upload-a');
        $b = $this->organisation('upload-b');
        $url = TenantContext::run($a, fn () => app(GenerateSignedUploadUrl::class)->forLocal());
        $this->assertStringContainsString(EndpointResolver::uploadPath(), $url);
        $this->assertStringContainsString($a->id, $url);
        $file = UploadedFile::fake()->createWithContent('employees.csv', "pin,name\n1,A\n");
        $this->post($url, ['files' => [$file]])->assertOk();
        tenancy()->end();
        $this->withHeader('Referer', url('/'.$b->shortname.'/admin'))
            ->post($url, ['files' => [$file]])->assertForbidden();
        tenancy()->end();
        $this->post(str_replace($a->id, $b->id, $url), ['files' => [$file]])->assertForbidden();
        $this->post(EndpointResolver::uploadPath(), ['files' => [$file]])->assertForbidden();
    }

    public function test_preview_signature_uses_the_same_tenant_storage_as_the_upload(): void
    {
        $a = $this->organisation('preview-a');
        $b = $this->organisation('preview-b');
        $url = TenantContext::run($a, function () {
            Storage::disk('tmp-for-tests')->put('livewire-tmp/probe.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP4sAAAAASUVORK5CYII='));

            return app(GenerateSignedUploadUrl::class)->signedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'probe.png']);
        });
        $this->get($url)->assertOk();
        tenancy()->end();
        $this->withHeader('Referer', url('/'.$b->shortname.'/admin'))->get($url)->assertForbidden();
        tenancy()->end();
        $this->get(str_replace($a->id, $b->id, $url))->assertForbidden();
    }

    public function test_provider_change_blocks_new_webhooks_and_retains_queued_evidence_as_failed(): void
    {
        Queue::fake();
        $a = $this->organisation('provider-a');
        $payload = [['EmployeeCode' => '1', 'LogDate' => '2026-10-01 09:00:00']];
        $this->postJson('/api/ebio/webhook/'.$a->ebio_webhook_token, $payload)->assertOk();
        $receipt = WebhookReceipt::firstOrFail();
        $a->update(['device_integration' => 'adms_gateway']);
        $this->postJson('/api/ebio/webhook/'.$a->ebio_webhook_token, $payload)->assertForbidden();
        try {
            (new ProcessEbioWebhookJob($a, [], $receipt->id))->handle();
            $this->fail('A queued eBio batch must check the current provider.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('failed', $receipt->fresh()->state);
            $this->assertSame($payload, $receipt->fresh()->payload);
        }
        $this->assertFalse(tenancy()->initialized);
        TenantContext::run($a, fn () => $this->assertDatabaseCount('attendance_logs', 0));
    }

    public function test_soap_sync_escapes_credentials_parses_namespaces_and_restores_context(): void
    {
        $a = $this->organisation('soap-a');
        $b = $this->organisation('soap-b');
        Http::fake(['*' => Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><x:GetEmployeeCodesResult xmlns:x="http://tempuri.org/"></x:GetEmployeeCodesResult></s:Body></s:Envelope>')]);
        (new SyncEbioUsersJob($a))->handle(app(EbioSoapService::class));
        $this->assertFalse(tenancy()->initialized);
        Http::assertSent(function ($request) {
            $document = simplexml_load_string($request->body());

            return (string) $document->xpath('//*[local-name()="Password"]')[0] === 'pass<&"word';
        });
        tenancy()->initialize($b);
        app(EbioSoapService::class)->syncUsers($a);
        $this->assertSame($b->id, tenant('id'));
    }

    public function test_soap_vendor_errors_faults_and_missing_results_fail_and_restore_context(): void
    {
        $a = $this->organisation('error-a');
        foreach (['<GetEmployeeCodesResult>error</GetEmployeeCodesResult>', '<s:Envelope xmlns:s="urn:soap"><s:Body><s:Fault /></s:Body></s:Envelope>', '<unexpected />'] as $body) {
            Http::fake(['*' => Http::response($body)]);
            try {
                (new SyncEbioUsersJob($a))->handle(app(EbioSoapService::class));
                $this->fail('A rejected or invalid SOAP response must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertFalse(tenancy()->initialized);
            }
        }
    }

    public function test_failed_sync_restores_the_previous_tenant(): void
    {
        $a = $this->organisation('failed-a');
        $b = $this->organisation('previous-b');
        tenancy()->initialize($b);
        Http::fake(['*' => Http::response('<GetEmployeeCodesResult>error</GetEmployeeCodesResult>')]);
        try {
            (new SyncEbioUsersJob($a))->handle(app(EbioSoapService::class));
            $this->fail('The vendor error must not be acknowledged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($b->id, tenant('id'));
        }
    }

    public function test_push_rejection_fails_the_queue_job_instead_of_succeeding_silently(): void
    {
        $a = $this->organisation('push-a');
        $id = TenantContext::run($a, fn () => User::create(['pin' => '1', 'name' => 'Employee'])->id);
        $service = \Mockery::mock(EbioSoapService::class);
        $service->shouldReceive('pushUser')->once()->andReturn(false);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('fail')->once()->with(\Mockery::on(fn ($error) => $error instanceof \RuntimeException));
        $job = new PushEbioUserJob($a, $id);
        $job->setJob($queueJob);
        $job->handle($service);
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_face_enrollment_keeps_the_legacy_fallback_and_accepts_namespaced_void_results(): void
    {
        $a = $this->organisation('face-a');
        Http::fakeSequence()->push('<fault />', 500)
            ->push('<s:Envelope xmlns:s="urn:soap"><s:Body><x:DeviceCommand_EnrollFaceExResult xmlns:x="http://tempuri.org/" /></s:Body></s:Envelope>', 200);
        $this->assertTrue(app(EbioSoapService::class)->enrollFace($a, 'serial&1', 'pin<1'));
        Http::assertSentCount(2);
    }
}
