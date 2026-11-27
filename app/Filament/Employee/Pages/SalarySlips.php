<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Shared\Filters\PayrollMonthFilter;
use App\Filament\Tenant\Resources\SalarySlips\Schemas\SalarySlipInfolist;
use App\Models\SalarySlip;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Payroll\SalarySlipExportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
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
        return SalarySlip::where('user_id', PortalAccess::employee()->id)->whereIn('status', ['draft', 'reviewed', 'approved', 'paid']);
    }

    protected function getViewData(): array
    {
        return ['latestPaid' => $this->availableSlips()->where('status', 'paid')->latest('period_start')->latest('id')->first()];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => $this->availableSlips())
            ->contentGrid(['default' => 1])
            ->columns([Split::make([
                Stack::make([
                    TextColumn::make('period_start')->label('Month')->date('M')->weight('bold')
                        ->action(ViewAction::make()->slideOver()->modalWidth('5xl')
                            ->schema(fn (Schema $schema) => SalarySlipInfolist::configure($schema))),
                    TextColumn::make('reviewed_at')->label('Reviewed')->date('d M Y')->visibleFrom('md'),
                ])->space(1),
                TextColumn::make('net_pay_minor')->label('Net pay')->extraAttributes(['class' => 'portal-number'])
                    ->formatStateUsing(fn (SalarySlip $record) => $record->currency === 'INR'
                        ? '₹'.number_format(((int) $record->net_pay_minor) / 100, 2)
                        : $record->formattedMoney('net_pay_minor')),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($record) => $record->display_status)->color(fn ($state) => match ($state) {
                    'paid' => 'success', 'draft' => 'gray', default => 'primary',
                }),
            ])])
            ->filters([
                PayrollMonthFilter::make(fn () => $this->availableSlips())->default('all')
                ->indicateUsing(fn (array $data): ?string => is_string($data['value'] ?? null) && $data['value'] !== 'all'
                    ? $data['value'] : null),
            ])
            ->recordActions([
                Action::make('download')->label('Download PDF')->icon('heroicon-o-arrow-down-tray')->iconButton()
                ->action(function (SalarySlip $record) {
                    $slip = $this->availableSlips()->findOrFail($record->id);

                    return app(SalarySlipExportService::class)->pdf(
                        $this->availableSlips()->whereKey($slip->id), tenant()->name, $slip->period_start
                    );
                }),
            ])
            ->defaultSort('period_start', 'desc')->defaultKeySort()
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions()
            ->emptyStateHeading('No salary slips yet');
    }
}
