<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;
use App\Services\Attendance\ReportService;
use App\Services\Support\TenantTimezone;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

class Reports extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable {
        applyTableFilters as private applyFilamentFilters;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Reports';
    }

    public static function getNavigationBadge(): ?string
    {
        return 'Beta';
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return 'Reports (Beta)';
    }

    protected string $view = 'filament.tenant.pages.reports';

    public $activeTab = 'daily';

    // Filament Table State
    public ?array $tableFilters = null;

    #[Locked]
    public bool $reportRequested = false;

    public function mount()
    {
        $this->tableFilters['date_range']['from_date'] = now(TenantTimezone::get())->format('Y-m-d');
        $this->tableFilters['date_range']['to_date'] = now(TenantTimezone::get())->format('Y-m-d');
    }

    public function updatedActiveTab()
    {
        abort_unless(in_array($this->activeTab, ['daily', 'weekly', 'monthly', 'templates'], true), 422);

        $this->reportRequested = false;
        unset($this->tableFilters['date_range']);
        $this->resetTable();
    }

    public function selectReportDate(string $date): void
    {
        Validator::make(['date' => $date, 'tab' => $this->activeTab], [
            'date' => ['required', 'date_format:Y-m-d'],
            'tab' => ['required', 'in:daily,weekly,monthly'],
        ])->validate();

        $start = Carbon::parse($date);
        $end = $start->copy();
        if ($this->activeTab === 'weekly') {
            $start->startOfWeek(Carbon::MONDAY);
            $end = $start->copy()->addDays(6);
        } elseif ($this->activeTab === 'monthly') {
            $start->startOfMonth();
            $end->endOfMonth();
        }

        $this->tableFilters['date_range'] = [
            'from_date' => $start->toDateString(),
            'to_date' => $end->toDateString(),
        ];
        $this->reportRequested = true;
        // Use Filament's filter lifecycle to synchronize the popover and reset pagination.
        $this->updatedTableFilters();
        $this->flushCachedTableRecords();
    }

    public function applyTableFilters(): void
    {
        $dates = $this->tableDeferredFilters['date_range'] ?? [];
        $appliedDates = $this->tableFilters['date_range'] ?? [];
        if ($dates !== $appliedDates) {
            $this->reportRequested = filled($dates['from_date'] ?? null) || filled($dates['to_date'] ?? null);
        }

        $this->applyFilamentFilters();
        $this->flushCachedTableRecords();
    }

    public function previousReportWeek(): void
    {
        if ($this->activeTab === 'weekly') {
            $this->selectReportDate($this->reportStartDate()->subWeek()->toDateString());
        }
    }

    public function nextReportWeek(): void
    {
        if ($this->activeTab === 'weekly') {
            $this->selectReportDate($this->reportStartDate()->addWeek()->toDateString());
        }
    }

    protected function reportStartDate(): Carbon
    {
        return Carbon::parse($this->tableFilters['date_range']['from_date'] ?? now(TenantTimezone::get())->toDateString());
    }

    public function generateReportData()
    {
        if (! $this->hasReportDate()) {
            return null;
        }

        return $this->reportForEmployees($this->getTableQueryForExport()->get());
    }

    #[Unexposed]
    public function generateReportPage(): ?array
    {
        if (! $this->hasReportDate()) {
            return null;
        }

        return $this->reportForEmployees($this->getTableRecords()->getCollection());
    }

    private function hasReportDate(): bool
    {
        return $this->reportRequested && $this->activeTab !== 'templates'
            && (filled($this->tableFilters['date_range']['from_date'] ?? null)
                || filled($this->tableFilters['date_range']['to_date'] ?? null));
    }

    private function reportForEmployees(Collection $employees): ?array
    {
        if ($employees->isEmpty()) {
            return null;
        }

        $dateRange = $this->getTableFilterState('date_range') ?? [];

        return app(ReportService::class)->generateForEmployees(
            $employees,
            $dateRange['from_date'] ?? now(TenantTimezone::get())->format('Y-m-d'),
            $dateRange['to_date'] ?? now(TenantTimezone::get())->format('Y-m-d')
        );
    }

    public function downloadCsv()
    {
        $reportData = $this->generateReportData();
        if (! $reportData) {
            return;
        }

        $csvData = [];
        $header = ['Employee'];
        foreach ($reportData['period'] as $date) {
            $header[] = $date['month_day'].' HH:MM';
        }
        $header[] = 'Total Hrs';
        $header[] = 'Approved OT';
        $header[] = 'Present';
        $header[] = 'Half Day';
        $header[] = 'Absent';
        $csvData[] = implode(',', $header);

        foreach ($reportData['data'] as $row) {
            $csvRow = ['"'.$row['user_name'].'"'];
            foreach ($reportData['period'] as $date) {
                $d = $date['date'];
                $csvRow[] = $row['daily'][$d]['clock'] ?? '00:00';
            }
            $csvRow[] = $row['total_display'];
            $csvRow[] = $row['overtime_display'];
            $csvRow[] = $row['present'];
            $csvRow[] = $row['half_day'];
            $csvRow[] = $row['absent'];
            $csvData[] = implode(',', $csvRow);
        }

        return response()->streamDownload(function () use ($csvData) {
            echo implode("\n", $csvData);
        }, $this->activeTab.'_report.csv');
    }

    public function downloadPdf()
    {
        $reportData = $this->generateReportData();
        if (! $reportData) {
            return;
        }

        $dateRange = $this->getTableFilterState('date_range') ?? [];
        $fromDate = $dateRange['from_date'] ?? now(TenantTimezone::get())->format('Y-m-d');
        $toDate = $dateRange['to_date'] ?? now(TenantTimezone::get())->format('Y-m-d');

        $pdf = Pdf::loadView('filament.tenant.pages.pdf.report-matrix', [
            'reportData' => $reportData,
            'activeTab' => $this->activeTab,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'tenantName' => Filament::getTenant()?->name ?? 'Company Name',
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $this->activeTab.'_report_'.now(TenantTimezone::get())->format('Y-m-d').'.pdf');
    }

    public function table(Table $table): Table
    {
        $table->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions();

        if ($this->activeTab === 'templates') {
            return $table
                ->query(Report::query()->where('is_template', true))
                ->columns([
                    TextColumn::make('name')->label('Template Name'),
                    TextColumn::make('type')->label('Type')->badge(),
                    TextColumn::make('date_range')->label('Period')->badge()->color('gray'),
                    TextColumn::make('status')->label('Status')->badge(),
                    TextColumn::make('last_calculated_at')->label('Last Updates')->dateTime(),
                ])
                ->actions([
                    Action::make('view')
                        ->label('View')
                        ->icon('heroicon-o-eye')
                        ->modalContent(fn (Report $record) => view('filament.tenant.pages.report-view', ['report' => $record]))
                        ->modalSubmitAction(false),
                ]);
        }

        return $table
            ->query(User::employees())
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->multiple()
                    ->options(fn () => Branch::all()->pluck('display_name', 'id')),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->multiple()
                    ->options(fn () => Department::pluck('name', 'id')),
                Filter::make('user_ids')
                    ->label('Employees')
                    ->schema([
                        Select::make('values')->label('Employees')->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::employees()->whereLike('name', '%'.$search.'%')
                                ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                            ->getOptionLabelsUsing(fn (array $values): array => User::employees()->whereKey($values)->pluck('name', 'id')->all()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn (Builder $query, array $ids) => $query->whereKey($ids)))
                    ->indicateUsing(fn (array $data) => empty($data['values']) ? null : 'Employees: '.count($data['values'])),
                Filter::make('date_range')
                    ->query(fn (Builder $query) => $query->when(! $this->hasReportDate(), fn (Builder $query) => $query->whereRaw('1 = 0')))
                    ->form([
                        DatePicker::make('from_date')->label('From Date'),
                        DatePicker::make('to_date')->label('To Date'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from_date'] ?? null) {
                            $indicators['from_date'] = 'From: '.Carbon::parse($data['from_date'])->format('M d, Y');
                        }
                        if ($data['to_date'] ?? null) {
                            $indicators['to_date'] = 'To: '.Carbon::parse($data['to_date'])->format('M d, Y');
                        }

                        return $indicators;
                    }),
            ])
            ->headerActions([
                Action::make('previousReportWeek')
                    ->label('Previous week')->icon('heroicon-o-chevron-left')->color('gray')
                    ->visible(fn (): bool => $this->activeTab === 'weekly')
                    ->action(fn () => $this->previousReportWeek()),
                Action::make('selectReportDate')
                    ->label(fn (): string => $this->activeTab === 'daily' ? $this->reportStartDate()->format('d M Y') : 'Pick week')
                    ->icon('heroicon-o-calendar-days')->color('gray')
                    ->visible(fn (): bool => in_array($this->activeTab, ['daily', 'weekly'], true))
                    ->modalHeading(fn (): string => $this->activeTab === 'daily' ? 'Choose report date' : 'Choose report week')
                    ->modalSubmitActionLabel('Apply')
                    ->schema([
                        DatePicker::make('date')
                            ->label(fn (): string => $this->activeTab === 'daily' ? 'Date' : 'Date in week')
                            ->helperText(fn (): ?string => $this->activeTab === 'weekly' ? 'Select any date to show its Monday–Sunday week.' : null)
                            ->native(false)->displayFormat('d M Y')->required(),
                    ])
                    ->fillForm(fn (): array => ['date' => $this->reportStartDate()->toDateString()])
                    ->action(fn (array $data) => $this->selectReportDate($data['date'])),
                Action::make('nextReportWeek')
                    ->label('Next week')->icon('heroicon-o-chevron-right')->color('gray')
                    ->visible(fn (): bool => $this->activeTab === 'weekly')
                    ->action(fn () => $this->nextReportWeek()),
                Action::make('selectReportMonth')
                    ->label('Pick month')->icon('heroicon-o-calendar-days')->color('gray')
                    ->visible(fn (): bool => $this->activeTab === 'monthly')
                    ->modalHeading('Choose report month')->modalSubmitActionLabel('Apply')
                    ->schema([
                        Select::make('month')->label('Month')->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2000, $month, 1)->format('F')])->all())->required(),
                        TextInput::make('year')->label('Year')->integer()->minValue(1)->maxValue(9999)->required(),
                    ])
                    ->fillForm(fn (): array => ['month' => $this->reportStartDate()->month, 'year' => $this->reportStartDate()->year])
                    ->action(fn (array $data) => $this->selectReportDate(sprintf('%04d-%02d-01', $data['year'], $data['month']))),
                ActionGroup::make([
                    Action::make('downloadPdf')
                        ->label('Print PDF')
                        ->icon('heroicon-o-printer')
                        ->color('danger')
                        ->disabled(fn (): bool => ! $this->hasReportDate())
                        ->action('downloadPdf'),
                    Action::make('downloadCsv')
                        ->label('Download CSV')
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        ->disabled(fn (): bool => ! $this->hasReportDate())
                        ->action('downloadCsv'),
                ])
                    ->label('Export Options')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->button(),
            ])
            ->emptyStateHeading(fn (): string => $this->hasReportDate() ? 'No matching employees' : 'Choose a date to load the report')
            ->content(fn () => view('filament.tenant.pages.report-table-content', [
                'activeTab' => $this->activeTab,
            ]));
    }
}
