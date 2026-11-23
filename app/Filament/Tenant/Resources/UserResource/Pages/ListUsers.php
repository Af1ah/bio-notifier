<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use App\Filament\Tenant\Resources\UserResource;
use App\Jobs\SyncEbioUsersJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Imports\UserCsvImportService;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public string $importPreview = '';

    #[Locked]
    public array $importReview = [];

    #[Locked]
    public string $importFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('syncEbioUsers')
                ->authorize(fn (): bool => (bool) auth('web')->user()?->canManageDevices() && (DeviceIntegration::usesEbio()))
                ->label('Sync employees')->icon('heroicon-o-arrow-path')->color('warning')
                ->requiresConfirmation()->modalHeading('Sync employees')
                ->modalDescription('Import registered employees from the device connection. This may take a few moments.')
                ->action(function () {
                    $command = null;
                    try {
                        $command = DeviceCommand::create(['device_id' => null, 'command_type' => 'sync_users', 'command_content' => 'eBioServer SOAP Command: sync users', 'status' => 'pending']);
                        SyncEbioUsersJob::dispatch(tenancy()->tenant, $command->id);
                        Notification::make()->title('Sync Queued')->body('Employee synchronization is tracked on the Device Commands page.')->success()->send();
                    } catch (\Exception $e) {
                        $command?->markAsFailed($e->getMessage());
                        Notification::make()->title('Sync Failed')->body('Employee synchronization failed. Contact your administrator.')->danger()->send();
                    }
                }),
            Actions\Action::make('readGatewayUsers')
                ->authorize(fn (): bool => (bool) auth('web')->user()?->canManageDevices() && (tenant()?->device_integration === DeviceIntegration::GATEWAY))
                ->label('Read device users')->icon('heroicon-o-users')->color('warning')
                ->modalHeading('Read device users')
                ->modalDescription('The device sends its users on the next connection. New users are added as employees; existing employee names are updated. Payroll and login settings are not changed.')
                ->form([
                    Select::make('device_id')->label('Device')
                        ->options(fn () => Device::query()->get()->filter(fn (Device $device) => app(DeviceCommandService::class)->available($device, 'query_users'))->mapWithKeys(fn (Device $device) => [$device->id => trim(($device->name ?: 'Unnamed device').' — '.$device->serial_number)])->all())
                        ->searchable()->required(),
                ])
                ->action(function (array $data): void {
                    $device = Device::findOrFail($data['device_id']);
                    abort_unless(app(DeviceCommandService::class)->available($device, 'query_users'), 403);
                    app(DeviceCommandService::class)->request($device, 'query_users');
                    Notification::make()->title('Request sent')->body('Device users will be added or updated when the device responds. Track the request in Device Commands.')->success()->send();
                }),
            Actions\Action::make('importUsers')
                ->authorize(fn (): bool => (bool) auth('web')->user()?->hasManagementAccess())
                ->label('Import employees')->icon('heroicon-o-arrow-up-tray')->color('info')
                ->modalHeading('Import employees')->modalSubmitActionLabel('Import employees')
                ->modalDescription('Upload a CSV, review the employees, then import.')
                ->beforeFormFilled(function (): void {
                    $this->importPreview = '';
                    $this->importReview = [];
                    $this->importFingerprint = '';
                })
                ->steps([
                    Step::make('Upload and validate')->schema([
                        SchemaActions::make([
                            Actions\Action::make('downloadBlankEmployeeCsv')->label('Download blank CSV')->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')->action(fn () => $this->downloadImportTemplate(false)),
                            Actions\Action::make('downloadEmployeeExamples')->label('Download example')->icon('heroicon-o-arrow-down-tray')
                                ->action(fn () => $this->downloadImportTemplate(true)),
                        ]),
                        Select::make('mode')->label('Existing employees')
                            ->options(['create' => 'Add new employees', 'upsert' => 'Add or update by PIN'])
                            ->default('create')->required(),
                        FileUpload::make('file')->label('CSV file')
                            ->helperText('Up to 1,000 employees · 5 MB')
                            ->acceptedFileTypes(['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/plain'])
                            ->disk('local')->directory(fn () => app(UserCsvImportService::class)->directory())
                            ->maxSize(5120)->required()->storeFiles(true),
                    ])->afterValidation(function (Step $component): void {
                        $this->importReview = [];
                        $this->importPreview = '';
                        $this->importFingerprint = '';
                        $data = $component->getChildSchema()->getState();
                        $file = $data['file'] ?? null;
                        if (! is_string($file)) {
                            throw ValidationException::withMessages(['file' => 'Wait for the upload to finish.']);
                        }
                        try {
                            $preview = app(UserCsvImportService::class)->preview($file, (string) $data['mode']);
                        } catch (ValidationException $e) {
                            $this->rejectImport($component->getChildSchema(), $e);
                        }
                        $this->importPreview = "{$preview['created']} new · {$preview['updated']} updates";
                        $this->importReview = $preview['review'];
                        $this->importFingerprint = $preview['fingerprint'];
                    }),
                    Step::make('Review and import')->schema([
                        Text::make(fn (): string => $this->importPreview),
                        View::make('filament.tenant.components.employee-import-review')
                            ->viewData(fn (): array => ['review' => $this->importReview]),
                        Checkbox::make('confirmed')->label('These details are correct.')->accepted()->required(),
                    ]),
                ])
                ->action(function (array $data, Schema $schema): void {
                    Validator::make($data, ['confirmed' => ['accepted'], 'file' => ['required', 'string'], 'mode' => ['required', 'in:create,upsert']])->validate();
                    try {
                        $result = app(UserCsvImportService::class)->import($data['file'], $data['mode'], $this->importFingerprint);
                    } catch (ValidationException $e) {
                        $this->rejectImport($schema, $e);
                    }
                    Storage::disk('local')->delete($data['file']);
                    $this->importFingerprint = '';
                    $this->importReview = [];
                    Notification::make()->title('Import complete')
                        ->body("Created {$result['created']} employees and updated {$result['updated']}. Changed payroll profiles require salary slips to be recalculated.")
                        ->success()->send();
                }),
        ];
    }

    public function downloadImportTemplate(bool $examples = false): StreamedResponse
    {
        abort_unless(tenancy()->initialized && (bool) auth('web')->user()?->hasManagementAccess(), 403);
        $service = app(UserCsvImportService::class);
        $csv = $examples ? $service->exampleTemplate() : $service->template(true, blank: true);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $examples ? 'employees-10-examples.csv' : 'employees-blank.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rejectImport(Schema $schema, ValidationException $exception): never
    {
        $message = implode(' ', $exception->validator->errors()->all());
        Notification::make()->title('Import not saved')->body($message)->danger()->send();
        throw ValidationException::withMessages([$schema->getStatePath().'.file' => $message]);
    }
}
