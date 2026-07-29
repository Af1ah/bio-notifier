<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Tenant\Resources\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('syncEbioUsers')
                ->label('Sync from eBioServer')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sync Users from eBioServer')
                ->modalDescription('This will connect to your eBioServer over the local network and pull all registered users. This may take a few moments depending on the number of users.')
                ->action(function () {
                    try {
                        $service = new \App\Services\EbioSoapService();
                        $result = $service->syncUsers(tenant());
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Sync Complete')
                            ->body("Successfully synced {$result['synced']} users." . ($result['errors'] > 0 ? " {$result['errors']} failed." : ""))
                            ->status($result['errors'] > 0 ? 'warning' : 'success')
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Sync Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('importUsers')
                ->label('Import Users')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('info')
                ->modalHeading('Import Users')
                ->modalDescription(function () {
                    $csvContent = "pin,name,email,card_number,privilege,device_password,is_enabled,branch_id,department_id,group\n" .
                                  "1001,amal das,,,,,,,,\n" .
                                  "1002,shamil ,shamil@example.com,12345678,0,1234,1,1,2,Staff\n";
                    $base64Csv = base64_encode($csvContent);
                    $dataUri = "data:text/csv;base64,{$base64Csv}";
                    
                    return new \Illuminate\Support\HtmlString('
                        <div class="mb-4">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                <p class="text-sm text-gray-600 dark:text-gray-400" style="flex: 1; min-width: 250px; margin: 0;">
                                    Upload a CSV file containing user data. The file must include column headers matching the field names.
                                </p>
                                <div>
                                    <a href="'.$dataUri.'" download="users_import_example.csv" 
                                       style="display: inline-flex; align-items: center; gap: 0.5rem; background-color: #10b981; color: #ffffff; padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; text-decoration: none; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); border: 1px solid #059669; transition: all 0.2s ease-in-out;"
                                       onmouseover="this.style.backgroundColor=\'#059669\'" 
                                       onmouseout="this.style.backgroundColor=\'#10b981\'">
                                        <svg style="width: 16px; height: 16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        Download Example CSV
                                    </a>
                                </div>
                            </div>
                        </div>
                    ');
                })
                ->form([
                    \Filament\Forms\Components\FileUpload::make('file')
                        ->label('CSV File')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/plain'])
                        ->disk('local')
                        ->directory('imports')
                        ->required()
                        ->storeFiles(true)
                ])
                ->action(function (array $data) {
                    $filePath = \Illuminate\Support\Facades\Storage::disk('local')->path($data['file']);
                    
                    if (!file_exists($filePath) || !is_readable($filePath)) {
                        \Filament\Notifications\Notification::make()->title('File not found or unreadable.')->danger()->send();
                        return;
                    }

                    $header = null;
                    $users = [];
                    if (($handle = fopen($filePath, 'r')) !== false) {
                        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                            if (!$header) {
                                $header = array_map(function($h) { return trim(strtolower($h)); }, $row);
                            } else {
                                if (count($header) == count($row)) {
                                    $users[] = array_combine($header, $row);
                                }
                            }
                        }
                        fclose($handle);
                    }
                    
                    $successCount = 0;
                    $errorCount = 0;
                    
                    foreach ($users as $userData) {
                        if (empty($userData['pin']) || empty($userData['name'])) {
                            $errorCount++;
                            continue;
                        }
                        
                        $existingUser = \App\Models\User::where('pin', $userData['pin'])->first();
                        
                        $userAttributes = [
                            'name' => $userData['name'],
                        ];
                        
                        $fields = ['email', 'card_number', 'device_password', 'group'];
                        foreach ($fields as $field) {
                            if (isset($userData[$field]) && $userData[$field] !== '') {
                                $userAttributes[$field] = $userData[$field];
                            }
                        }
                        
                        if (isset($userData['privilege']) && $userData['privilege'] !== '') {
                            $userAttributes['privilege'] = (int) $userData['privilege'];
                        }
                        if (isset($userData['is_enabled']) && $userData['is_enabled'] !== '') {
                            $userAttributes['is_enabled'] = filter_var($userData['is_enabled'], FILTER_VALIDATE_BOOLEAN);
                        }
                        if (isset($userData['branch_id']) && $userData['branch_id'] !== '') {
                            $userAttributes['branch_id'] = (int) $userData['branch_id'];
                        }
                        if (isset($userData['department_id']) && $userData['department_id'] !== '') {
                            $userAttributes['department_id'] = (int) $userData['department_id'];
                        }
                        
                        try {
                            if ($existingUser) {
                                $existingUser->update($userAttributes);
                            } else {
                                $userAttributes['pin'] = $userData['pin'];
                                \App\Models\User::create($userAttributes);
                            }
                            $successCount++;
                        } catch (\Exception $e) {
                            $errorCount++;
                        }
                    }
                    
                    @unlink($filePath);
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Import Complete')
                        ->body("Successfully imported {$successCount} users." . ($errorCount > 0 ? " {$errorCount} failed." : ""))
                        ->status($errorCount > 0 ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }
}
