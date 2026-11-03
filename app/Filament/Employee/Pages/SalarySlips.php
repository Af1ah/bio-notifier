<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Tenant\Resources\SalarySlips\Schemas\SalarySlipInfolist;
use App\Models\SalarySlip;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Payroll\SalarySlipExportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalarySlips extends PortalPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.employee.pages.salary-slips';

    protected function availableSlips(): Builder
    {
        return SalarySlip::where('user_id', PortalAccess::employee()->id)->whereIn('status', ['approved', 'paid']);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->availableSlips())
            ->columns([
                TextColumn::make('period_start')->label('Month')->date('F Y')->sortable(),
                TextColumn::make('net_pay_minor')->label('Net pay')->formatStateUsing(fn (SalarySlip $record) => $record->formattedMoney('net_pay_minor')),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'paid' ? 'success' : 'primary'),
                TextColumn::make('approved_at')->label('Approved')->date('d M Y')->visibleFrom('md'),
            ])
            ->recordActions([ActionGroup::make([
                ViewAction::make()->slideOver()->modalWidth('5xl')
                    ->schema(fn (Schema $schema) => SalarySlipInfolist::configure($schema)),
                Action::make('download')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')
                    ->action(function (SalarySlip $record) {
                        $slip = $this->availableSlips()->findOrFail($record->id);

                        return app(SalarySlipExportService::class)->pdf(
                            $this->availableSlips()->whereKey($slip->id), tenant()->name, $slip->period_start
                        );
                    }),
            ])->label('Actions')])
            ->recordAction('view')
            ->defaultSort('period_start', 'desc')->defaultKeySort()
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions()
            ->emptyStateHeading('No approved salary slips yet');
    }
}
