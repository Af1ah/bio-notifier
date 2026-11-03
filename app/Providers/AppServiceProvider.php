<?php

namespace App\Providers;

use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\AttendanceOccurrence;
use App\Models\Branch;
use App\Models\CompensatoryAward;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\EmployeeLeave;
use App\Models\EmployeePayrollProfile;
use App\Models\Holiday;
use App\Models\LeaveCreditLot;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRevision;
use App\Models\LeaveType;
use App\Models\Schedule;
use App\Models\ShiftAssignmentSet;
use App\Models\ShiftRuleRevision;
use App\Models\TaskGroup;
use App\Models\User;
use App\Observers\LeaveBalanceSummaryObserver;
use App\Observers\QueuedActionNotificationObserver;
use App\Policies\DeviceManagementPolicy;
use App\Services\Devices\Gateway\GatewayMonitor;
use App\Services\Devices\Gateway\Queue\GatewayDatabaseConnector;
use App\Services\EmployeePortal\EmployeeUserProvider;
use App\Services\Support\TenantTimezone;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([DeviceCommand::class, AttendanceCalculationRun::class] as $model) {
            $model::observe(QueuedActionNotificationObserver::class);
        }
        foreach ([Device::class, DeviceCommand::class, AttendanceLog::class] as $model) {
            Gate::policy($model, DeviceManagementPolicy::class);
        }
        foreach ([
            User::class, EmployeePayrollProfile::class,
            AttendanceLog::class, AttendanceDay::class, AttendanceOccurrence::class,
            Schedule::class, ShiftRuleRevision::class, ShiftAssignmentSet::class,
            Branch::class, Department::class, TaskGroup::class,
            Holiday::class, LeaveType::class, LeavePolicy::class,
            LeavePolicyRevision::class, LeaveCreditLot::class, LeaveLedgerEntry::class,
            EmployeeLeave::class, CompensatoryAward::class,
        ] as $model) {
            $model::observe(LeaveBalanceSummaryObserver::class);
        }
        // Date/time presentation resolves the active company at render time.
        TextColumn::configureUsing(fn ($column) => $column->timezone(
            fn () => $column->isDateTime() && tenant() ? TenantTimezone::get() : config('app.timezone')
        ));
        TextEntry::configureUsing(fn ($entry) => $entry->timezone(
            fn () => $entry->isDateTime() && tenant() ? TenantTimezone::get() : config('app.timezone')
        ));
        URL::defaults(['fileTenant' => 'central']);
        Event::listen(TenancyInitialized::class, function ($event) {
            URL::defaults(['fileTenant' => (string) $event->tenancy->tenant->getTenantKey()]);
        });
        Event::listen(TenancyEnded::class, function () {
            URL::defaults(['fileTenant' => 'central']);
        });
        $this->app->booted(function () {
            Route::getRoutes()->refreshNameLookups();
            foreach (['livewire.upload-file', 'livewire.preview-file'] as $name) {
                if ($route = Route::getRoutes()->getByName($name)) {
                    $route->setAction(array_replace($route->getAction(), ['as' => 'legacy.'.$name]));
                }
            }
            Route::post(EndpointResolver::uploadPath().'/{fileTenant}', [FileUploadController::class, 'handle'])
                ->name('livewire.upload-file');
            Route::get(EndpointResolver::previewPath().'/{fileTenant}', [FilePreviewController::class, 'handle'])
                ->name('livewire.preview-file');
            Route::getRoutes()->refreshNameLookups();
        });
        $this->app['queue']->addConnector('gateway-database', fn () => new GatewayDatabaseConnector($this->app['db']));
        Queue::looping(function (Looping $event) {
            if (! in_array($event->connectionName, ['database', 'gateway'], true)) {
                return;
            }
            foreach (explode(',', $event->queue) as $queue) {
                if (in_array($queue, ['device-ingest', 'device-commands', 'attendance-live', 'exports-notifications'], true)) {
                    GatewayMonitor::beat($queue, 'worker:'.$queue.':'.getmypid());
                }
            }
        });
        Auth::provider('employee-eloquent', fn ($app, array $config) => new EmployeeUserProvider($app['hash'], $config['model']));
        Auth::extend('tenant-session', function ($app, $name, array $config) {
            // IDs in different tenant databases can overlap. Scope both the login
            // session key and the remember cookie to the owning organisation.
            $scope = tenancy()->initialized ? (string) tenant('id') : 'central';

            return $app['auth']->createSessionDriver($name.'_'.hash('sha256', $scope), $config);
        });
    }
}
