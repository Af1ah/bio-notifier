<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\Backups;
use App\Jobs\RunOrganisationBackups;
use App\Models\Organisation;
use App\Models\OrganisationBackupRun;
use App\Models\OrganisationBackupSetting;
use App\Models\User;
use App\Services\Backups\BackupSchedule;
use App\Services\Backups\GoogleDrive;
use App\Services\Tenancy\TenantSqlBackup;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class GoogleDriveBackupsTest extends TestCase
{
    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        config([
            'backups.google_client_id' => 'drive-test-client',
            'backups.google_client_secret' => 'drive-test-client-secret',
            'backups.google_redirect_uri' => 'http://localhost/oauth/google-drive/callback',
        ]);
        $this->organisation = Organisation::create(['name' => 'Backup fixture', 'shortname' => 'backup-fixture']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        $this->organisation->delete();
        parent::tearDown();
    }

    public function test_google_authorization_is_tied_to_the_tenant_admin_session_and_used_once(): void
    {
        $this->signIn();
        $start = $this->post('/backup-fixture/admin/organisation-settings/backups/google/connect')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $params);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', $params['scope']);
        $this->assertSame('offline', $params['access_type']);
        $this->assertSame('S256', $params['code_challenge_method']);

        $sessionId = session()->getId();
        $cookie = 'bio-notifier-tenant-'.hash('sha256', $this->organisation->id);
        $callback = '/oauth/google-drive/callback?'.http_build_query(['state' => $params['state'], 'code' => 'test-code']);
        tenancy()->end();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'temporary-access', 'refresh_token' => 'private-refresh',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response(['user' => [
                'permissionId' => 'drive-account', 'displayName' => 'Company backup account',
            ]]),
        ]);

        $this->withCookie($cookie, 'different-session')->get($callback)->assertForbidden();
        Http::assertNothingSent();
        $this->withCookie($cookie, $sessionId)->get($callback)
            ->assertRedirect('/backup-fixture/admin/organisation-settings/backups');
        $this->get($callback)->assertForbidden();

        $settings = OrganisationBackupSetting::findOrFail($this->organisation->id);
        $this->assertSame('private-refresh', $settings->refresh_token);
        $this->assertNotSame('private-refresh', $settings->getRawOriginal('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $settings->toArray());
    }

    public function test_org_admin_can_schedule_a_backup_or_queue_one_on_demand(): void
    {
        $this->connected();
        $this->signIn();

        Livewire::test(Backups::class)->fillForm(['frequency' => 'weekly', 'backup_time' => '03:15'])
            ->call('save')->assertHasNoFormErrors()->call('queueBackup')->assertHasNoFormErrors();
        $settings = OrganisationBackupSetting::findOrFail($this->organisation->id);
        $this->assertSame('weekly', $settings->frequency);
        $this->assertSame('Mon 03:15', $settings->next_run_at->setTimezone($settings->timezone)->format('D H:i'));
        $this->assertSame(1, OrganisationBackupRun::where('organisation_id', $this->organisation->id)->count());

        tenancy()->end();
        Queue::fake();
        $this->artisan('organisations:queue-backups')->assertSuccessful();
        Queue::assertPushedOn('backup-uploads', RunOrganisationBackups::class);
    }

    public function test_periods_keep_the_chosen_local_time(): void
    {
        $after = CarbonImmutable::parse('2026-11-23T13:00:00Z');
        $this->assertSame('2026-11-24 07:00', BackupSchedule::next('daily', '02:00', 'America/New_York', $after)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-30 07:00', BackupSchedule::next('weekly', '02:00', 'America/New_York', $after)->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 07:00', BackupSchedule::next('monthly', '02:00', 'America/New_York', $after)->format('Y-m-d H:i'));
    }

    public function test_uploaded_sql_is_verified_and_retry_can_recover_an_existing_upload(): void
    {
        if (! (new ExecutableFinder)->find('sqlite3')) {
            $this->markTestSkipped('Requires the sqlite3 CLI.');
        }

        $settings = $this->connected();
        $run = BackupSchedule::queue($this->organisation->id, $settings->connection_id);
        $invalidChecksum = false;
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'temporary-access']),
            'https://www.googleapis.com/drive/v3/files/generateIds*' => Http::response(['ids' => ['drive-file-1']]),
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => Http::response([], 200, [
                'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=fixture-upload',
            ]),
            'https://www.googleapis.com/upload/drive/v3/files?upload_id=fixture-upload' => function (Request $request) use ($run) {
                $this->assertSame('PUT', $request->method());
                $this->assertSame($run->fresh()->checksum, md5($request->body()));

                return Http::response(['id' => 'drive-file-1']);
            },
            'https://www.googleapis.com/drive/v3/files/drive-file-1*' => function () use ($run, &$invalidChecksum) {
                return Http::response([
                    'id' => 'drive-file-1', 'size' => (string) $run->fresh()->bytes,
                    'md5Checksum' => $invalidChecksum ? str_repeat('0', 32) : $run->fresh()->checksum, 'trashed' => false,
                ]);
            },
        ]);

        $worker = new RunOrganisationBackups($this->organisation->id);
        $worker->handle(app(GoogleDrive::class), app(TenantSqlBackup::class));
        $this->assertSame('verified', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->verified_at);

        $run->refresh()->update(['status' => 'queued', 'available_at' => now()]);
        $worker->handle(app(GoogleDrive::class), app(TenantSqlBackup::class));
        $this->assertSame('verified', $run->fresh()->status);
        $invalidChecksum = true;
        $run->refresh()->update(['status' => 'queued', 'available_at' => now(), 'verified_at' => null]);
        $worker->handle(app(GoogleDrive::class), app(TenantSqlBackup::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('verification_failed', $run->fresh()->error);
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'PUT'));
    }

    private function connected(): OrganisationBackupSetting
    {
        return OrganisationBackupSetting::create([
            'organisation_id' => $this->organisation->id,
            'connection_id' => (string) Str::uuid(),
            'drive_account' => 'drive-account',
            'drive_name' => 'Backup account',
            'refresh_token' => 'private-refresh',
            'backup_time' => '02:00',
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function signIn(): void
    {
        tenancy()->initialize($this->organisation);
        URL::defaults(['tenant' => $this->organisation->shortname]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::create([
            'name' => 'Backup admin', 'pin' => 'backup-admin', 'privilege' => 14,
            'email' => 'backup-admin@example.test', 'password' => 'test-only-password',
        ]), 'web');
    }
}
