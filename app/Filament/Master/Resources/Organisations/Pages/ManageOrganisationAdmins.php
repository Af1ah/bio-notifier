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
use App\Models\Organisation;
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
        // On updates Livewire restores this locked model after verifying the
        // snapshot, before boot hooks (including the table's boot hook) run.
        if (isset($this->record) && $this->record instanceof Organisation) {
            tenancy()->initialize($this->record);

            return;
        }

        // Initial GET: table boot precedes mount, so use the routed record.
        $id = request()->route('record');
        if (is_string($id) || is_int($id)) {
            tenancy()->initialize($this->resolveRecord($id));
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
            ->query(User::on('tenant'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('privilege')
                    ->label('Application Access')
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        14 => 'Admin',
                        0 => 'User',
                        default => 'Unknown',
                    })
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
                            ->unique('tenant.users', 'email', ignoreRecord: false),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('privilege')
                            ->label('Application Access')
                            ->options([
                                14 => 'Admin',
                                0 => 'User',
                            ])
                            ->default(14)
                            ->helperText('Admin access requires an email address and password.')
                            ->required(),
                        Forms\Components\Hidden::make('pin')
                            ->default(fn () => (string) rand(10000, 99999)),
                    ])
                    ->using(function (array $data, string $model): \Illuminate\Database\Eloquent\Model {
                        $user = new $model($data);
                        $user->setConnection('tenant');
                        $user->save();
                        return $user;
                    }),
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
                            ->unique('tenant.users', 'email', ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->maxLength(255)
                            ->afterStateHydrated(fn (Forms\Components\TextInput $component) => $component->state(null))
                            ->helperText('Leave blank to keep the current password.')
                            ->dehydrated(fn ($state) => filled($state)),
                        Forms\Components\Select::make('privilege')
                            ->label('Application Access')
                            ->options([
                                14 => 'Admin',
                                0 => 'User',
                            ])
                            ->helperText('Admin access requires an email address and password.')
                            ->required(),
                        Forms\Components\Hidden::make('pin')
                            ->default(fn () => (string) rand(10000, 99999)),
                    ])
                    ->using(function (\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model {
                        $record->setConnection('tenant');
                        $record->update($data);
                        return $record;
                    }),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
