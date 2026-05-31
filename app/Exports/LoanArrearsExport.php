<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LoanArrearsExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function array(): array
    {
        $exportData = [];
        $totals = [
            'loan_fees' => 0.0,
            'penalties' => 0.0,
            'instalment_in_arrears' => 0.0,
            'total_balance_in_arrears' => 0.0,
        ];

        if (count($this->data['arrears_data']) > 0) {
            foreach ($this->data['arrears_data'] as $index => $row) {
                foreach ($totals as $key => $value) {
                    $totals[$key] += (float) ($row[$key] ?? 0);
                }

                $exportData[] = [
                    $index + 1,
                    $row['customer'],
                    $row['customer_no'],
                    $row['phone'],
                    $row['loan_no'],
                    $row['loan_amount'],
                    $row['disbursed_date'],
                    $row['branch'],
                    $row['group'],
                    $row['loan_officer'],
                    $row['loan_fees'] ?? 0,
                    $row['penalties'] ?? 0,
                    $row['instalment_in_arrears'] ?? 0,
                    $row['total_balance_in_arrears'] ?? 0,
                    $row['days_in_arrears'],
                    $row['first_overdue_date'],
                    $row['no_of_instalments'] ?? 0,
                    $row['arrears_severity'],
                ];
            }

            $exportData[] = [
                'TOTALS',
                '', '', '', '', '', '', '', '', '',
                $totals['loan_fees'],
                $totals['penalties'],
                $totals['instalment_in_arrears'],
                $totals['total_balance_in_arrears'],
                '', '', '', '',
            ];
        }

        return $exportData;
    }

    public function headings(): array
    {
        return [
            ['LOAN ARREARS REPORT'],
            ['Generated: ' . $this->data['generated_date']],
            ['Branch: ' . $this->data['branch_name']],
            ['Group: ' . $this->data['group_name']],
            ['Loan Officer: ' . $this->data['loan_officer_name']],
            [],
            [
                '#', 'Customer', 'Customer No', 'Phone', 'Loan No', 'Loan Amount', 'Disbursed Date',
                'Branch', 'Group', 'Loan Officer', 'Loan Fees', 'Penalties', 'Instalment in Arrears',
                'Total Balance in Arrears', 'Days in Arrears', 'First Overdue Date', 'No of Instalments', 'Severity',
            ],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            7 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 5, 'B' => 22, 'K' => 12, 'L' => 12, 'M' => 16, 'N' => 18];
    }

    public function title(): string
    {
        return 'Loan Arrears Report';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->mergeCells('A1:R1');
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle('A7:R' . $highestRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                if ($highestRow > 7) {
                    $sheet->getStyle('A' . $highestRow . ':R' . $highestRow)->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF343A40']],
                    ]);
                }
                foreach (['K7', 'L7', 'M7', 'N7'] as $cell) {
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB(match ($cell) {
                            'K7' => 'FFFFA500',
                            'L7' => 'FF8B4513',
                            'M7' => 'FF808000',
                            'N7' => 'FFFF0000',
                            default => 'FF4472C4',
                        });
                }
            },
        ];
    }
}
