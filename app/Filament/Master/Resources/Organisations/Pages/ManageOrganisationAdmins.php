<?php

namespace App\Filament\Master\Resources\Organisations\Pages;

use App\Filament\Master\Resources\Organisations\OrganisationResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Forms;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;

class ManageOrganisationAdmins extends Page implements HasTable, HasForms
{
    use InteractsWithRecord;
    use InteractsWithTable;
    use InteractsWithForms;

    protected static string $resource = OrganisationResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $title = 'Manage Admins';

    protected string $view = 'filament.master.resources.organisations.pages.manage-organisation-admins';

    public function boot(): void
    {
        $recordId = request()->route('record') ?? request()->input('components.0.snapshot');
        // Livewire 3 snapshot handling for record
        if (!$recordId && isset($this->record)) {
            $recordId = $this->record->id ?? $this->record;
        }

        if (is_string($recordId) && str_contains($recordId, '{')) {
            // Livewire snapshot JSON parsing
            $snapshot = json_decode($recordId, true);
            if (isset($snapshot['data']['record'])) {
                $recordId = $snapshot['data']['record'];
            }
        }
        
        // Simpler: let's just resolve the record
        // In Filament page, $this->record is usually hydrated automatically.
        if (isset($this->record) && $this->record instanceof \App\Models\Organisation) {
            tenancy()->initialize($this->record);
        } else {
            $id = request()->route('record');
            if ($id) {
                $tenant = \App\Models\Organisation::find($id);
                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            } else {
                // If Livewire request, we can get it from the component state
                // We will rely on mount() to set it first.
            }
        }
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        tenancy()->initialize($this->record);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query())
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->model(User::class)
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email'),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->required()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state)),
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'user' => 'User',
                            ])
                            ->default('admin')
                            ->required(),
                    ]),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email', ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state)),
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'user' => 'User',
                            ])
                            ->required(),
                    ]),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
