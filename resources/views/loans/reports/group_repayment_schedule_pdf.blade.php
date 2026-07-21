<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Group Repayment Schedule Card</title>
    <style>
        @page {
            size: A3 landscape;
            margin: 10mm;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        @include('loans.reports.partials.pdf_page_shell_styles')

        body {
            font-size: 8px;
            line-height: 1.35;
        }

        @include('loans.reports.partials.pdf_company_header_styles')

        .group-meta {
            text-align: center;
            font-size: 9px;
            color: #374151;
            margin: 4px 0 8px;
        }

        .group-name {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a3a5c;
            letter-spacing: 0.4px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #1f2937;
            padding: 3px 4px;
        }

        table.data-table th {
            background-color: #1a3a5c;
            color: #ffffff;
            text-align: center;
            font-size: 7px;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.2px;
        }

        table.data-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .member-name {
            text-align: left;
            font-weight: bold;
            white-space: nowrap;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .dash {
            text-align: center;
            color: #9ca3af;
        }

        table.data-table tfoot td {
            background-color: #e5e7eb;
            font-weight: bold;
            font-size: 7.5px;
        }

        table.signatures {
            width: 100%;
            margin-top: 22px;
        }

        table.signatures td {
            border: none;
            width: 50%;
            vertical-align: top;
            padding: 0 10px;
        }

        .sign-line {
            border-top: 1.5px solid #111827;
            padding-top: 5px;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
    </style>
</head>
<body>
    <div class="pdf-page-container">
    @php
        $group = $reportData['group'];
        $loanOfficerName = $group->loanOfficer->name ?? 'N/A';
        $chairpersonName = $group->groupLeader->name ?? 'N/A';
        $reportInfo = '<span class="group-name">' . e($group->name) . '</span><br>'
            . 'CO: ' . e($loanOfficerName) . ' | CHAIRPERSON: ' . e($chairpersonName) . '<br>'
            . '<strong>Period:</strong> ' . \Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' - ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y');
    @endphp

    @include('loans.reports.partials.pdf_company_header', [
        'company' => $company,
        'reportTitle' => 'Group Repayment Schedule Card',
        'reportInfo' => $reportInfo,
    ])

    <table class="data-table">
        <thead>
            <tr>
                <th>NO</th>
                <th>MEMBER NAME</th>
                <th>CYCLE</th>
                <th>SECURITY</th>
                <th>LOAN NO.</th>
                <th>DS AMOUNT</th>
                <th>DS DATE</th>
                <th>INST. SIZE</th>
                <th>C REAL.</th>
                <th>OS BAL.</th>
                @foreach($reportData['date_keys'] as $dateKey)
                    <th>{{ \Carbon\Carbon::parse($dateKey)->format('d/m') }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($reportData['rows'] as $row)
                <tr>
                    <td class="text-center">{{ $row['no'] }}</td>
                    <td class="member-name">{{ $row['member_name'] }}</td>
                    <td class="text-center">{{ $row['cycle'] }}</td>
                    <td class="text-right">{{ number_format($row['security'], 0) }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-right">{{ number_format($row['ds_amount'], 0) }}</td>
                    <td class="text-center">{{ $row['ds_date'] ? \Carbon\Carbon::parse($row['ds_date'])->format('d/m') : '-' }}</td>
                    <td class="text-right">{{ number_format($row['installment_size'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['c_realization'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['os_balance'], 0) }}</td>
                    @foreach($reportData['date_keys'] as $dateKey)
                        <td class="{{ isset($row['date_amounts'][$dateKey]) ? 'text-right' : 'dash' }}">
                            @if(isset($row['date_amounts'][$dateKey]))
                                {{ number_format($row['date_amounts'][$dateKey], 0) }}
                            @else
                                -
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="10" class="text-right">TOTAL</td>
                @foreach($reportData['date_keys'] as $dateKey)
                    <td class="text-right">{{ number_format($reportData['column_totals'][$dateKey] ?? 0, 0) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>

    <table class="signatures">
        <tr>
            <td><div class="sign-line">JINA LA MUHASIBU</div></td>
            <td><div class="sign-line">SAHIHI YA MUHASIBU</div></td>
        </tr>
    </table>
    </div>
</body>
</html>
