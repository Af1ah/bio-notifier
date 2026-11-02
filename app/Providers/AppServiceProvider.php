<?php

namespace App\Providers;

use App\Services\Devices\Gateway\GatewayMonitor;
use App\Services\Devices\Gateway\Queue\GatewayDatabaseConnector;
use App\Services\Support\TenantTimezone;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
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
        foreach ([\App\Models\Device::class, \App\Models\DeviceCommand::class, \App\Models\AttendanceLog::class] as $model) {
            \Illuminate\Support\Facades\Gate::policy($model, \App\Policies\DeviceManagementPolicy::class);
        }
        foreach ([
            \App\Models\User::class, \App\Models\EmployeePayrollProfile::class,
            \App\Models\AttendanceLog::class, \App\Models\AttendanceDay::class, \App\Models\AttendanceOccurrence::class,
            \App\Models\Schedule::class, \App\Models\ShiftRuleRevision::class, \App\Models\ShiftAssignmentSet::class,
            \App\Models\Branch::class, \App\Models\Department::class, \App\Models\TaskGroup::class,
            \App\Models\Holiday::class, \App\Models\LeaveType::class, \App\Models\LeavePolicy::class,
            \App\Models\LeavePolicyRevision::class, \App\Models\LeaveCreditLot::class, \App\Models\LeaveLedgerEntry::class,
            \App\Models\EmployeeLeave::class, \App\Models\CompensatoryAward::class,
        ] as $model) {
            $model::observe(\App\Observers\LeaveBalanceSummaryObserver::class);
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
            Route::post('/livewire/upload-file/{fileTenant}', [FileUploadController::class, 'handle'])
                ->name('livewire.upload-file');
            Route::get('/livewire/preview-file/{filename}/{fileTenant}', [FilePreviewController::class, 'handle'])
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
        Auth::extend('tenant-session', function ($app, $name, array $config) {
            // IDs in different tenant databases can overlap. Scope both the login
            // session key and the remember cookie to the owning organisation.
            $scope = tenancy()->initialized ? (string) tenant('id') : 'central';

            return $app['auth']->createSessionDriver($name.'_'.hash('sha256', $scope), $config);
        });
    }
}
