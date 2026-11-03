<?php

namespace App\Filament\Employee\Widgets;

use App\Models\PortalPunch;
use App\Models\WorkLocation;
use App\Services\EmployeePortal\GeofencedPunchService;
use App\Services\EmployeePortal\PortalAccess;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;

class LocationPunch extends Widget implements HasSchemas
{
    use InteractsWithSchemas;

    public ?array $data = [];

    protected string $view = 'filament.employee.widgets.location-punch';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function boot(): void
    {
        PortalAccess::employee();
    }

    public function mount(): void
    {
        $this->form->fill(['location_id' => WorkLocation::where('is_active', true)->orderBy('name')->value('id')]);
    }

    public function hasLocations(): bool
    {
        return WorkLocation::where('is_active', true)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->label('Work location')->required()->searchable()
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
            Notification::make()->title('Punch not recorded')->body(collect($exception->errors())->flatten()->first())->danger()->send();

            return false;
        }
        Notification::make()->title($log->kind === 'in' ? 'Check-in requested' : 'Checkout requested')
            ->body($log->punched_at->format('d M, h:i A').' · '.$log->evidence['location_name'])->success()->send();

        return true;
    }
}
