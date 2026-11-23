<?php

namespace Tests\Feature;

use App\Jobs\EnrollEbioBiometricJob;
use App\Jobs\PushEbioUserJob;
use App\Livewire\Tenant\ResultNotifications;
use App\Models\AttendanceCalculationRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Organisation;
use App\Models\User;
use App\Services\EbioSoapService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CommandNotificationsTest extends TestCase
{
    private User $actor;

    private User $other;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        config(['tenancy.bootstrappers' => []]);
        $this->actor = User::create(['name' => 'Operator', 'pin' => 'operator', 'email' => 'operator@example.test', 'password' => 'password', 'privilege' => 14, 'is_system_account' => true]);
        $this->other = User::create(['name' => 'Other', 'pin' => 'other', 'email' => 'other@example.test', 'password' => 'password', 'privilege' => 14]);
        tenancy()->initialize((new Organisation)->forceFill(['id' => 'notification-test', 'shortname' => 'alpha', 'device_integration' => 'ebio']));
        $this->actingAs($this->actor, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->device = Device::create(['serial_number' => 'TEST', 'name' => 'Test terminal']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    private function command(array $attributes = []): DeviceCommand
    {
        return DeviceCommand::create($attributes + ['device_id' => $this->device->id, 'command_type' => 'reboot', 'command_content' => 'Reboot', 'status' => 'pending']);
    }

    public function test_native_filament_completion_is_private_persistent_linked_and_not_duplicated(): void
    {
        $command = $this->command(['gateway_command_id' => Str::uuid()]);
        $this->assertSame($this->actor->id, $command->requested_by_user_id);
        $this->assertSame(0, $this->actor->notifications()->count());
        $command->markAsAcknowledged();
        $command->update(['response' => 'Repeated receipt']);
        $this->assertSame(1, $this->actor->notifications()->count());
        $this->assertSame(0, $this->other->notifications()->count());
        $notification = $this->actor->notifications()->firstOrFail();
        $this->assertSame('filament', $notification->data['format']);
        $this->assertSame('success', $notification->data['status']);
        $this->assertStringContainsString('Device confirmed', $notification->data['title']);
        $this->assertStringContainsString('/alpha/admin/device-commands/'.$command->id, $notification->data['actions'][0]['url']);
        $this->assertTrue(Filament::getPanel('tenant')->hasDatabaseNotifications());
        $this->assertSame('15s', Filament::getPanel('tenant')->getDatabaseNotificationsPollingInterval());
    }

    public function test_new_results_use_filaments_native_toast_event_once_and_old_history_stays_in_the_bell(): void
    {
        $this->command()->markAsAcknowledged();
        $component = Livewire::test(ResultNotifications::class);
        $component->assertNotDispatched('notificationSent');
        $this->command()->markAsFailed();
        $component->call('$refresh')->assertDispatched('notificationSent', fn ($event, $data) => $data['notification']['status'] === 'danger');
        $component->call('$refresh')->assertNotDispatched('notificationSent');
        $this->assertSame(2, $this->actor->notifications()->unread()->count());
    }

    public function test_failure_and_uncertain_outcomes_use_safe_native_alerts(): void
    {
        foreach (['failed' => 'danger', 'expired' => 'warning', 'outcome_unknown' => 'warning', 'cancelled' => 'warning'] as $state => $severity) {
            $command = $this->command();
            $command->update(['status' => $state, 'response' => 'secret-password=do-not-display']);
            $notification = $this->actor->notifications()->latest('created_at')->get()->first(fn ($n) => str_contains($n->data['title'], $state === 'outcome_unknown' ? 'needs review' : $state));
            $this->assertNotNull($notification);
            $this->assertSame($severity, $notification->data['status']);
            $this->assertStringNotContainsString('secret-password', json_encode($notification->data));
        }
    }

    public function test_read_acknowledgement_waits_for_saved_data_and_access_acknowledgement_is_not_success(): void
    {
        $read = $this->command(['command_type' => 'info', 'gateway_command_id' => Str::uuid()]);
        $read->markAsAcknowledged();
        $this->assertSame(0, $this->actor->notifications()->count());
        $read->update(['data_applied' => true]);
        $this->assertSame(1, $this->actor->notifications()->count());
        $this->assertStringContainsString('Data saved', $this->actor->notifications()->first()->data['title']);
        $access = $this->command(['command_type' => 'set_user_validity', 'gateway_command_id' => Str::uuid()]);
        $access->markAsAcknowledged();
        $this->assertSame(1, $this->actor->notifications()->where('data->status', 'warning')->count());
        $access->update(['data_applied' => true]);
        $this->assertSame(2, $this->actor->notifications()->where('data->status', 'success')->count());
    }

    public function test_notifications_and_deduplication_roll_back_with_the_result(): void
    {
        $command = $this->command();
        DB::beginTransaction();
        $command->markAsAcknowledged();
        $this->assertSame(1, $this->actor->notifications()->count());
        DB::rollBack();
        $this->assertSame(0, $this->actor->notifications()->count());
        $command->fresh()->markAsAcknowledged();
        $this->assertSame(1, $this->actor->notifications()->count());
    }

    public function test_calculation_completion_is_available_to_hr_and_background_runs_do_not_spam_users(): void
    {
        $this->actor->update(['privilege' => User::HR_PRIVILEGE]);
        $run = AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => '2026-10-01', 'to_date' => '2026-10-03', 'total' => 3]);
        $run->update(['state' => 'completed_with_skips', 'completed' => 2, 'skipped' => 1]);
        $notification = $this->actor->notifications()->firstOrFail();
        $this->assertSame('warning', $notification->data['status']);
        $this->assertStringContainsString('with skips', $notification->data['title']);
        $this->command()->markAsAcknowledged();
        $this->assertSame(1, $this->actor->notifications()->count());
        auth('web')->forgetUser();
        $this->command()->markAsFailed();
        $this->assertSame(1, $this->actor->notifications()->count());
    }

    public function test_ebio_worker_completion_and_timeout_keep_the_original_requester(): void
    {
        $employee = User::create(['name' => 'Employee', 'pin' => 'employee']);
        $job = new PushEbioUserJob(tenant(), $employee->id);
        $this->assertNotNull($job->trackedCommandId);
        auth('web')->forgetUser();
        $service = \Mockery::mock(EbioSoapService::class);
        $service->shouldReceive('pushUser')->once()->andReturnTrue();
        $job->handle($service);
        $this->assertSame(1, $this->actor->notifications()->where('data->status', 'success')->count());
        $this->actingAs($this->actor, 'web');
        $enroll = new EnrollEbioBiometricJob(tenant(), $employee->id, $this->device->id, 'face');
        auth('web')->forgetUser();
        $enroll->failed(new \RuntimeException('Timeout'));
        $enroll->failed(new \RuntimeException('Repeated failure callback'));
        $this->assertSame(1, $this->actor->notifications()->where('data->status', 'danger')->count());
    }

    public function test_explicit_retry_can_notify_again_without_duplicate_final_receipts(): void
    {
        $command = $this->command();
        $command->markAsFailed();
        $command->retry();
        $command->markAsFailed();
        $this->assertSame(2, $this->actor->notifications()->count());
    }
}
