<?php

namespace Tests\Feature;

use App\Jobs\RecalculateAttendanceChunk;
use App\Models\AttendanceCalculationRun;
use App\Models\Organisation;
use App\Services\Attendance\AttendanceCalculationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class AttendanceQueueTest extends TestCase
{
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
