<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ExpectedVsCollectedExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        $rows = [];
        $reportData = $this->data['report_data'] ?? [];
        $totals = [
            'outstanding_fees' => 0.0,
            'arrears_before_period' => 0.0,
            'due_instalment' => 0.0,
            'accrued_penalties' => 0.0,
            'total_instalment_due' => 0.0,
            'amount_paid' => 0.0,
            'balance_due' => 0.0,
        ];

        foreach ($reportData as $index => $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }

            $rows[] = [
                $index + 1,
                $row['customer'],
                $row['customer_no'],
                $row['phone'],
                $row['loan_amount'],
                $row['disbursed_date'],
                $row['loan_officer'],
                $row['instalment_due_dates'] ?? '',
                $row['outstanding_fees'] ?? 0,
                $row['arrears_before_period'] ?? 0,
                $row['due_instalment'] ?? 0,
                $row['accrued_penalties'] ?? 0,
                $row['total_instalment_due'] ?? 0,
                $row['amount_paid'] ?? 0,
                $row['balance_due'] ?? 0,
            ];
        }

        if (count($reportData) > 0) {
            $rows[] = [
                'TOTALS',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $totals['outstanding_fees'],
                $totals['arrears_before_period'],
                $totals['due_instalment'],
                $totals['accrued_penalties'],
                $totals['total_instalment_due'],
                $totals['amount_paid'],
                $totals['balance_due'],
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            ['Expected vs Collected Report'],
            ['Generated:', $this->data['generated_date']],
            ['Period:', \Carbon\Carbon::parse($this->data['start_date'])->format('d-m-Y') . ' to ' . \Carbon\Carbon::parse($this->data['end_date'])->format('d-m-Y')],
            ['Branch:', $this->data['branch_name']],
            ['Group:', $this->data['group_name']],
            ['Loan Officer:', $this->data['loan_officer_name']],
            [],
            [
                '#',
                'Customer',
                'Customer No',
                'Phone',
                'Loan Amount',
                'Disbursed Date',
                'Loan Officer',
                'Instalment due date(s)',
                'Outstanding Fees',
                'Arrears (before period)',
                'Due Instalment',
                'Accrued Penalties',
                'Total Instalment due',
                'Amount paid',
                'Balance Due',
            ],
        ];
    }

    public function title(): string
    {
        return 'Expected vs Collected Report';
    }

    public function styles(Worksheet $sheet)
    {
        $headerRow = 8;
        $lastRow = (int) $sheet->getHighestRow();

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 16]],
            $headerRow => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];

        if ($lastRow > $headerRow) {
            $styles[$lastRow] = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '343A40']],
            ];
        }

        return $styles;
    }
}
