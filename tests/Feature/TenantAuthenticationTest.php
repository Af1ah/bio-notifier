<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyByShortname;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
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
        Route::get('/api/auth-probe/{tenant}', fn () => response()->json(['id' => Auth::guard('web')->id()]))
            ->middleware(['web', InitializeTenancyByShortname::class, AuthenticateSession::class]);
        Route::post('/livewire/auth-probe', fn () => response()->json([
            'id' => Auth::guard('web')->id(), 'token' => session()->token(),
        ]))->middleware('web');
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
        $this->assertEqualsCanonicalizing([$adminEmployee->id, $employee->id], \App\Filament\Tenant\Resources\UserResource::getEloquentQuery()->pluck('id')->all());
        // Authentication uses the unfiltered model, so the default admin can still log in.
        $this->assertSame($default->id, Auth::createUserProvider('users')->retrieveById($default->id)->id);
    }

    public function test_quick_login_replaces_stale_password_state_and_scopes_session_cookie(): void
    {
        $user = User::create(['name' => 'Administrator', 'pin' => '100', 'privilege' => 14, 'password' => 'password']);
        $url = URL::signedRoute('tenant.impersonate', ['tenant' => 'alpha']);

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
        $user = User::create(['name' => 'Administrator', 'pin' => '102', 'privilege' => 14, 'password' => 'password']);
        $this->get(URL::signedRoute('tenant.impersonate', ['tenant' => 'alpha']))->assertRedirect();
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
