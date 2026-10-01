<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;
use App\Services\Attendance\ReportService;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;

class Reports extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.tenant.pages.reports';

    public $activeTab = 'daily';

    // Filament Table State
    public ?array $tableFilters = null;

    public function mount()
    {
        $this->tableFilters['date_range']['from_date'] = now()->format('Y-m-d');
        $this->tableFilters['date_range']['to_date'] = now()->format('Y-m-d');
    }

    public function updatedActiveTab()
    {
        if (in_array($this->activeTab, ['daily', 'weekly', 'monthly'], true)) {
            $this->selectReportDate(now()->toDateString());
        }
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
        // Use Filament's filter lifecycle to synchronize the popover and reset pagination.
        $this->updatedTableFilters();
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
        return Carbon::parse($this->tableFilters['date_range']['from_date'] ?? now()->toDateString());
    }

    public function generateReportData()
    {
        $branchIds = $this->getTableFilterState('branch_id')['values'] ?? [];
        $departmentIds = $this->getTableFilterState('department_id')['values'] ?? [];
        $userIds = $this->getTableFilterState('user_ids')['values'] ?? [];
        $dateRange = $this->getTableFilterState('date_range') ?? [];

        $query = User::query();
        if (! empty($branchIds)) {
            $query->whereIn('branch_id', $branchIds);
        }
        if (! empty($departmentIds)) {
            $query->whereIn('department_id', $departmentIds);
        }
        if (! empty($userIds)) {
            $query->whereIn('id', $userIds);
        }

        $finalUserIds = $query->pluck('id')->toArray();

        if (empty($finalUserIds)) {
            return null;
        }

        $service = new ReportService;

        return $service->generateReport(
            $finalUserIds,
            $dateRange['from_date'] ?? now()->format('Y-m-d'),
            $dateRange['to_date'] ?? now()->format('Y-m-d')
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
            $header[] = $date['month_day'];
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
                $csvRow[] = $row['daily'][$d]['display'] ?? 'Absent';
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
        $fromDate = $dateRange['from_date'] ?? now()->format('Y-m-d');
        $toDate = $dateRange['to_date'] ?? now()->format('Y-m-d');

        $pdf = Pdf::loadView('filament.tenant.pages.pdf.report-matrix', [
            'reportData' => $reportData,
            'activeTab' => $this->activeTab,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'tenantName' => Filament::getTenant()?->name ?? 'Company Name',
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $this->activeTab.'_report_'.now()->format('Y-m-d').'.pdf');
    }

    public function table(Table $table): Table
    {
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
            ->query(User::query())
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->multiple()
                    ->options(Branch::all()->pluck('display_name', 'id')),
                SelectFilter::make('department_id')
                    ->label('Department')
                    ->multiple()
                    ->options(Department::pluck('name', 'id')),
                SelectFilter::make('user_ids')
                    ->attribute('id')
                    ->label('Employees')
                    ->multiple()
                    ->options(User::pluck('name', 'id')),
                Filter::make('date_range')
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
                        ->action('downloadPdf'),
                    Action::make('downloadCsv')
                        ->label('Download CSV')
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        ->action('downloadCsv'),
                ])
                    ->label('Export Options')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->button(),
            ])
            ->content(fn () => view('filament.tenant.pages.report-table-content', [
                'activeTab' => $this->activeTab,
            ]));
    }
}
