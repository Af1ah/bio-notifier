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
        $id = request()->route('record');
        
        // Handle Livewire 3 snapshot requests where route parameter is missing
        if (!$id && request()->has('components.0.snapshot')) {
            $snapshot = json_decode(request()->input('components.0.snapshot'), true);
            if (isset($snapshot['data']['record'])) {
                $id = $snapshot['data']['record'];
            }
        }

        if ($id) {
            $tenant = \App\Models\Organisation::find($id);
            if ($tenant) {
                tenancy()->initialize($tenant);
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
                            ->maxLength(255),
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'user' => 'User',
                            ])
                            ->default('admin')
                            ->required(),
                        Forms\Components\Hidden::make('privilege')
                            ->default(14),
                        Forms\Components\Hidden::make('pin')
                            ->default(fn () => (string) rand(10000, 99999)),
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
                            ->dehydrated(fn ($state) => filled($state)),
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Admin',
                                'manager' => 'Manager',
                                'user' => 'User',
                            ])
                            ->required(),
                        Forms\Components\Hidden::make('privilege')
                            ->default(14),
                        Forms\Components\Hidden::make('pin')
                            ->default(fn () => (string) rand(10000, 99999)),
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
