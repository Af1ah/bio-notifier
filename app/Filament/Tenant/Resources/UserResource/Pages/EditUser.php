<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use App\Filament\Tenant\Actions\AssignShiftAction;
use App\Filament\Tenant\Actions\EndShiftAssignmentAction;
use App\Filament\Tenant\Resources\UserResource;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\WhatsAppService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bank_account_number'] = $this->record->bank_account_number;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            AssignShiftAction::make('user'),
            EndShiftAssignmentAction::make('user'),
            Actions\ViewAction::make(),
            Actions\Action::make('reset_password')
                ->label(fn () => $this->record->portal_enabled ? 'Reset portal password' : 'Enable employee portal')
                ->visible(fn () => ! $this->record->hasManagementAccess())
                ->modalDescription('The employee will sign in with their email and device password, then choose a new portal password.')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(PortalAccess::class)->reset(auth()->user(), $this->record);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Portal access not enabled')
                            ->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Portal access ready')->success()->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if (! empty($this->record->whatsapp_number) && $this->record->wasChanged()) {
            $message = "Hello {$this->record->name}, your profile has been updated in the attendance system.";
            try {
                app(WhatsAppService::class)->sendMessage($this->record->whatsapp_number, $message);
            } catch (\Exception $e) {
                Log::error('WhatsApp notification failed: '.$e->getMessage());
            }
        }
    }
}
