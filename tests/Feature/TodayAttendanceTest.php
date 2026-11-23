<?php

namespace Tests\Feature;

use App\Services\Attendance\TodayAttendance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TodayAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'attendance.timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 20:00:00', 'UTC'));

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('pin');
            $table->string('email')->nullable();
            $table->boolean('is_system_account')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('branch_id')->default(1);
            $table->unsignedInteger('department_id')->default(1);
        });
        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('pin');
            $table->dateTime('punched_at');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_uses_local_midnight_and_counts_only_enabled_employees_with_nonfuture_punches(): void
    {
        DB::table('users')->insert([
            ['pin' => '1', 'is_enabled' => true, 'is_system_account' => false],
            ['pin' => '2', 'is_enabled' => true, 'is_system_account' => false],
            ['pin' => '3', 'is_enabled' => false, 'is_system_account' => false],
            ['pin' => '4', 'is_enabled' => true, 'is_system_account' => true],
            ['pin' => '5', 'is_enabled' => true, 'is_system_account' => false],
        ]);
        DB::table('attendance_logs')->insert([
            ['pin' => '1', 'punched_at' => '2026-10-29 00:00:00'],
            ['pin' => '1', 'punched_at' => '2026-10-29 01:30:00'],
            ['pin' => '2', 'punched_at' => '2026-10-28 23:59:59'],
            ['pin' => '3', 'punched_at' => '2026-10-29 00:30:00'],
            ['pin' => '4', 'punched_at' => '2026-10-29 00:30:00'],
            ['pin' => '5', 'punched_at' => '2026-10-29 01:30:01'],
            ['pin' => 'unknown', 'punched_at' => '2026-10-29 00:30:00'],
        ]);

        $this->assertSame(3, TodayAttendance::employees()->count());
        $this->assertSame(['1'], TodayAttendance::filterEmployees(TodayAttendance::employees(), true)->pluck('pin')->all());
        $this->assertSame(['2', '5'], TodayAttendance::filterEmployees(TodayAttendance::employees(), false)->orderBy('pin')->pluck('pin')->all());
        $this->assertSame(2, TodayAttendance::logs()->count());

        Carbon::setTestNow(Carbon::parse('2026-10-29 18:30:00', 'UTC'));
        $this->assertSame(0, TodayAttendance::filterEmployees(TodayAttendance::employees(), true)->count());
        $this->assertSame(0, TodayAttendance::logs()->count());
    }

    public function test_employee_shortcut_scope_combines_with_branch_and_department_filters(): void
    {
        DB::table('users')->insert([
            ['pin' => '1', 'branch_id' => 1, 'department_id' => 1],
            ['pin' => '2', 'branch_id' => 2, 'department_id' => 1],
            ['pin' => '3', 'branch_id' => 1, 'department_id' => 2],
        ]);
        foreach (['1', '2', '3'] as $pin) {
            DB::table('attendance_logs')->insert(['pin' => $pin, 'punched_at' => '2026-10-29 00:30:00']);
        }

        $query = TodayAttendance::employees()->where('branch_id', 1)->where('department_id', 2);
        $this->assertSame(['3'], TodayAttendance::filterEmployees($query, true)->pluck('pin')->all());
    }
}
