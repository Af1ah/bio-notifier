<?php

namespace App\Livewire\Master;

use App\Models\User;
use App\Models\Organisation;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

class TenantUsersTable extends Component implements HasForms, HasTable, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public Organisation $organisation;

    public function table(Table $table): Table
    {
        // Initialize tenant context for multi-db
        tenancy()->initialize($this->organisation);

        return $table
            ->query(User::query())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('pin')
                    ->label('Employee Code'),
                TextColumn::make('email')
                    ->placeholder('-'),
                TextColumn::make('privilege_label')
                    ->label('Role')
                    ->badge(),
            ]);
    }

    public function render()
    {
        return view('livewire.master.tenant-users-table');
    }
}
