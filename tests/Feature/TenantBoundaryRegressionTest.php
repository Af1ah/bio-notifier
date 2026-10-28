<?php

namespace Tests\Feature;

use App\Jobs\ProcessEbioWebhookJob;
use App\Jobs\PushEbioUserJob;
use App\Jobs\SyncEbioUsersJob;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WebhookReceipt;
use App\Services\EbioSoapService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

    public function test_ebio_setup_displays_only_the_current_company_credential(): void
    {
        $a = $this->organisation('setup-a');
        $b = $this->organisation('setup-b');
        config(['app.url' => 'https://noti.example.test']);
        tenancy()->initialize($a);
        URL::defaults(['tenant' => $a->shortname]);
        $admin = User::create(['name' => 'Setup admin', 'pin' => 'setup-admin', 'privilege' => 14, 'email' => 'setup@example.test', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('tenant'));
        \Filament\Facades\Filament::bootCurrentPanel();
        \Livewire\Livewire::test(\App\Filament\Tenant\Pages\OrganisationSettings::class)
            ->assertSee('eBio setup')->assertSee('Copy URL')->assertSee('Success')
            ->assertSee('https://noti.example.test/api/ebio/webhook/'.$a->ebio_webhook_token, escape: false)
            ->assertDontSee($b->ebio_webhook_token);
        $a->update(['device_integration' => 'adms_gateway']);
        \Livewire\Livewire::test(\App\Filament\Tenant\Pages\OrganisationSettings::class)
            ->assertDontSee('eBio setup')->assertDontSee($a->ebio_webhook_token);
        $a->update(['device_integration' => 'ebio', 'ebio_webhook_token' => null]);
        \Livewire\Livewire::test(\App\Filament\Tenant\Pages\OrganisationSettings::class)
            ->assertSee('Webhook setup is not ready.')
            ->assertDontSee('https://noti.example.test/api/ebio/webhook/'.$a->id, escape: false);
        $admin->update(['privilege' => 0]);
        \Livewire\Livewire::test(\App\Filament\Tenant\Pages\OrganisationSettings::class)->assertForbidden();
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
        $url = TenantContext::run($a, fn () => URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)));
        $this->assertStringContainsString($a->id, $url);
        $file = UploadedFile::fake()->createWithContent('employees.csv', "pin,name\n1,A\n");
        $this->post($url, ['files' => [$file]])->assertOk();
        tenancy()->end();
        $this->withHeader('Referer', url('/'.$b->shortname.'/admin'))
            ->post($url, ['files' => [$file]])->assertForbidden();
        tenancy()->end();
        $this->post(str_replace($a->id, $b->id, $url), ['files' => [$file]])->assertForbidden();
        $this->post('/livewire/upload-file', ['files' => [$file]])->assertForbidden();
    }

    public function test_preview_signature_uses_the_same_tenant_storage_as_the_upload(): void
    {
        $a = $this->organisation('preview-a');
        $b = $this->organisation('preview-b');
        $url = TenantContext::run($a, function () {
            Storage::disk('tmp-for-tests')->put('livewire-tmp/probe.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP4sAAAAASUVORK5CYII='));

            return URL::temporarySignedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'probe.png']);
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
