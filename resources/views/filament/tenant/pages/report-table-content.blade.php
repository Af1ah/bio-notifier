@php
    $reportData = $this->generateReportData();
@endphp

@if($reportData)
    <div class="overflow-x-auto" id="report-print-area">
        <div class="p-4 border-b border-gray-200 dark:border-white/10 flex justify-between items-center print:hidden">
            <div>
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ ucfirst($activeTab) }} Attendance Report
                </h3>
                @php
                    $dateRange = $this->getTableFilterState('date_range') ?? [];
                    $fromDate = $dateRange['from_date'] ?? now()->format('Y-m-d');
                    $toDate = $dateRange['to_date'] ?? now()->format('Y-m-d');
                @endphp
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ \Carbon\Carbon::parse($fromDate)->format('M d, Y') }} - {{ \Carbon\Carbon::parse($toDate)->format('M d, Y') }}
                </p>
            </div>
        <div id="print-only-header">
            <h2 class="text-center font-bold text-lg text-black">{{ ucfirst($activeTab) }} Status Report (Basic Report)</h2>
            <p class="text-center text-sm text-black mb-4">
                {{ \Carbon\Carbon::parse($fromDate)->format('M d Y') }} &nbsp; To &nbsp; {{ \Carbon\Carbon::parse($toDate)->format('M d Y') }}
            </p>
            <div class="flex justify-between text-sm text-black border-b border-black pb-2">
                <div>
                    <span class="font-bold">Company:</span> {{ \Filament\Facades\Filament::getTenant()?->name ?? 'Company Name' }}
                </div>
                <div>
                    Printed On : {{ now()->format('M d Y H:i') }}
                </div>
            </div>
        </div>
        
        <table class="report-matrix-table">
            <thead>
                <tr>
                    <th class="print:text-[10px]">Sl</th>
                    <th class="print:text-[10px]">Name</th>
                    @foreach ($reportData['period'] as $date)
                        <th class="center">
                            {{ $date['day'] }}<br>
                            <span class="text-xs font-normal text-gray-500">{{ $date['month_day'] }}</span>
                        </th>
                    @endforeach
                    <th>Total Hrs</th>
                    <th>Present</th>
                    <th>Absent</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reportData['data'] as $index => $row)
                <tr>
                    <td class="center print:text-[10px]">{{ $index + 1 }}</td>
                    <td class="font-medium text-gray-950 dark:text-white print:text-[10px] print:text-black">
                        {{ $row['user_name'] }}
                    </td>
                    @foreach ($reportData['period'] as $date)
                        @php
                            $d = $date['date'];
                            $dayData = $row['daily'][$d] ?? null;
                        @endphp
                        <td class="center">
                            @if($dayData && $dayData['status'] === 'P')
                                <x-filament::badge color="success" size="sm">
                                    {{ $dayData['display'] }}
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="danger" size="sm">
                                    A
                                </x-filament::badge>
                            @endif
                        </td>
                    @endforeach
                    <td class="font-bold text-gray-950 dark:text-white">{{ $row['total_display'] }}</td>
                    <td class="font-bold text-success-600">{{ $row['present'] }}</td>
                    <td class="font-bold text-danger-600">{{ $row['absent'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    
    <style>
        .report-matrix-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .report-matrix-table thead {
            background-color: rgb(249 250 251);
        }
        .dark .report-matrix-table thead {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .report-matrix-table th, .report-matrix-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid rgb(229 231 235);
            white-space: nowrap;
        }
        .dark .report-matrix-table th, .dark .report-matrix-table td {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }
        .report-matrix-table th {
            font-size: 0.875rem;
            font-weight: 600;
            color: rgb(3 7 18);
        }
        .dark .report-matrix-table th {
            color: white;
        }
        .report-matrix-table td {
            font-size: 0.875rem;
        }
        .report-matrix-table th.center, .report-matrix-table td.center {
            text-align: center;
        }
        .report-matrix-table tbody tr:hover {
            background-color: rgb(249 250 251);
        }
        .dark .report-matrix-table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        #print-only-header {
            display: none;
        }
        
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }
            body * {
                visibility: hidden;
            }
            #report-print-area, #report-print-area * {
                visibility: visible;
                color: black !important;
            }
            #report-print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none !important;
                background-color: white !important;
            }
            /* Show our custom print header, hide the normal screen header */
            #print-only-header {
                display: block !important;
                margin-bottom: 20px;
            }
            #report-print-area > div:first-child {
                display: none !important;
            }
            
            /* Enforce Exact PDF Style Table */
            .report-matrix-table {
                width: 100%;
                border-collapse: collapse !important;
            }
            .report-matrix-table th, .report-matrix-table td {
                border: 1px solid black !important;
                padding: 2px 4px !important;
                font-size: 10px !important;
                background-color: transparent !important;
                color: black !important;
            }
            .report-matrix-table th {
                font-weight: bold !important;
            }
            
            /* Remove badges for print, just show text */
            .fi-badge {
                background: transparent !important;
                color: black !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                font-weight: normal !important;
            }
            .fi-badge-label {
                color: black !important;
                font-weight: normal !important;
            }
        }
    </style>
@else
    <div class="p-12 text-center text-gray-500 dark:text-gray-400 flex flex-col justify-center items-center">
        <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="h-12 w-12 text-gray-400 mb-4" />
        <p>No attendance records found for the selected criteria.</p>
        <p class="text-sm mt-1">Please adjust your filters to view the report.</p>
    </div>
@endif
