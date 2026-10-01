<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Models\AttendanceCalculationRun;
use App\Jobs\RecalculateAttendanceChunk;
use App\Models\Organisation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceRecalculationFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'sec']);
        $this->actingAs(User::create(['name' => 'Admin', 'pin' => '970', 'privilege' => 14]));
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
            Queue::assertPushed(RecalculateAttendanceChunk::class, fn ($job) => $job->runId === $run->id && $job->queue === null);
        } finally {
            tenancy()->tenant = null;
        }
    }
}
