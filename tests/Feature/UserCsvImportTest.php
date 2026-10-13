<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\UserResource\Pages\ListUsers;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Organisation;
use App\Models\SalarySlip;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Imports\UserCsvImportService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserCsvImportTest extends TestCase
{
    private UserCsvImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        Storage::fake('local');
        // Isolated SQLite tenant schema; no provisioning or production databases.
        tenancy()->initialized = true;
        tenancy()->tenant = (new Organisation)->forceFill(['id' => 'import-test']);
        Auth::guard('web')->login(User::create(['pin' => 'owner', 'name' => 'Owner', 'privilege' => 14]));
        $this->service = app(UserCsvImportService::class);
    }

    protected function tearDown(): void
    {
        tenancy()->initialized = false;
        tenancy()->tenant = null;
        parent::tearDown();
    }

    private function upload(string $csv): string
    {
        $path = $this->service->directory().'/test.csv';
        Storage::disk('local')->put($path, $csv);

        return $path;
    }

    public function test_templates_round_trip_and_payroll_is_exact(): void
    {
        $path = $this->upload($this->service->template(true));
        $this->assertSame(1, $this->service->preview($path, 'create')['created']);
        $this->assertSame(1, User::count()); // Preview writes nothing.
        $this->service->import($path, 'create');
        $user = User::where('pin', '1001')->firstOrFail();
        $this->assertSame(800000, $user->payrollProfile->basic_salary_minor);
        $this->assertFalse($user->payrollProfile->payroll_enabled);
        $this->assertSame(0, $user->privilege);
        $path = $this->upload("pin,name,pay_basis,basic_salary,overtime_hourly_rate,effective_from,payroll_enabled\n0012,Hourly,hourly,85.05,100.1,2026-08-01,yes\n");
        $this->service->import($path, 'create');
        $profile = User::where('pin', '0012')->firstOrFail()->payrollProfile;
        $this->assertSame(8505, $profile->basic_salary_minor);
        $this->assertSame(10010, $profile->overtime_hourly_rate_minor);
        $this->assertTrue($profile->payroll_enabled);
    }

    public function test_limit_accepts_1000_and_rejects_1001_before_writes(): void
    {
        $csv = "pin,name\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csv .= "employee{$i},Employee {$i}\n";
        }
        $path = $this->upload($csv);
        $this->assertSame(1000, $this->service->preview($path, 'create')['created']);
        $this->assertSame(1000, $this->service->import($path, 'create')['created']);
        $path = $this->upload($csv."employee1001,Employee 1001\n");
        try {
            $this->service->import($path, 'upsert');
            $this->fail('Expected row limit rejection');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1,000', $e->getMessage());
        }
        $this->assertSame(1001, User::count());
        $this->assertFalse(User::where('pin', 'employee1001')->exists());
    }

    public function test_bom_semicolon_and_blank_update_preserve_access_and_payroll(): void
    {
        $user = User::create(['pin' => '123', 'name' => 'Old', 'email' => 'old@example.com', 'privilege' => 14, 'password' => 'keep-password']);
        $user->payrollProfile()->create(['pay_basis' => 'monthly', 'basic_salary_minor' => 800000, 'payroll_enabled' => true, 'effective_from' => '2026-08-01']);
        $password = $user->password;
        $path = $this->upload("\xEF\xBB\xBFpin;name;email;basic_salary;device_privilege\n123;New;;;14\n");
        $this->assertSame('semicolon', $this->service->preview($path, 'upsert')['delimiter']);
        $this->service->import($path, 'upsert');
        $user->refresh();
        $this->assertSame('New', $user->name);
        $this->assertSame('old@example.com', $user->email);
        $this->assertSame($password, $user->password);
        $this->assertSame(14, $user->privilege);
        $this->assertSame(14, $user->device_privilege);
        $this->assertSame(800000, $user->payrollProfile->basic_salary_minor);
    }

    public function test_invalid_files_are_rejected_without_partial_import(): void
    {
        $invalid = [
            "pin,name,privilege\n1,Employee,14\n",
            "pin,name,password\n1,Employee,secret\n",
            "pin,name,tenant_id\n1,Employee,other\n",
            "pin,name,name\n1,A,B\n",
            "pin,name\n1,Good\n2\n",
            "pin,name\n1,Good\n1,Duplicate\n",
            "pin,name\n1,Good\n2,=HYPERLINK(attack)\n",
            "pin,name,is_enabled\n1,Employee,maybe\n",
            "pin,name,basic_salary\n1,Employee,1e6\n",
            "pin,name,basic_salary\n1,Employee,5.999\n",
            "pin,name,pay_basis\n1,Employee,weekly\n",
            "pin,name,effective_from\n1,Employee,2026-02-30\n",
            "pin,name,branch_id\n1,Employee,99999\n",
            "pin,name,task_group_ids\n1,Employee,99999\n",
            "pin,name,email\n1,A,a@example.com\n2,B,a@example.com\n",
            "pin,name\n' OR 1=1,Employee\n",
            "pin,name\n1,Invalid\x00\n",
            "pin,name\n1,".str_repeat('x', UserCsvImportService::MAX_BYTES)."\n",
        ];
        foreach ($invalid as $csv) {
            try {
                $this->service->import($this->upload($csv), 'create');
                $this->fail('Expected invalid CSV rejection');
            } catch (ValidationException) {
                $this->assertSame(1, User::count());
            }
        }
    }

    public function test_default_account_and_upload_paths_are_protected(): void
    {
        User::create(['pin' => 'system', 'name' => 'Admin', 'email' => User::DEFAULT_LOGIN_EMAIL]);
        foreach ([$this->upload("pin,name\nsystem,Overwrite\n"), 'imports/users/other/test.csv', $this->service->directory().'/../test.csv'] as $path) {
            try {
                $this->service->import($path, 'upsert');
                $this->fail('Expected protected resource rejection');
            } catch (ValidationException) {
                $this->assertSame('Admin', User::where('pin', 'system')->first()->name);
            }
        }
    }

    public function test_user_and_central_context_cannot_import(): void
    {
        $path = $this->upload($this->service->template(false));
        Auth::guard('web')->user()->update(['privilege' => 0]);
        try {
            $this->service->import($path, 'create');
            $this->fail('Expected authorization failure');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Auth::guard('web')->user()->update(['privilege' => 14]);
        tenancy()->initialized = false;
        $this->expectException(HttpException::class);
        $this->service->import($path, 'create');
    }

    public function test_database_failure_rolls_back_entire_batch(): void
    {
        DB::unprepared("CREATE TRIGGER reject_import BEFORE INSERT ON users WHEN NEW.pin = 'zzz' BEGIN SELECT RAISE(ABORT, 'test conflict'); END");
        try {
            $this->service->import($this->upload("pin,name\naaa,First\nzzz,Second\n"), 'create');
            $this->fail('Expected database conflict');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No rows', $e->getMessage());
        }
        $this->assertSame(1, User::count());
    }

    public function test_filament_upload_preview_and_confirm_import(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'sec']);
        $page = Livewire::test(ListUsers::class)
            ->mountAction('importUsers')
            ->fillForm(['file' => UploadedFile::fake()->createWithContent('users.csv', "pin,name\n001,Preview Employee\n"), 'mode' => 'create'])
            ->goToNextWizardStep()
            ->assertHasNoErrors();
        $this->assertStringContainsString('1 new employees', $page->get('importPreview'));
        $this->assertSame(1, User::count());
        $page->fillForm(['confirmed' => true])->callMountedAction()->assertHasNoErrors()->assertNotified('Import complete');
        $this->assertTrue(User::where('pin', '001')->exists());
    }

    public function test_invalid_csv_error_is_visible_in_the_wizard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'sec']);
        Livewire::test(ListUsers::class)->mountAction('importUsers')
            ->fillForm(['file' => UploadedFile::fake()->createWithContent('bad.csv', "pin,name,privilege\n1,Unsafe,14\n"), 'mode' => 'create'])
            ->goToNextWizardStep()
            ->assertHasErrors(['mountedActions.0.data.file'])->assertNotified('Import not saved');
        $this->assertSame(1, User::count());
    }

    public function test_expired_csv_cleanup_preserves_fresh_and_unrelated_files(): void
    {
        $expired = $this->upload("pin,name\n1,Old\n");
        touch(Storage::disk('local')->path($expired), now()->subDays(2)->getTimestamp());
        $fresh = $this->service->directory().'/fresh.csv';
        Storage::disk('local')->put($fresh, "pin,name\n2,Fresh\n");
        Storage::disk('local')->put('imports/unrelated.csv', 'keep');
        $this->artisan('users:prune-imports')->assertSuccessful();
        Storage::disk('local')->assertMissing($expired);
        Storage::disk('local')->assertExists($fresh);
        Storage::disk('local')->assertExists('imports/unrelated.csv');
    }

    public function test_sql_like_text_is_data_not_executable_sql(): void
    {
        $this->service->import($this->upload("pin,name\n1,'; DROP TABLE users;--\n"), 'create');
        $this->assertSame("'; DROP TABLE users;--", User::where('pin', '1')->firstOrFail()->name);
        $this->assertSame(2, User::count());
    }

    public function test_confirmed_file_and_mode_cannot_change_after_preview(): void
    {
        $path = $this->upload("pin,name\n1,Original\n");
        $fingerprint = $this->service->preview($path, 'create')['fingerprint'];
        foreach (['changed content', 'changed mode'] as $change) {
            $mode = $change === 'changed mode' ? 'upsert' : 'create';
            Storage::disk('local')->put($path, $change === 'changed content' ? "pin,name\n2,Changed\n" : "pin,name\n1,Original\n");
            try {
                $this->service->import($path, $mode, $fingerprint);
                $this->fail('Expected preview mismatch rejection');
            } catch (ValidationException) {
                $this->assertSame(1, User::count());
            }
        }
    }

    public function test_assignments_are_validated_and_flag_existing_attendance_stale(): void
    {
        $branch = Branch::create(['name' => 'Branch']);
        $department = Department::create(['name' => 'Department']);
        $department->branches()->attach($branch);
        $group = TaskGroup::create(['name' => 'Group']);
        $user = User::create(['pin' => '1', 'name' => 'Employee']);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-01', 'status' => 'present', 'calculation_revision' => 1]);
        $this->service->import($this->upload("pin,name,branch_id,department_id,task_group_ids\n1,Employee,{$branch->id},{$department->id},{$group->id}\n"), 'upsert');
        $this->assertSame($branch->id, $user->fresh()->branch_id);
        $this->assertSame($department->id, $user->fresh()->department_id);
        $this->assertTrue($user->taskGroups()->whereKey($group->id)->exists());
        $this->assertNotNull($day->fresh()->stale_at);
    }

    public function test_existing_payroll_changes_mark_slips_stale_without_rewriting_pay(): void
    {
        $user = User::create(['pin' => '1', 'name' => 'Employee']);
        $user->payrollProfile()->create(['pay_basis' => 'monthly', 'basic_salary_minor' => 800000, 'payroll_enabled' => true, 'effective_from' => '2026-08-01']);
        $slip = SalarySlip::create(['user_id' => $user->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'reviewed', 'net_pay_minor' => 700000, 'calculation_snapshot' => [], 'calculated_at' => now()]);
        $this->service->import($this->upload("pin,name,basic_salary\n1,Employee,9000\n"), 'upsert');
        $this->assertSame(900000, $user->payrollProfile->fresh()->basic_salary_minor);
        $this->assertTrue($slip->fresh()->calculation_snapshot['needs_recalculation']);
        $this->assertSame(700000, $slip->fresh()->net_pay_minor);
    }
}
