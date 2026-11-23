<?php

namespace Tests\Feature;

use App\Jobs\RunAttendanceSerially;
use App\Models\AttendanceDay;
use App\Models\AttendanceRunRequest;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceRunQueue;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NightlyAttendanceRunnerTest extends TestCase
{
    private array $organisations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Queue::fake();
        config(['attendance.nightly_time' => '02:00']);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->organisations as $organisation) {
            $organisation->delete();
            File::deleteDirectory(base_path('storage/tenant'.$organisation->id));
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function organisation(string $name, int $employees = 1, string $timezone = 'Asia/Kolkata'): Organisation
    {
        $organisation = Organisation::create(['name' => $name, 'shortname' => $name, 'timezone' => $timezone]);
        $this->organisations[] = $organisation;
        TenantContext::run($organisation, function () use ($employees) {
            for ($i = 0; $i < $employees; $i++) {
                User::create(['name' => 'Employee '.$i, 'pin' => (string) $i]);
            }
        });

        return $organisation;
    }

    private function submit(Organisation $organisation, string $from = '2026-10-01', string $to = '2026-10-03', bool $nightly = false): AttendanceRunRequest
    {
        TenantContext::run($organisation, fn () => app(AttendanceRunQueue::class)->submit($organisation, Carbon::parse($from), Carbon::parse($to), nightly: $nightly));

        return AttendanceRunRequest::where('organisation_id', $organisation->id)->latest('id')->firstOrFail();
    }

    private function calculator(array &$calls): AttendanceCalculationService
    {
        $calculator = Mockery::mock(AttendanceCalculationService::class);
        $calculator->shouldReceive('calculate')->andReturnUsing(function ($employee, $date) use (&$calls) {
            $calls[] = tenant('shortname').':'.$employee->id.':'.$date->toDateString();

            return new AttendanceDay;
        });

        return $calculator;
    }

    private function processRequest(AttendanceRunRequest $request, AttendanceCalculationService $calculator): void
    {
        (new RunAttendanceSerially($request->id))->handle(app(AttendanceRunQueue::class), $calculator);
    }

    public function test_one_tenant_finishes_before_another_even_with_out_of_order_and_duplicate_jobs(): void
    {
        $a = $this->organisation('runner-a', 4);
        $b = $this->organisation('runner-b');
        $first = $this->submit($a);
        $second = $this->submit($b);
        $calls = [];
        $calculator = $this->calculator($calls);
        $this->processRequest($second, $calculator);
        $this->assertCount(0, $calls);
        $this->processRequest($first, $calculator);
        $this->assertSame(10, $first->fresh()->cursor);
        $this->assertSame('running', $first->fresh()->state);
        $this->processRequest($second, $calculator);
        $this->assertCount(10, $calls);
        $this->processRequest($first, $calculator);
        $this->assertSame('completed', $first->fresh()->state);
        $this->processRequest($second, $calculator);
        $this->assertSame('completed', $second->fresh()->state);
        $this->processRequest($first, $calculator);
        $this->assertCount(15, $calls);
        $this->assertCount(12, array_filter($calls, fn ($call) => str_starts_with($call, 'runner-a:')));
        $this->assertSame(12, TenantContext::run($a, fn () => DB::table('attendance_calculation_results')->count()));
        $this->assertSame(3, TenantContext::run($b, fn () => DB::table('attendance_calculation_results')->count()));
        $this->assertNull(tenant());
    }

    public function test_running_tenant_keeps_ownership_when_an_older_queued_request_becomes_visible(): void
    {
        $earlier = $this->submit($this->organisation('runner-earlier'));
        $running = $this->submit($this->organisation('runner-running'));
        // Model commit visibility: the higher ID was already claimed when the lower ID appeared.
        $running->update(['state' => 'running']);
        $calls = [];
        $calculator = $this->calculator($calls);
        $this->processRequest($earlier, $calculator);
        $this->assertCount(0, $calls);
        $this->processRequest($running, $calculator);
        $this->assertSame('completed', $running->fresh()->state);
        $this->processRequest($earlier, $calculator);
        $this->assertSame('completed', $earlier->fresh()->state);
        $this->assertStringStartsWith('runner-running:', $calls[0]);
    }

    public function test_execution_lock_excludes_an_independent_process(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attendance-lock-');
        $central = config('tenancy.database.central_connection');
        config(['database.connections.runner_lock_process' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => ''], 'tenancy.database.central_connection' => 'runner_lock_process']);
        $lock = null;
        try {
            DB::connection('runner_lock_process')->statement('CREATE TABLE cache_locks (key VARCHAR(255) PRIMARY KEY, owner VARCHAR(255), expiration INTEGER)');
            $lock = AttendanceRunQueue::lock();
            $this->assertTrue($lock->get());
            $script = <<<'CODE'
require $argv[1];
$capsule = new Illuminate\Database\Capsule\Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => $argv[2], 'prefix' => '']);
$lock = new Illuminate\Cache\DatabaseLock($capsule->getConnection(), 'cache_locks', 'attendance-serial:execute', 120);
$acquired = $lock->get();
echo $acquired ? 'acquired' : 'busy';
if ($acquired) $lock->release();
CODE;
            $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $path]);
            $process->mustRun();
            $this->assertSame('busy', $process->getOutput());
            $lock->release();
            $process->mustRun();
            $this->assertSame('acquired', $process->getOutput());
        } finally {
            $lock?->release();
            config(['tenancy.database.central_connection' => $central]);
            DB::purge('runner_lock_process');
            unlink($path);
        }
    }

    public function test_each_organisation_uses_its_local_night_and_duplicate_ticks_do_not_repeat_the_run(): void
    {
        $east = $this->organisation('runner-east');
        $west = $this->organisation('runner-west', timezone: 'America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-11-02 20:00:00', 'UTC'));
        Artisan::call('attendance:recalculate-nightly', ['--due' => true]);
        $this->assertSame(0, AttendanceRunRequest::where('organisation_id', $east->id)->count());
        $request = AttendanceRunRequest::where('organisation_id', $west->id)->firstOrFail();
        $this->assertSame('2026-10-30', $request->from_date->toDateString());
        $this->assertSame('2026-11-01', $request->to_date->toDateString());
        Carbon::setTestNow(Carbon::parse('2026-11-02 21:00:00', 'UTC'));
        Artisan::call('attendance:recalculate-nightly', ['--due' => true]);
        Artisan::call('attendance:recalculate-nightly', ['--due' => true]);
        $this->assertSame(2, AttendanceRunRequest::count());
        $this->assertSame('2026-11-02', AttendanceRunRequest::where('organisation_id', $east->id)->firstOrFail()->to_date->toDateString());
    }

    public function test_busy_manual_run_defers_nightly_then_runs_it_once_and_manual_duplicates_are_rejected(): void
    {
        $organisation = $this->organisation('runner-manual');
        $manual = $this->submit($organisation);
        try {
            $this->submit($organisation);
            $this->fail('A simultaneous manual submission was accepted.');
        } catch (ValidationException) {
            $this->assertSame(1, AttendanceRunRequest::count());
        }
        Artisan::call('attendance:recalculate-nightly', ['--date' => '2026-10-03']);
        $this->assertSame(1, AttendanceRunRequest::count());
        $calls = [];
        $this->processRequest($manual, $this->calculator($calls));
        Artisan::call('attendance:recalculate-nightly', ['--date' => '2026-10-03']);
        Artisan::call('attendance:recalculate-nightly', ['--date' => '2026-10-03']);
        $this->assertSame(2, AttendanceRunRequest::count());
    }

    public function test_global_lock_releases_contending_jobs_without_processing_or_spending_failure_retries(): void
    {
        $request = $this->submit($this->organisation('runner-lock'));
        $lock = AttendanceRunQueue::lock();
        $this->assertTrue($lock->get());
        $job = new RunAttendanceSerially($request->id);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(15);
        $job->setJob($queueJob);
        $calculator = Mockery::mock(AttendanceCalculationService::class);
        $calculator->shouldNotReceive('calculate');
        $job->handle(app(AttendanceRunQueue::class), $calculator);
        $this->assertSame(0, $request->fresh()->cursor);
        $this->assertSame(0, $request->fresh()->failures);
        $this->assertSame(0, $job->tries);
        $lock->release();
    }

    public function test_lost_queue_signal_and_missing_tenant_run_are_recovered_from_the_durable_request(): void
    {
        $organisation = $this->organisation('runner-recovery');
        $ids = TenantContext::run($organisation, fn () => User::employees()->pluck('id')->all());
        $request = AttendanceRunRequest::create(['organisation_id' => $organisation->id, 'run_key' => Str::uuid(), 'from_date' => '2026-10-01', 'to_date' => '2026-10-01', 'user_ids' => $ids, 'filters' => ['source' => 'nightly']]);
        Artisan::call('attendance:recover-runs');
        Queue::assertPushed(RunAttendanceSerially::class, fn ($job) => $job->requestId === $request->id);
        $calls = [];
        $this->processRequest($request, $this->calculator($calls));
        $this->assertSame('completed', $request->fresh()->state);
        $this->assertNotNull($request->fresh()->tenant_run_id);
        // Simulate a crash after the tenant transaction committed but before its central cursor advanced.
        $request->update(['state' => 'running', 'cursor' => 0]);
        $this->processRequest($request, $this->calculator($calls));
        $this->assertCount(1, $calls);
    }

    public function test_repeated_calculation_failure_stops_the_run_and_allows_the_next_tenant(): void
    {
        $first = $this->submit($this->organisation('runner-failed'));
        $second = $this->submit($this->organisation('runner-next'));
        $calculator = Mockery::mock(AttendanceCalculationService::class);
        $calculator->shouldReceive('calculate')->times(3)->andThrow(new \RuntimeException('Simulated failure'));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->processRequest($first, $calculator);
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame('failed', $first->fresh()->state);
        $calls = [];
        $this->processRequest($second, $this->calculator($calls));
        $this->assertSame('completed', $second->fresh()->state);
    }
}
