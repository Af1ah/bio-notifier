<?php

namespace App\Filament\Employee\Widgets;

use App\Models\EmployeePortalSetting;
use App\Models\PortalPunch;
use App\Models\WorkLocation;
use App\Services\EmployeePortal\GeofencedPunchService;
use App\Services\EmployeePortal\PortalAccess;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class LocationPunch extends Widget implements HasSchemas
{
    use InteractsWithSchemas;

    public ?array $data = [];

    #[Locked]
    public bool $geofenceEnabled = true;

    protected string $view = 'filament.employee.widgets.location-punch';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function boot(): void
    {
        PortalAccess::employee();
        $this->geofenceEnabled = EmployeePortalSetting::geofencingEnabled();
    }

    public function mount(): void
    {
        $this->form->fill(['location_id' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->label(__('employee-punch.location'))->placeholder(__('employee-punch.selectLocation'))->searchable()->preload()
                ->options(fn () => WorkLocation::where('is_active', true)->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getSearchResultsUsing(fn (string $search) => WorkLocation::where('is_active', true)->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => WorkLocation::where('is_active', true)->find($value)?->name),
        ])->statePath('data');
    }

    public function lastPunch(): ?PortalPunch
    {
        return PortalPunch::where('user_id', PortalAccess::employee()->id)->latest('id')->first();
    }

    public function punch(array $data): bool
    {
        try {
            $log = app(GeofencedPunchService::class)->record($data);
        } catch (ValidationException $exception) {
            $this->dispatch('portal-punch-rejected', message: collect($exception->errors())->flatten()->first());

            return false;
        }
        $this->dispatch('portal-punch-submitted');
        $this->dispatch('portal-punch-receipt', kind: $log->kind, time: $log->punched_at->format('d M, h:i A'), state: $log->state);

        return true;
    }
}
