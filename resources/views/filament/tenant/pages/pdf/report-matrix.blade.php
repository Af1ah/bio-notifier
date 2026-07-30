<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ ucfirst($activeTab) }} Attendance Report</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 9px;
            margin: 0;
            padding: 0;
            color: #000;
        }
        .header-container {
            width: 100%;
            margin-bottom: 15px;
            text-align: center;
        }
        .header-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .header-subtitle {
            font-size: 11px;
            margin-bottom: 10px;
        }
        .header-details {
            width: 100%;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .header-details table {
            width: 100%;
            border: none;
        }
        .header-details td {
            border: none;
            padding: 0;
            font-size: 10px;
        }
        
        .matrix-table {
            width: 100%;
            border-collapse: collapse;
        }
        .matrix-table th, .matrix-table td {
            border: 1px solid #333;
            padding: 3px 2px;
            text-align: center;
        }
        .matrix-table th {
            background-color: #f3f4f6;
            font-weight: bold;
        }
        
        /* Make employee name column wider and left aligned */
        .matrix-table .col-name {
            text-align: left;
            padding-left: 5px;
            white-space: nowrap;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .text-present {
            color: #166534; /* dark green */
            font-weight: bold;
        }
        .text-absent {
            color: #991b1b; /* dark red */
            font-weight: bold;
        }
        .text-off {
            color: #4b5563; /* gray */
        }
        .text-late {
            color: #92400e; /* dark yellow/orange */
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="header-container">
        <div class="header-title">{{ ucfirst($activeTab) }} Status Report</div>
        <div class="header-subtitle">
            {{ \Carbon\Carbon::parse($fromDate)->format('M d Y') }} &nbsp; To &nbsp; {{ \Carbon\Carbon::parse($toDate)->format('M d Y') }}
        </div>
        
        <div class="header-details">
            <table>
                <tr>
                    <td style="text-align: left;"><b>Company:</b> {{ $tenantName }}</td>
                    <td style="text-align: right;"><b>Printed On:</b> {{ now()->format('M d Y H:i') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <table class="matrix-table">
        <thead>
            <tr>
                <th style="width: 15px;">#</th>
                <th class="col-name">Employee</th>
                @foreach ($reportData['period'] as $date)
                    <th>
                        {{ $date['day'] }}<br>
                        <span style="font-size: 7px; font-weight: normal;">{{ $date['month_day'] }}</span>
                    </th>
                @endforeach
                <th style="width: 30px;">Total<br>Hrs</th>
                <th style="width: 25px;">Prs</th>
                <th style="width: 25px;">Abs</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reportData['data'] as $index => $row)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="col-name">{{ $row['user_name'] }}</td>
                @foreach ($reportData['period'] as $date)
                    @php
                        $d = $date['date'];
                        $dayData = $row['daily'][$d] ?? null;
                        
                        $letter = 'A';
                        $class = 'text-absent';
                        
                        if ($dayData) {
                            if ($dayData['status'] === 'P') {
                                $letter = 'P';
                                $class = 'text-present';
                            } elseif ($dayData['status'] === 'O') {
                                $letter = 'O';
                                $class = 'text-off';
                            } elseif ($dayData['status'] === 'L') {
                                $letter = 'L';
                                $class = 'text-late';
                            } else {
                                $letter = substr($dayData['display'], 0, 1);
                            }
                        }
                    @endphp
                    <td class="{{ $class }}">{{ $letter }}</td>
                @endforeach
                <td style="font-weight: bold;">{{ $row['total_display'] }}</td>
                <td style="font-weight: bold;" class="text-present">{{ $row['present'] }}</td>
                <td style="font-weight: bold;" class="text-absent">{{ $row['absent'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

</body>
</html>
