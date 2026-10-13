<?php
namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyByShortname;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Bridge\BridgeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class BridgeWebRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'bridge_route_test','database.connections.bridge_route_test'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        Schema::create('devices',function(Blueprint $t){$t->id();$t->string('vendor');$t->string('connection_mode');});
        DB::table('devices')->insert(['id'=>5,'vendor'=>'matrix','connection_mode'=>'remote']);
        $this->app->instance(\Stancl\Tenancy\Contracts\Tenant::class,(new Organisation())->forceFill(['id'=>'tenant-a','shortname'=>'secumax']));
        $this->app->instance(InitializeTenancyByShortname::class,new class extends InitializeTenancyByShortname {
            public function handle(Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response {
                $request->route()->forgetParameter('tenant');return $next($request);
            }
        });
    }
    public function test_bridge_route_is_not_captured_by_tenant_redirect(): void
    {
        $route=Route::getRoutes()->match(Request::create('/secumax/bridge-device/5/open'));
        $this->assertSame('bridge.device.web',$route->getName());
        $this->get('/secumax')->assertRedirect('/secumax/admin');
        $this->get('/secumax/bridge-device/5/open/admin/admin')->assertNotFound();
    }
    public function test_authenticated_launch_uses_device_id_after_tenant_parameter_is_removed(): void
    {
        $this->actingAs((new User())->forceFill(['id'=>42,'privilege'=>14,'role'=>'admin']));
        $gateway=\Mockery::mock(BridgeGateway::class);
        $gateway->shouldReceive('webUrl')->once()->withArgs(fn($device)=>$device->id===5)->andReturn('https://matrix-test.aflahdev.in/launch/test-token');
        $this->app->instance(BridgeGateway::class,$gateway);
        $this->get('/secumax/bridge-device/5/open')->assertRedirect('https://matrix-test.aflahdev.in/launch/test-token');
    }
    public function test_guest_goes_to_tenant_login_without_a_redirect_loop(): void
    {
        $this->get('/secumax/bridge-device/5/open')->assertRedirect(route('filament.tenant.auth.login',['tenant'=>'secumax']));
    }
    public function test_non_admin_cannot_launch_device_session(): void
    {
        $this->actingAs((new User())->forceFill(['id'=>42,'privilege'=>1,'role'=>'user']));
        $this->get('/secumax/bridge-device/5/open')->assertForbidden();
    }
}
