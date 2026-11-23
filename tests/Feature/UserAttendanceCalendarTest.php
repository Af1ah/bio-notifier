<?php

namespace Tests\Feature;

use App\Livewire\Tenant\UserAttendanceCalendar;
use App\Models\AttendanceDay;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ShiftRuleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class UserAttendanceCalendarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', [
            '--path' => database_path('migrations/tenant'),
            '--realpath' => true,
            '--force' => true,
        ]);
        $this->actingAs(User::create(['pin' => 'calendar-admin', 'name' => 'Admin', 'privilege' => 14]), 'web');
    }

    public function test_previous_month_is_a_public_livewire_action(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $user = User::create(['name' => 'Calendar Employee', 'pin' => '510']);

        try {
            Livewire::test(UserAttendanceCalendar::class, ['record' => $user])
                ->assertSet('year', 2026)
                ->assertSet('month', 10)
                ->call('previousMonth')
                ->assertSet('year', 2026)
                ->assertSet('month', 9);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_navigation_buttons_belong_to_the_calendar_component_root(): void
    {
        $user = User::create(['name' => 'Calendar Employee', 'pin' => '511']);
        $calendar = Livewire::test(UserAttendanceCalendar::class, ['record' => $user]);
        $dom = new \DOMDocument;
        $dom->loadHTML('<div wire:id="parent-user-page">'.$calendar->html().'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        foreach (['previousMonth', 'nextMonth'] as $method) {
            $buttons = $xpath->query('//*[@*[name()="wire:click"]="'.$method.'"]');
            $this->assertSame(1, $buttons->length);
            $root = $buttons->item(0);
            while ($root instanceof \DOMElement && ! $root->hasAttribute('wire:id')) {
                $root = $root->parentNode;
            }
            $this->assertInstanceOf(\DOMElement::class, $root);
            $this->assertSame('div', $root->tagName);
            $this->assertSame($calendar->id(), $root->getAttribute('wire:id'));
        }
    }

    public function test_calendar_shows_holiday_title_and_scheduled_shift_times(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        try {
            $user = User::create(['name' => 'Calendar Employee', 'pin' => '512']);
            Holiday::create(['name' => 'Founders Day', 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-03', 'recurrence' => 'none', 'active' => true]);
            $shift = Schedule::create(['name' => 'Day shift', 'type' => 'regular', 'valid_from' => '2026-10-01', 'status' => true,
                'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [1, 2, 3, 4, 5], 'half_day_minutes' => 240, 'full_day_minutes' => 480]]);
            $rule = app(ShiftRuleService::class)->sync($shift);
            $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-10-02', 'status' => 'present']);
            $day->occurrences()->create(['schedule_id' => $shift->id, 'shift_rule_revision_id' => $rule->id, 'position' => 1,
                'window_starts_at' => '2026-10-02 08:00:00', 'window_ends_at' => '2026-10-02 18:00:00',
                'first_in_at' => '2026-10-02 09:15:00', 'last_out_at' => '2026-10-02 16:30:00']);
            $html = html_entity_decode(Livewire::test(UserAttendanceCalendar::class, ['record' => $user])->html(), ENT_QUOTES | ENT_HTML5);
            $this->assertStringContainsString('Founders Day', $html);
            $this->assertStringContainsString('Oct 03, 2026: Holiday', $html);
            $this->assertStringContainsString('"start":"09:00"', $html);
            $this->assertStringContainsString('"end":"17:00"', $html);
        } finally {
            Carbon::setTestNow();
        }
    }
}
