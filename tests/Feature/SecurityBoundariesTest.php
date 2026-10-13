<?php

namespace Tests\Feature;

use App\Jobs\RefreshAttendanceDay;
use App\Models\Admin;
use App\Models\Organisation;
use App\Services\Diagnostics\RequestEventStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityBoundariesTest extends TestCase
{
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }
        parent::tearDown();
    }

    private function organisation(): Organisation
    {
        $tenant = Organisation::create(['name' => 'Security test']);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    public function test_organisation_id_cannot_authenticate_webhook(): void
    {
        Queue::fake();
        $tenant = $this->organisation();
        $this->postJson('/api/ebio/webhook/'.$tenant->id, [])->assertForbidden();
        Queue::assertNothingPushed();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tenant->ebio_webhook_token);
    }

    public function test_master_auth_and_database_queue_remain_central_inside_tenant(): void
    {
        $tenant = $this->organisation();
        $master = (new Admin)->forceFill(['name' => 'Central master', 'email' => 'central@test.local', 'password' => bcrypt('test-only'), 'role' => 'master']);
        $master->save();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
        tenancy()->initialize($tenant);
        $this->assertSame('central@test.local', Admin::findOrFail($master->id)->email);
        RefreshAttendanceDay::dispatch($tenant->id, 999, '2026-10-01');
        $this->assertSame(1, DB::connection('sqlite')->table('jobs')->count());
        $this->assertSame('sqlite', (new Admin)->getConnectionName());
        tenancy()->end();
    }

    public function test_invalid_payload_and_failed_decryption_are_not_acknowledged(): void
    {
        Queue::fake();
        $tenant = $this->organisation();
        $url = '/api/ebio/webhook/'.$tenant->ebio_webhook_token;
        $this->postJson($url, ['data' => 'broken'])->assertStatus(422);
        $this->postJson($url, ['data' => ['unexpected']])->assertStatus(422);
        $this->postJson($url, [['EmployeeCode' => '1', 'LogDate' => 'not-a-date']])->assertStatus(422);
        $this->postJson($url, [['EmployeeCode' => '1', 'LogDate' => '2026-10-01', 'Direction' => ['out']]])->assertStatus(422);
        $this->postJson($url, ['password' => 'private'])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_rotation_revokes_old_webhook_credential(): void
    {
        Queue::fake();
        $tenant = $this->organisation();
        $old = $tenant->ebio_webhook_token;
        $this->artisan('ebio:rotate-webhook-token', ['tenant' => $tenant->id])->assertSuccessful();
        $this->postJson('/api/ebio/webhook/'.$old, [])->assertForbidden();
        $new = $tenant->fresh()->ebio_webhook_token;
        $this->assertNotSame($old, $new);
        $this->postJson('/api/ebio/webhook/'.$new, ['EmployeeCode' => '1', 'LogDate' => '2026-10-01 09:30:00'])->assertOk()->assertSee('Success');
    }

    public function test_request_diagnostics_do_not_store_secrets(): void
    {
        $this->postJson('/api/ebio/webhook/invalid-secret?signature=private', ['password' => 'private', 'face' => 'private'], ['Authorization' => 'Bearer private'])->assertForbidden();
        $event = DB::table('request_diagnostic_events')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('private', json_encode($event));
        $this->assertSame(403, $event->status);
    }

    public function test_fifo_retains_only_latest_ten_thousand_diagnostic_events(): void
    {
        for ($offset = 0; $offset < 10000; $offset += 500) {
            DB::table('request_diagnostic_events')->insert(array_fill(0, 500, ['method' => 'GET', 'route' => 'old', 'status' => 200, 'created_at' => now()]));
        }
        app(RequestEventStore::class)->record(['method' => 'POST', 'route' => str_repeat('x', 100000), 'status' => 403, 'password' => 'private']);
        $this->assertSame(10000, DB::table('request_diagnostic_events')->count());
        $this->assertFalse(DB::table('request_diagnostic_events')->where('id', 1)->exists());
        $this->assertSame(200, strlen(DB::table('request_diagnostic_events')->latest('id')->value('route')));
        $this->assertSame('POST', DB::table('request_diagnostic_events')->latest('id')->value('method'));
    }
}
