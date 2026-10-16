<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use App\Filament\Tenant\Resources\UserResource;
use App\Jobs\SyncEbioUsersJob;
use App\Models\DeviceCommand;
use App\Services\Devices\DeviceIntegration;
use App\Services\Imports\UserCsvImportService;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public string $importPreview = '';

    #[Locked]
    public string $importFingerprint = '';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('syncEbioUsers')
                ->authorize(fn (): bool => DeviceIntegration::usesEbio())
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
            Actions\Action::make('importUsers')
                ->label('Import employees')->icon('heroicon-o-arrow-up-tray')->color('info')
                ->modalHeading('Import employees')->modalSubmitActionLabel('Import employees')
                ->modalDescription(function (): HtmlString {
                    $service = app(UserCsvImportService::class);
                    $basic = base64_encode($service->template(false));
                    $payroll = base64_encode($service->template(true));

                    return new HtmlString('<p>Upload up to 1,000 employees in a UTF-8 CSV (5 MB maximum). Comma, semicolon and tab separators are detected. Blank cells preserve existing values. Login access and passwords are never changed.</p><p><a class="text-primary-600 underline" download="employees.csv" href="data:text/csv;base64,'.$basic.'">Employee template</a> · <a class="text-primary-600 underline" download="employees-payroll.csv" href="data:text/csv;base64,'.$payroll.'">Payroll template</a></p><p>Payroll amounts are INR, not paise, without commas (8000.00). Pay basis: monthly, daily or hourly. Dates: YYYY-MM-DD. New payroll profiles require pay_basis, basic_salary and effective_from; payroll_enabled defaults to 0. Use organisation branch/department IDs and task_group_ids separated by |. Department must belong to the branch. Device privilege: 0 or 14. Booleans: 0/1, true/false or yes/no.</p>');
                })
                ->steps([
                    Step::make('Upload and validate')->schema([
                        Select::make('mode')->label('Existing employees')
                            ->options(['create' => 'Create only — reject existing PINs', 'upsert' => 'Create and update by PIN'])
                            ->default('create')->required(),
                        FileUpload::make('file')->label('CSV file')
                            ->acceptedFileTypes(['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/plain'])
                            ->disk('local')->directory(fn () => app(UserCsvImportService::class)->directory())
                            ->maxSize(5120)->required()->storeFiles(true),
                    ])->afterValidation(function (Step $component): void {
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
                        $this->importPreview = "Validated {$preview['delimiter']}-separated CSV: {$preview['created']} new employees, {$preview['updated']} existing employees updated, {$preview['payroll']} payroll profiles included. All records are revalidated on submission and saved together; an error rolls back the entire upload.";
                        $this->importFingerprint = $preview['fingerprint'];
                    }),
                    Step::make('Review and import')->schema([
                        Text::make(fn (): string => $this->importPreview),
                        Checkbox::make('confirmed')->label('I checked the import mode, employee details and payroll rates.')->accepted()->required(),
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
                    Notification::make()->title('Import complete')
                        ->body("Created {$result['created']} employees and updated {$result['updated']}. Changed payroll profiles require salary slips to be recalculated.")
                        ->success()->send();
                }),
        ];
    }

    private function rejectImport(Schema $schema, ValidationException $exception): never
    {
        $message = implode(' ', $exception->validator->errors()->all());
        Notification::make()->title('Import not saved')->body($message)->danger()->send();
        throw ValidationException::withMessages([$schema->getStatePath().'.file' => $message]);
    }
}
