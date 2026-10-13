<?php

namespace Tests\Feature;

use App\Jobs\RecalculateAttendanceChunk;
use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceDay;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class AttendanceQueueTest extends TestCase
{
    public function test_partial_retry_counts_each_employee_day_only_once(): void
    {
        $a = User::create(['name' => 'A', 'pin' => 'qa']);
        $b = User::create(['name' => 'B', 'pin' => 'qb']);
        $run = AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'total' => 2]);
        $org = (new Organisation)->forceFill(['id' => 'queue-test']);
        $tenancy = Mockery::mock(Tenancy::class);
        $tenancy->shouldReceive('initialize')->twice()->with($org);
        $tenancy->shouldReceive('end')->twice();
        $this->app->instance(Tenancy::class, $tenancy);
        $calculator = Mockery::mock(AttendanceCalculationService::class);
        $calls = [];
        $calculator->shouldReceive('calculate')->times(3)->andReturnUsing(function ($user) use (&$calls, $b) {
            $calls[$user->id] = ($calls[$user->id] ?? 0) + 1;
            if ($user->id === $b->id && $calls[$user->id] === 1) {
                throw new \RuntimeException('Simulated interruption');
            }

            return new AttendanceDay;
        });
        $job = new RecalculateAttendanceChunk($org, $run->id, [$a->id, $b->id]);
        try {
            $job->handle($calculator);
            $this->fail('Expected interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated interruption', $exception->getMessage());
        }
        $this->assertSame(1, $run->fresh()->completed);
        $job->handle($calculator);
        $this->assertSame(2, $run->fresh()->completed);
        $this->assertSame(0, $run->fresh()->failed);
        $this->assertSame('completed', $run->fresh()->state);
        $this->assertSame(1, $calls[$a->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
    }

    public function test_attendance_job_uses_the_connections_default_queue(): void
    {
        $organisation = (new Organisation)->forceFill(['id' => 'queue-test']);
        $job = new RecalculateAttendanceChunk($organisation, 1, [1]);
        $this->assertNull($job->queue);
        $this->assertNull($job->connection);
    }

    public function test_legacy_reversed_date_run_is_rejected_without_calculating_attendance(): void
    {
        $run = AttendanceCalculationRun::create([
            'run_key' => Str::uuid(), 'from_date' => '2026-10-01', 'to_date' => '2026-08-26', 'total' => -280,
        ]);
        $organisation = (new Organisation)->forceFill(['id' => 'queue-test']);
        $tenancy = Mockery::mock(Tenancy::class);
        $tenancy->shouldReceive('initialize')->once()->with($organisation);
        $tenancy->shouldReceive('end')->once();
        $this->app->instance(Tenancy::class, $tenancy);
        $calculator = Mockery::mock(AttendanceCalculationService::class);
        $calculator->shouldNotReceive('calculate');

        (new RecalculateAttendanceChunk($organisation, $run->id, [1]))->handle($calculator);

        $this->assertSame('failed', $run->fresh()->state);
        $this->assertEquals(0, $run->fresh()->total);
        $this->assertStringContainsString('end date', $run->fresh()->last_error);
    }
}
