<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\Integrations;
use App\Jobs\ProcessEbioWebhookJob;
use App\Jobs\PushFrappeCheckins;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\FrappeSetting;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Integrations\FrappeCheckins;
use App\Services\Support\TenantTimezone;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class OrganisationSettingsPagesTest extends TestCase
{
    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->organisation = Organisation::create([
            'name' => 'Settings fixture',
            'shortname' => 'settings-fixture',
            'device_integration' => 'ebio',
        ]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        $this->organisation->delete();
        parent::tearDown();
    }

    public function test_settings_pages_require_a_tenant_admin_and_keep_the_existing_route(): void
    {
        $this->assertSame('resources/css/filament/tenant/theme.css', Filament::getPanel('tenant')->getViteTheme());
        $base = '/settings-fixture/admin/organisation-settings';
        $this->get($base.'/integrations')->assertRedirect('/settings-fixture/admin/login');
        $this->post($base.'/backups/download')->assertRedirect('/settings-fixture/admin/login');

        tenancy()->initialize($this->organisation);
        $admin = User::create([
            'name' => 'Settings admin',
            'pin' => '901',
            'email' => 'settings-admin@example.test',
            'password' => 'test-only-password',
            'privilege' => 14,
        ]);
        $this->actingAs($admin, 'web');

        $this->get($base)->assertOk()->assertSee('fi-page-sub-navigation-sidebar', false)
            ->assertSee('wire:navigate', false)->assertSee($base.'/backups')->assertSee($base.'/integrations');
        $this->get($base.'/backups')->assertOk()->assertSee('Download SQL');
        $this->get($base.'/integrations')->assertOk()->assertSee('Frappe HR');
    }

    public function test_frappe_credentials_are_encrypted_and_never_rehydrated(): void
    {
        tenancy()->initialize($this->organisation);
        URL::defaults(['tenant' => $this->organisation->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::create([
            'name' => 'Settings admin',
            'pin' => '902',
            'email' => 'settings-admin@example.test',
            'password' => 'test-only-password',
            'privilege' => 14,
        ]), 'web');

        Livewire::test(Integrations::class)
            ->assertSee('Not connected')->assertSee('Connect')
            ->call('connect')->assertDispatched('open-modal', id: 'frappe-connection')
            ->fillForm(['hostname' => 'HR.Example.com', 'api_key' => 'samplekey', 'api_secret' => 'samplesecret', 'enabled' => true])
            ->call('save')->assertHasNoFormErrors()
            ->assertDispatched('close-modal', id: 'frappe-connection')
            ->assertSee('Sync enabled')->assertSee('Edit connection')->assertSee('hr.example.com')
            ->assertSet('data.api_key', null)->assertSet('data.api_secret', null);

        $settings = FrappeSetting::findOrFail(1);
        $this->assertEquals('hr.example.com', $settings->hostname);
        $this->assertSame('samplekey', $settings->api_key);
        $this->assertNotSame('samplesecret', $settings->getRawOriginal('api_secret'));
        $this->assertArrayNotHasKey('api_secret', $settings->toArray());

        Livewire::test(Integrations::class)
            ->assertSet('data.api_secret', null)
            ->fillForm(['api_key' => 'unsavedkey', 'api_secret' => 'unsavedsecret'])
            ->call('connect')->assertSet('data.api_key', null)->assertSet('data.api_secret', null)
            ->fillForm(['hostname' => 'elsewhere.example.com'])
            ->call('save')->assertHasFormErrors(['api_key']);
        $this->assertEquals('hr.example.com', $settings->fresh()->hostname);
    }

    public function test_health_results_are_cached_throttled_and_invalidated_when_connection_changes(): void
    {
        tenancy()->initialize($this->organisation);
        URL::defaults(['tenant' => $this->organisation->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::create([
            'name' => 'Settings admin', 'pin' => '906', 'privilege' => 14,
            'email' => 'health-admin@example.test', 'password' => 'test-only-password',
        ]), 'web');
        $settings = FrappeSetting::create([
            'id' => 1, 'hostname' => '127.0.0.1', 'api_key' => 'testkey', 'api_secret' => 'testsecret', 'enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake();

        $page = Livewire::test(Integrations::class)->assertSee('Health not checked')
            ->call('checkHealth')->assertNotified('Hostname must resolve to a public server.');
        $this->assertSame('destination', $page->instance()->deliverySummary()['health']['status']);
        $page->call('checkHealth')->assertNotified('Wait a few seconds before checking again.');
        Livewire::test(Integrations::class)->assertSee('Hostname must resolve to a public server.');
        $settings->update(['api_secret' => 'changedsecret']);
        $this->assertNull($page->instance()->deliverySummary()['health']);
        $this->travel(16)->seconds();
        $page->call('checkHealth');
        $this->assertSame('destination', $page->instance()->deliverySummary()['health']['status']);
        $this->travel(301)->seconds();
        $this->assertNull($page->instance()->deliverySummary()['health']);
        $this->travelBack();
        Http::assertNothingSent();
    }

    public function test_older_punches_are_queued_once_with_date_defaults_and_last_sync_details(): void
    {
        tenancy()->initialize($this->organisation);
        URL::defaults(['tenant' => $this->organisation->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::create([
            'name' => 'History admin', 'pin' => '907', 'privilege' => 14,
            'email' => 'history-admin@example.test', 'password' => 'test-only-password',
        ]), 'web');
        $settings = FrappeSetting::create([
            'id' => 1, 'hostname' => 'hr.example.com', 'api_key' => 'testkey', 'api_secret' => 'testsecret', 'enabled' => true,
        ]);
        $device = Device::create(['name' => 'History device', 'serial_number' => 'HISTORY01']);
        foreach (['2020-01-01 00:00:00', '2020-01-31 23:59:59', '2020-02-01 00:00:00'] as $date) {
            DB::table('attendance_logs')->insert(['device_id' => $device->id, 'pin' => '0042', 'punched_at' => $date]);
        }
        Http::preventStrayRequests();
        Http::fake();
        $page = Livewire::test(Integrations::class)->mountAction('syncHistory')
            ->assertActionDataSet(['from' => '2020-01-01', 'to' => today(TenantTimezone::get())->toDateString()]);
        $page->fillForm(['from' => '2020-01-01', 'to' => '2020-01-31'])->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('2 punches queued');
        $this->assertDatabaseCount('frappe_checkin_deliveries', 2);
        $this->assertSame(0, app(FrappeCheckins::class)->queueHistory('2020-01-01', '2020-01-31'));
        $ids = DB::table('frappe_checkin_deliveries')->orderBy('attendance_log_id')->pluck('attendance_log_id');
        DB::table('frappe_checkin_deliveries')->where('attendance_log_id', $ids[0])->update([
            'sent_at' => now(), 'retry_at' => null, 'outcome' => 'verified', 'attempts' => 1,
        ]);
        DB::table('frappe_checkin_deliveries')->where('attendance_log_id', $ids[1])->update([
            'error' => 'employee_not_found', 'retry_at' => null, 'attempts' => 1,
        ]);
        $this->assertSame(0, app(FrappeCheckins::class)->queueHistory('2020-01-01', '2020-01-31'));
        $summary = $page->instance()->deliverySummary();
        $this->assertEquals($ids[0], $summary['last_synced']->attendance_log_id);
        $this->assertSame('0042', $summary['last_synced']->pin);
        $page->call('$refresh')->assertSee('Last synced punch')->assertSee('Employee PIN 0042')
            ->assertSee('No matching employee in Frappe.');
        $this->assertDatabaseHas('frappe_checkin_deliveries', ['attendance_log_id' => $ids[0], 'outcome' => 'verified', 'attempts' => 1]);
        $this->assertDatabaseHas('frappe_checkin_deliveries', ['attendance_log_id' => $ids[1], 'error' => 'employee_not_found', 'attempts' => 1]);
        $settings->update(['enabled' => false]);
        try {
            app(FrappeCheckins::class)->queueHistory('2020-01-01', '2020-02-01');
            $this->fail('Disabled sync accepted older punches.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('frappe_checkin_deliveries', 2);
        }
        Http::assertNothingSent();
    }

    public function test_history_queue_is_bounded_and_can_resume_without_duplicates(): void
    {
        $this->organisation->run(function () {
            FrappeSetting::create(['id' => 1, 'hostname' => 'hr.example.com', 'api_key' => 'testkey', 'api_secret' => 'testsecret', 'enabled' => true]);
            $device = Device::create(['name' => 'History batch', 'serial_number' => 'BATCH01']);
            foreach (array_chunk(range(1, 5001), 200) as $pins) {
                DB::table('attendance_logs')->insert(array_map(fn ($pin) => [
                    'device_id' => $device->id, 'pin' => 'HISTORY-'.$pin, 'punched_at' => '2020-01-01 09:00:00',
                ], $pins));
            }
            $service = app(FrappeCheckins::class);
            $this->assertSame(5000, $service->queueHistory('2020-01-01', '2020-01-01'));
            $this->assertSame(1, $service->queueHistory('2020-01-01', '2020-01-01'));
            $this->assertSame(0, $service->queueHistory('2020-01-01', '2020-01-01'));
            $this->assertDatabaseCount('frappe_checkin_deliveries', 5001);
            $this->assertDatabaseCount('attendance_logs', 5001);
        });
    }

    public function test_ebio_punches_create_one_delivery_and_unsafe_destinations_are_blocked(): void
    {
        $this->organisation->run(fn () => FrappeSetting::create([
            'id' => 1,
            'hostname' => '127.0.0.1',
            'api_key' => 'testkey',
            'api_secret' => 'testsecret',
            'enabled' => true,
        ]));

        $job = new ProcessEbioWebhookJob($this->organisation, [[
            'EmployeeCode' => '0042',
            'LogDate' => '2026-11-23 08:12:00',
            'SerialNumber' => 'device-A',
            'Direction' => 'OUT',
        ]]);
        $job->handle();
        $job->handle();

        $this->organisation->run(function () {
            $log = AttendanceLog::sole();
            $this->assertDatabaseCount('frappe_checkin_deliveries', 1);
            $this->assertSame([
                'employee_field_value' => '0042',
                'timestamp' => '2026-11-23 08:12:00.000000',
                'device_id' => (string) $log->device_id,
                'log_type' => 'OUT',
            ], FrappeCheckins::payload($log));
        });

        Queue::fake();
        $this->artisan('frappe:queue-checkins')->assertSuccessful();
        Queue::assertPushedOn('exports-notifications', PushFrappeCheckins::class, fn ($job) => $job->tenantId === $this->organisation->id);

        Http::fake();
        (new PushFrappeCheckins($this->organisation->id))->handle(app(FrappeCheckins::class));
        Http::assertNothingSent();

        $this->organisation->run(fn () => $this->assertDatabaseHas('frappe_checkin_deliveries', [
            'attempts' => 1,
            'error' => 'destination',
            'retry_at' => null,
            'sent_at' => null,
        ]));
    }

    public function test_resend_is_available_in_integrations_and_queues_only_the_failed_punch(): void
    {
        $this->organisation->run(fn () => FrappeSetting::create([
            'id' => 1,
            'hostname' => 'hr.example.test',
            'api_key' => 'testkey',
            'api_secret' => 'testsecret',
            'enabled' => true,
        ]));
        (new ProcessEbioWebhookJob($this->organisation, [
            ['EmployeeCode' => '0042', 'LogDate' => '2026-11-23 08:12:00', 'SerialNumber' => 'A', 'Direction' => 'IN'],
            ['EmployeeCode' => '0042', 'LogDate' => '2026-11-23 17:12:00', 'SerialNumber' => 'A', 'Direction' => 'OUT'],
        ]))->handle();

        tenancy()->initialize($this->organisation);
        URL::defaults(['tenant' => $this->organisation->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::create([
            'name' => 'Settings admin', 'pin' => '905', 'privilege' => 14,
            'email' => 'settings-admin@example.test', 'password' => 'test-only-password',
        ]), 'web');

        [$failed, $sent] = AttendanceLog::orderBy('id')->pluck('id')->all();
        DB::table('frappe_checkin_deliveries')->where('attendance_log_id', $failed)->update([
            'retry_at' => null, 'attempts' => 6, 'error' => 'http_417', 'last_attempt_at' => now(),
        ]);
        DB::table('frappe_checkin_deliveries')->where('attendance_log_id', $sent)->update([
            'retry_at' => null, 'attempts' => 1, 'outcome' => 'verified', 'sent_at' => now(), 'last_attempt_at' => now(),
        ]);

        Livewire::test(Integrations::class)
            ->assertSee('Verified in Frappe')->assertSee('Resend')
            ->call('retryFailed', $failed)->assertHasNoErrors()
            ->call('retryFailed', $sent)->assertHasNoErrors();

        $this->assertDatabaseHas('frappe_checkin_deliveries', ['attendance_log_id' => $failed, 'attempts' => 0, 'error' => null]);
        $this->assertDatabaseHas('frappe_checkin_deliveries', ['attendance_log_id' => $sent, 'attempts' => 1, 'outcome' => 'verified']);
    }

    public function test_frappe_push_uses_the_documented_endpoint_and_handles_duplicate_validation(): void
    {
        Http::fakeSequence()
            ->push(['message' => ['name' => 'CHECKIN-1', 'time' => '2026-11-23 08:12:00', 'device_id' => '1']])
            ->push(['exc_type' => 'ValidationError', '_server_messages' => json_encode([
                json_encode(['message' => 'This employee already has a log with the same timestamp.']),
            ])], 417)
            ->pushStatus(302)
            ->push([], 429, ['Retry-After' => '180']);

        $this->organisation->run(function () {
            $settings = new FrappeSetting(['hostname' => 'hr.example.com', 'api_key' => 'key', 'api_secret' => 'secret']);
            $log = new AttendanceLog(['pin' => '0003', 'device_id' => 1, 'punched_at' => '2026-11-23 08:12:00', 'status' => 255]);
            $frappe = app(FrappeCheckins::class);

            $this->assertSame('verified', $frappe->push($settings, $log, '93.184.215.14')->outcome);
            $this->assertSame('existing', $frappe->push($settings, $log, '93.184.215.14')->outcome);
            $this->assertSame('http_302', $frappe->push($settings, $log, '93.184.215.14')->error);
            $rateLimit = $frappe->push($settings, $log, '93.184.215.14');
            $this->assertSame('http_429', $rateLimit->error);
            $this->assertSame(180, $rateLimit->cooldownSeconds);
        });

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://hr.example.com/api/method/hrms.hr.doctype.employee_checkin.employee_checkin.add_log_based_on_employee_field'
            && $request->hasHeader('Authorization', 'token key:secret')
            && $request->data() === [
                'employee_field_value' => '0003',
                'timestamp' => '2026-11-23 08:12:00.000000',
                'device_id' => '1',
            ]);
    }

    public function test_sqlite_download_contains_restorable_rows_from_the_current_tenant(): void
    {
        if (! (new ExecutableFinder)->find('sqlite3')) {
            $this->markTestSkipped('Requires the sqlite3 CLI.');
        }

        tenancy()->initialize($this->organisation);
        $admin = User::create([
            'name' => 'Backup admin',
            'pin' => '903',
            'email' => 'backup-admin@example.test',
            'password' => 'test-only-password',
            'privilege' => 14,
        ]);
        $this->actingAs($admin, 'web');

        $response = $this->post('/settings-fixture/admin/organisation-settings/backups/download')
            ->assertOk()->assertDownload()->assertHeader('Content-Type', 'application/sql');
        $file = $response->baseResponse->getFile()->getPathname();
        try {
            $db = new \PDO('sqlite::memory:');
            $db->exec(file_get_contents($file));
            $this->assertSame('backup-admin@example.test', $db->query("SELECT email FROM users WHERE pin='903'")->fetchColumn());
            $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='organisations'")->fetchColumn());
        } finally {
            unlink($file);
        }
    }
}
