<?php

namespace App\Filament\Tenant\Pages;

use App\Models\Report;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Carbon\Carbon;

class Reports extends Page implements HasTable, HasForms
{
    use InteractsWithTable, InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

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
        if ($this->activeTab === 'daily') {
            $this->tableFilters['date_range']['from_date'] = now()->format('Y-m-d');
            $this->tableFilters['date_range']['to_date'] = now()->format('Y-m-d');
        } elseif ($this->activeTab === 'weekly') {
            $this->tableFilters['date_range']['from_date'] = now()->startOfWeek()->format('Y-m-d');
            $this->tableFilters['date_range']['to_date'] = now()->endOfWeek()->format('Y-m-d');
        } elseif ($this->activeTab === 'monthly') {
            $this->tableFilters['date_range']['from_date'] = now()->startOfMonth()->format('Y-m-d');
            $this->tableFilters['date_range']['to_date'] = now()->endOfMonth()->format('Y-m-d');
        }
    }

    public function generateReportData()
    {
        $branchIds = $this->getTableFilterState('branch_id')['values'] ?? [];
        $departmentIds = $this->getTableFilterState('department_id')['values'] ?? [];
        $userIds = $this->getTableFilterState('user_ids')['values'] ?? [];
        $dateRange = $this->getTableFilterState('date_range') ?? [];
        
        $query = User::query();
        if (!empty($branchIds)) {
            $query->whereIn('branch_id', $branchIds);
        }
        if (!empty($departmentIds)) {
            $query->whereIn('department_id', $departmentIds);
        }
        if (!empty($userIds)) {
            $query->whereIn('id', $userIds);
        }
        
        $finalUserIds = $query->pluck('id')->toArray();
        
        if (empty($finalUserIds)) {
            return null;
        }

        $service = new ReportService();
        return $service->generateReport(
            $finalUserIds, 
            $dateRange['from_date'] ?? now()->format('Y-m-d'), 
            $dateRange['to_date'] ?? now()->format('Y-m-d')
        );
    }

    public function downloadCsv()
    {
        $reportData = $this->generateReportData();
        if (!$reportData) return;
        
        $csvData = [];
        $header = ['Employee'];
        foreach ($reportData['period'] as $date) {
            $header[] = $date['month_day'];
        }
        $header[] = 'Total Hrs';
        $header[] = 'Present';
        $header[] = 'Absent';
        $csvData[] = implode(',', $header);
        
        foreach ($reportData['data'] as $row) {
            $csvRow = ['"' . $row['user_name'] . '"'];
            foreach ($reportData['period'] as $date) {
                $d = $date['date'];
                $csvRow[] = $row['daily'][$d]['display'] ?? 'Absent';
            }
            $csvRow[] = $row['total_display'];
            $csvRow[] = $row['present'];
            $csvRow[] = $row['absent'];
            $csvData[] = implode(',', $csvRow);
        }
        
        return response()->streamDownload(function() use ($csvData) {
            echo implode("\n", $csvData);
        }, $this->activeTab . '_report.csv');
    }

    public function downloadPdf()
    {
        $reportData = $this->generateReportData();
        if (!$reportData) return;
        
        $dateRange = $this->getTableFilterState('date_range') ?? [];
        $fromDate = $dateRange['from_date'] ?? now()->format('Y-m-d');
        $toDate = $dateRange['to_date'] ?? now()->format('Y-m-d');
        
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('filament.tenant.pages.pdf.report-matrix', [
            'reportData' => $reportData,
            'activeTab' => $this->activeTab,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'tenantName' => \Filament\Facades\Filament::getTenant()?->name ?? 'Company Name'
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $this->activeTab . '_report_' . now()->format('Y-m-d') . '.pdf');
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
                    \Filament\Actions\Action::make('view')
                        ->label('View')
                        ->icon('heroicon-o-eye')
                        ->modalContent(fn (Report $record) => view('filament.tenant.pages.report-view', ['report' => $record]))
                        ->modalSubmitAction(false)
                ]);
        }

        return $table
            ->query(User::query())
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->multiple()
                    ->options(Branch::all()->pluck('display_name', 'id')),
                \Filament\Tables\Filters\SelectFilter::make('department_id')
                    ->label('Department')
                    ->multiple()
                    ->options(Department::pluck('name', 'id')),
                \Filament\Tables\Filters\SelectFilter::make('user_ids')
                    ->attribute('id')
                    ->label('Employees')
                    ->multiple()
                    ->options(User::pluck('name', 'id')),
                \Filament\Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from_date')->label('From Date'),
                        DatePicker::make('to_date')->label('To Date'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from_date'] ?? null) {
                            $indicators['from_date'] = 'From: ' . Carbon::parse($data['from_date'])->format('M d, Y');
                        }
                        if ($data['to_date'] ?? null) {
                            $indicators['to_date'] = 'To: ' . Carbon::parse($data['to_date'])->format('M d, Y');
                        }
                        return $indicators;
                    })
            ])
            ->headerActions([
                \Filament\Actions\ActionGroup::make([
                    \Filament\Actions\Action::make('downloadPdf')
                        ->label('Print PDF')
                        ->icon('heroicon-o-printer')
                        ->color('danger')
                        ->action('downloadPdf'),
                    \Filament\Actions\Action::make('downloadCsv')
                        ->label('Download CSV')
                        ->icon('heroicon-o-document-text')
                        ->color('success')
                        ->action('downloadCsv'),
                ])
                ->label('Export Options')
                ->icon('heroicon-m-arrow-down-tray')
                ->button()
            ])
            ->content(fn () => view('filament.tenant.pages.report-table-content', [
                'activeTab' => $this->activeTab,
            ]));
    }
}
