<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\AttendanceCalculationRuns\AttendanceCalculationRunResource;
use App\Filament\Tenant\Resources\AttendanceCalculationRuns\Pages\ListAttendanceCalculationRuns;
use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Jobs\RunAttendanceSerially;
use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceRunRequest;
use App\Models\Organisation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceRecalculationFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        Artisan::call('migrate', ['--path' => ['database/migrations/0001_01_01_000001_create_cache_table.php', 'database/migrations/2026_11_02_150000_create_attendance_run_requests.php'], '--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'sec']);
        tenancy()->tenant = (new Organisation)->forceFill(['id' => 'form-test']);
        $this->actingAs(User::create(['name' => 'Admin', 'pin' => '970', 'privilege' => 14]));
    }

    protected function tearDown(): void
    {
        tenancy()->tenant = null;
        parent::tearDown();
    }

    public function test_attendance_header_has_recalculate_and_a_status_link_without_sidebar_navigation(): void
    {
        $this->assertFalse(AttendanceCalculationRunResource::shouldRegisterNavigation());
        Livewire::test(ListAttendanceDays::class)
            ->assertActionExists('recalculate')
            ->assertActionExists('recalculationStatus', fn ($action) => $action->getUrl() === AttendanceCalculationRunResource::getUrl('index'));
        Livewire::test(ListAttendanceCalculationRuns::class)->assertActionDoesNotExist('recalculate')->assertSee('Recalculation status');
    }

    public function test_long_range_shows_the_error_inside_the_modal_and_does_not_create_a_run(): void
    {
        Livewire::test(ListAttendanceDays::class)
            ->callAction('recalculate', data: ['from_date' => '2026-08-01', 'to_date' => '2026-10-01', 'user_ids' => []])
            ->assertHasErrors(['mountedActions.0.data.to_date'])
            ->assertNotified('Calculation not queued');
        $this->assertSame(0, AttendanceCalculationRun::count());
    }

    public function test_active_run_error_is_visible_in_the_modal(): void
    {
        AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'total' => 31, 'state' => 'queued']);
        Livewire::test(ListAttendanceDays::class)
            ->callAction('recalculate', data: ['from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'user_ids' => []])
            ->assertHasErrors(['mountedActions.0.data.from_date'])
            ->assertNotified('Calculation not queued');
        $this->assertSame(1, AttendanceCalculationRun::count());
    }

    public function test_valid_month_queues_a_calculation_and_closes_the_modal(): void
    {
        Queue::fake();
        $organisation = (new Organisation)->forceFill(['id' => 'submit-test']);
        tenancy()->tenant = $organisation;
        try {
            Livewire::test(ListAttendanceDays::class)
                ->callAction('recalculate', data: ['from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'user_ids' => []])
                ->assertHasNoErrors()
                ->assertSet('mountedActions', []);
            $run = AttendanceCalculationRun::firstOrFail();
            $this->assertEquals(31, $run->total);
            $this->assertSame('queued', $run->state);
            Queue::assertPushed(RunAttendanceSerially::class, fn ($job) => $job->requestId === AttendanceRunRequest::firstOrFail()->id && $job->queue === null);
        } finally {
            tenancy()->tenant = null;
        }
    }
}
