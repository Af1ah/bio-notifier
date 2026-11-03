<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\UserResource\Pages\CreateUser;
use App\Filament\Tenant\Resources\UserResource\Pages\EditUser;
use App\Filament\Tenant\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 12:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->actingAs(User::create(['name' => 'HR', 'pin' => 'hr', 'privilege' => User::HR_PRIVILEGE, 'is_system_account' => true]));
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_employee_create_edit_and_view_preserve_personal_and_encrypted_bank_details(): void
    {
        Livewire::test(CreateUser::class)->fillForm([
            'pin' => 'employee-1', 'name' => 'Employee One',
            'date_of_birth' => '1995-04-12', 'gender' => 'female',
            'bank_account_holder' => 'Employee One', 'bank_name' => 'Example Bank',
            'bank_account_number' => '000012345678', 'bank_branch' => 'Main branch',
            'bank_ifsc_code' => 'ABCD0123456', 'bank_swift_code' => 'ABCDEXAMPLE',
        ])->call('create')->assertHasNoFormErrors();
        $employee = User::where('pin', 'employee-1')->firstOrFail();
        $this->assertSame('1995-04-12', $employee->date_of_birth->toDateString());
        $this->assertSame('female', $employee->gender);
        $this->assertSame('000012345678', $employee->bank_account_number);
        $this->assertNotSame('000012345678', DB::table('users')->where('id', $employee->id)->value('bank_account_number'));
        $this->assertArrayNotHasKey('bank_account_number', $employee->toArray());
        Livewire::test(EditUser::class, ['record' => $employee->id])
            ->assertFormSet(['bank_account_number' => '000012345678', 'date_of_birth' => '1995-04-12', 'gender' => 'female'])
            ->fillForm(['bank_branch' => 'Updated branch'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('000012345678', $employee->fresh()->bank_account_number);
        $this->assertSame('Updated branch', $employee->fresh()->bank_branch);
        Livewire::test(ViewUser::class, ['record' => $employee->id])
            ->assertSee('Bank details')->assertSee('Female')->assertSee('12 Apr 1995')->assertSee('000012345678')->assertSee('Updated branch');
    }

    public function test_future_birth_date_and_unknown_gender_are_rejected(): void
    {
        Livewire::test(CreateUser::class)->fillForm([
            'pin' => 'invalid', 'name' => 'Invalid', 'date_of_birth' => '2026-11-03', 'gender' => 'unknown',
        ])->call('create')->assertHasFormErrors(['date_of_birth', 'gender']);
        $this->assertFalse(User::where('pin', 'invalid')->exists());
    }

    public function test_existing_employees_can_leave_the_new_details_blank_and_clear_a_bank_number(): void
    {
        $employee = User::create(['pin' => 'existing', 'name' => 'Existing', 'bank_account_number' => '00009999']);
        Livewire::test(EditUser::class, ['record' => $employee->id])->fillForm(['bank_account_number' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($employee->fresh()->bank_account_number);
        $this->assertNull($employee->fresh()->date_of_birth);
        $this->assertNull($employee->fresh()->gender);
        $this->assertNull(DB::table('users')->where('id', $employee->id)->value('bank_account_number'));
    }
}
