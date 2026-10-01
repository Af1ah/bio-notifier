<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EbioSoapService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EbioUserSyncTest extends TestCase
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

    public function test_ebio_sync_does_not_overwrite_application_access_or_login_settings(): void
    {
        $user = User::create([
            'name' => 'Local Administrator',
            'pin' => '1001',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'privilege' => 14,
            'is_enabled' => false,
        ]);

        app(EbioSoapService::class)->updateUserFromEmployeeDetails('1001', [
            'EmployeeName' => 'Device Name',
            'EmployeeRole' => 'Normal Users',
            'EmployeeVerificationType' => '16',
        ]);

        $user->refresh();

        $this->assertSame(14, (int) $user->privilege);
        $this->assertFalse($user->is_enabled);
        $this->assertTrue(password_verify('secret-password', $user->password));
        $this->assertSame(0, (int) $user->device_privilege);
        $this->assertSame('16', $user->device_verification_type);
        $this->assertNull($user->face_enrolled);
    }

    public function test_new_ebio_user_has_no_application_admin_access(): void
    {
        $user = app(EbioSoapService::class)->updateUserFromEmployeeDetails('1002', [
            'EmployeeName' => 'Device Administrator',
            'EmployeeRole' => 'Admin Users',
            'EmployeeVerificationType' => 'Only Fingerprint',
        ]);

        $this->assertSame(0, (int) $user->privilege);
        $this->assertSame(14, (int) $user->device_privilege);
        $this->assertNull($user->password);
        $this->assertNull($user->face_enrolled);
    }

    public function test_device_access_migration_can_resume_after_a_partial_run(): void
    {
        $user = User::create([
            'name' => 'Face User',
            'pin' => '1003',
            'face_templates' => [['source' => 'device']],
        ]);

        $migration = require database_path('migrations/tenant/2026_09_30_110000_separate_device_access_from_application_access.php');
        $migration->up();

        $this->assertTrue($user->refresh()->face_enrolled);
    }

    public function test_ebio_verification_type_does_not_claim_a_face_template_exists(): void
    {
        $user = User::create([
            'name' => 'No Face Template',
            'pin' => '1004',
            'face_enrolled' => false,
        ]);

        app(EbioSoapService::class)->updateUserFromEmployeeDetails('1004', [
            'EmployeeName' => 'No Face Template',
            'EmployeeVerificationType' => 'Finger or Face or Card or Password',
        ]);

        $this->assertFalse($user->refresh()->face_enrolled);
    }
}
