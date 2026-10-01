<?php

namespace Tests\Feature;

use App\Livewire\Tenant\UserAttendanceCalendar;
use App\Models\User;
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
        $dom = new \DOMDocument();
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
}
