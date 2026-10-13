<?php

namespace Tests\Feature;

use App\Filament\Master\Resources\Organisations\Pages\ManageOrganisationAdmins;
use App\Filament\Tenant\Resources\UserResource;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Models\Admin;
use App\Models\Organisation;
use App\Models\User;
use App\Services\QuickLoginService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class TenantAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', [
            '--path' => database_path('migrations/tenant'),
            '--realpath' => true,
            '--force' => true,
        ]);
        // Keep the tests in one isolated SQLite database; production database
        // switching is independent of session and remember-cookie isolation.
        config(['tenancy.bootstrappers' => [], 'tenancy.database.central_connection' => 'sqlite']);
        Schema::create('organisations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shortname');
            $table->string('name');
            $table->json('data')->nullable();
            $table->timestamps();
        });
        Organisation::insert([
            ['id' => 'organisation-a', 'shortname' => 'alpha', 'name' => 'Alpha'],
            ['id' => 'organisation-b', 'shortname' => 'beta', 'name' => 'Beta'],
        ]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_02_090002_create_quick_login_grants.php', '--force' => true]);
        $master = (new Admin)->forceFill(['name' => 'Master', 'email' => 'master@test.local', 'pin' => 'master', 'role' => 'master', 'is_system_account' => true]);
        $master->save();
        Auth::guard('admin')->login($master);
        Route::get('/api/auth-probe/{tenant}', fn () => response()->json(['id' => Auth::guard('web')->id()]))
            ->middleware(['web', InitializeTenancyByShortname::class, AuthenticateSession::class]);
        Route::post('/livewire/auth-probe', fn () => response()->json([
            'id' => Auth::guard('web')->id(), 'token' => session()->token(),
        ]))->middleware('web');
        Route::post('/livewire/update', fn () => response()->json(['tenant' => tenant('id'), 'id' => Auth::guard('web')->id()]))->middleware('web');
    }

    public function test_master_admin_page_initializes_tenancy_from_restored_record(): void
    {
        $organisation = Organisation::findOrFail('organisation-a');
        $this->mock(Tenancy::class, function ($mock) use ($organisation) {
            $mock->shouldReceive('initialize')->once()->with($organisation);
        });
        $page = new ManageOrganisationAdmins;
        $page->record = $organisation;
        // Livewire updates have no routed record or raw snapshot here.
        $page->boot();
    }

    public function test_master_admin_page_boot_handles_record_before_mount(): void
    {
        $this->mock(Tenancy::class, function ($mock) {
            $mock->shouldNotReceive('initialize');
        });
        $page = new ManageOrganisationAdmins;
        $page->boot();
        $this->assertFalse(isset($page->record));
    }

    public function test_tenant_login_response_redirects_to_resolved_dashboard(): void
    {
        tenancy()->initialize(Organisation::findOrFail('organisation-a'));
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->assertSame(url('/alpha/admin'), Filament::getUrl());
        $response = app(LoginResponse::class)->toResponse(request());
        $this->assertSame(url('/alpha/admin'), $response->getTargetUrl());
        tenancy()->end();
    }

    public function test_tenants_have_different_login_and_remember_cookie_names(): void
    {
        tenancy()->initialize(Organisation::find('organisation-a'));
        $first = Auth::guard('web');
        $login = $first->getName();
        $remember = $first->getRecallerName();
        Auth::forgetGuards();
        tenancy()->initialize(Organisation::find('organisation-b'));
        $second = Auth::guard('web');

        $this->assertNotSame($login, $second->getName());
        $this->assertNotSame($remember, $second->getRecallerName());
    }

    public function test_only_the_default_login_account_is_excluded_from_employee_queries(): void
    {
        $default = User::create(['name' => 'Admin', 'email' => User::DEFAULT_LOGIN_EMAIL, 'pin' => '999', 'privilege' => 14, 'password' => 'password']);
        $adminEmployee = User::create(['name' => 'Employee Admin', 'email' => 'employee@example.test', 'pin' => '201', 'privilege' => 14, 'password' => 'password']);
        $employee = User::create(['name' => 'Employee', 'pin' => '202']);

        $this->assertSame(2, User::employees()->count());
        $this->assertEqualsCanonicalizing([$adminEmployee->id, $employee->id], UserResource::getEloquentQuery()->pluck('id')->all());
        // Authentication uses the unfiltered model, so the default admin can still log in.
        $this->assertSame($default->id, Auth::createUserProvider('users')->retrieveById($default->id)->id);
        $default->update(['email' => 'changed-login@example.test']);
        $this->assertTrue($default->fresh()->is_system_account);
        $this->assertSame(2, User::employees()->count());
    }

    public function test_expired_quick_login_link_cannot_authenticate(): void
    {
        User::create(['name' => 'Administrator', 'pin' => '100', 'privilege' => 14, 'password' => 'password']);
        $url = URL::temporarySignedRoute('tenant.impersonate', now()->subMinute(), ['tenant' => 'alpha']);
        $this->get($url)->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_quick_login_is_single_use_and_audits_master_actor(): void
    {
        User::create(['name' => 'Administrator', 'pin' => '104', 'privilege' => 14]);
        $url = app(QuickLoginService::class)->issue(Organisation::findOrFail('organisation-a'));
        $this->get($url)->assertRedirect('/alpha/admin');
        $this->get($url)->assertForbidden();
        $grant = DB::table('quick_login_grants')->first();
        $this->assertSame('organisation-a', $grant->organisation_id);
        $this->assertNotNull($grant->used_at);
        $this->assertNotNull($grant->actor_id);
    }

    public function test_quick_login_skips_device_admins_without_portal_credentials(): void
    {
        $deviceAdmin = User::create(['name' => 'Device administrator', 'pin' => '301', 'privilege' => 14, 'password' => 'password']);
        $portalAdmin = User::create(['name' => 'Portal administrator', 'pin' => '302', 'privilege' => 14, 'email' => 'portal@example.test', 'password' => 'password']);
        $this->assertFalse($deviceAdmin->has_login);
        $url = app(QuickLoginService::class)->issue(Organisation::findOrFail('organisation-a'));
        $this->get($url)->assertRedirect('/alpha/admin');
        $this->assertAuthenticatedAs($portalAdmin, 'web');
        $this->assertTrue(Auth::guard('web')->user()->canAccessPanel(Filament::getPanel('tenant')));
    }

    private function signedComponent(string $path): array
    {
        $snapshot = ['data' => [], 'memo' => ['path' => $path, 'name' => 'test', 'id' => 'test']];
        $snapshot['checksum'] = Checksum::generate($snapshot);

        return ['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []];
    }

    public function test_livewire_update_uses_signed_context_not_referer_and_rejects_mixed_tenants(): void
    {
        $a = $this->signedComponent('alpha/admin');
        $b = $this->signedComponent('beta/admin');
        $response = $this->postJson('/livewire/update', ['components' => [$a]]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJson(['tenant' => 'organisation-a']);
        $this->withHeader('Referer', 'http://localhost/beta/admin')->postJson('/livewire/update', ['components' => [$a]])->assertForbidden();
        $this->withHeader('Referer', 'http://localhost/alpha/admin')->postJson('/livewire/update', ['components' => [$a, $b]])->assertForbidden();
        $snapshot = json_decode($a['snapshot'], true);
        $snapshot['memo']['path'] = 'beta/admin';
        $a['snapshot'] = json_encode($snapshot);
        $this->postJson('/livewire/update', ['components' => [$a]])->assertForbidden();
        $this->postJson('/livewire/update', ['components' => []])->assertForbidden();
    }

    public function test_quick_login_replaces_stale_password_state_and_scopes_session_cookie(): void
    {
        $user = User::create(['name' => 'Administrator', 'email' => 'administrator@example.test', 'pin' => '100', 'privilege' => 14, 'password' => 'password']);
        $url = app(QuickLoginService::class)->issue(Organisation::findOrFail('organisation-a'));

        $response = $this->withSession(['password_hash_web' => 'outdated-password-hash'])->get($url);
        $response->assertRedirect('/alpha/admin')->assertSessionMissing('password_hash_web');
        $response->assertCookie('bio-notifier-tenant-'.hash('sha256', 'organisation-a'));
        $this->assertAuthenticatedAs($user, 'web');

        Auth::forgetGuards();
        $this->get('/api/auth-probe/alpha')->assertOk()->assertJson(['id' => $user->id]);
    }

    public function test_remember_cookie_restores_login_after_session_expiry_and_logout_removes_it(): void
    {
        $user = User::create(['name' => 'Remembered Administrator', 'email' => 'remembered@example.test', 'pin' => '101', 'privilege' => 14, 'password' => 'password']);
        tenancy()->initialize(Organisation::find('organisation-a'));
        $guard = Auth::guard('web');
        $guard->login($user, remember: true);
        $cookieName = $guard->getRecallerName();
        $cookieValue = collect(app('cookie')->getQueuedCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName)->getValue();
        session()->flush();
        Auth::forgetGuards();

        $this->withCookie($cookieName, $cookieValue)->get('/api/auth-probe/alpha')
            ->assertOk()->assertJson(['id' => $user->id]);
        $this->assertTrue(Auth::guard('web')->viaRemember());

        Auth::forgetGuards();
        $this->post('/alpha/admin/logout')->assertRedirect()->assertCookieExpired($cookieName);
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->get('/api/auth-probe/alpha')->assertOk()->assertJson(['id' => null]);
    }

    public function test_livewire_uses_the_same_tenant_cookie_and_csrf_token_as_quick_login(): void
    {
        $user = User::create(['name' => 'Administrator', 'email' => 'administrator@example.test', 'pin' => '102', 'privilege' => 14, 'password' => 'password']);
        $this->get(app(QuickLoginService::class)->issue(Organisation::findOrFail('organisation-a')))->assertRedirect();
        $token = session()->token();
        Auth::forgetGuards();

        $this->withHeader('Referer', 'http://localhost/alpha/admin')
            ->post('http://localhost/livewire/auth-probe')
            ->assertOk()->assertJson(['id' => $user->id, 'token' => $token])
            ->assertCookie('bio-notifier-tenant-'.hash('sha256', 'organisation-a'));
    }

    public function test_a_remembered_tenant_login_cannot_authenticate_in_another_organisation(): void
    {
        $user = User::create(['name' => 'Administrator', 'pin' => '103', 'privilege' => 14, 'password' => 'password']);
        tenancy()->initialize(Organisation::find('organisation-a'));
        $guard = Auth::guard('web');
        $guard->login($user, remember: true);
        $cookieName = $guard->getRecallerName();
        $cookieValue = collect(app('cookie')->getQueuedCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName)->getValue();
        Auth::forgetGuards();

        $this->withCookie($cookieName, $cookieValue)->get('/api/auth-probe/beta')
            ->assertOk()->assertJson(['id' => null]);
    }
}
