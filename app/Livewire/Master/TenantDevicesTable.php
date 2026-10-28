<?php

namespace App\Livewire\Master;

use App\Models\Device;
use App\Models\Organisation;
use App\Services\EbioSoapService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

class TenantDevicesTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public function boot(): void
    {
        abort(404); // Retired duplicate master component, including stale snapshots.
    }

    public Organisation $organisation;

    public function table(Table $table): Table
    {
        // Initialize tenant context for multi-db
        tenancy()->initialize($this->organisation);

        return $table
            ->headerActions([
                Action::make('syncEbioDevices')
                    ->label('Sync from eBioServer')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Sync Devices from eBioServer')
                    ->action(function () {
                        try {
                            $service = new EbioSoapService;
                            $result = $service->syncDevices($this->organisation);

                            Notification::make()
                                ->title('Sync Complete')
                                ->body("Successfully synced {$result['synced']} devices.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Sync Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->query(Device::query())
            ->columns([
                TextColumn::make('serial_number'),
                TextColumn::make('name'),
                TextColumn::make('options.location')
                    ->label('Location')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Device $record): string => $record->isOnline() ? 'online' : 'offline')
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('last_activity_at')
                    ->label('Last Ping')
                    ->dateTime()
                    ->placeholder('-'),
                TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public function render()
    {
        return view('livewire.master.tenant-devices-table');
    }
}
