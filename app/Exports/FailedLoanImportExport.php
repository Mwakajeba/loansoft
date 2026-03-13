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

class FailedLoanImportExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    protected $failedRecords;

    public function __construct(array $failedRecords)
    {
        $this->failedRecords = $failedRecords;
    }

    public function array(): array
    {
        $exportData = [];
        
        foreach ($this->failedRecords as $record) {
            $exportData[] = [
                $record['row_number'],
                $record['customer_no'] ?? '',
                $record['customer_name'] ?? '',
                $record['amount'] ?? '',
                $record['period'] ?? '',
                $record['interest'] ?? '',
                $record['date_applied'] ?? '',
                $record['interest_cycle'] ?? '',
                $record['loan_officer'] ?? '',
                $record['group_id'] ?? '',
                $record['sector'] ?? '',
                $record['error_reason'] ?? 'Unknown error',
            ];
        }

        return $exportData;
    }

    public function headings(): array
    {
        return [
            ['FAILED LOAN IMPORT RECORDS'],
            ['Generated: ' . now()->format('Y-m-d H:i:s')],
            ['Total Failed Records: ' . count($this->failedRecords)],
            [],
            [
                'Row Number',
                'Customer No',
                'Customer Name',
                'Amount',
                'Period',
                'Interest',
                'Date Applied',
                'Interest Cycle',
                'Loan Officer',
                'Group ID',
                'Sector',
                'Error Reason'
            ]
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Title row
            1 => [
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            
            // Info rows
            2 => ['font' => ['bold' => true]],
            3 => ['font' => ['bold' => true]],
            
            // Header row
            5 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFFF0000']
                ],
                'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,  // Row Number
            'B' => 15,  // Customer No
            'C' => 25,  // Customer Name
            'D' => 15,  // Amount
            'E' => 10,  // Period
            'F' => 12,  // Interest
            'G' => 15,  // Date Applied
            'H' => 15,  // Interest Cycle
            'I' => 20,  // Loan Officer
            'J' => 12,  // Group ID
            'K' => 15,  // Sector
            'L' => 50,  // Error Reason
        ];
    }

    public function title(): string
    {
        return 'Failed Records';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                // Merge title cells
                $sheet->mergeCells('A1:L1');
                
                // Add borders to data
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                
                if ($highestRow > 5) {
                    $sheet->getStyle('A5:' . $highestColumn . $highestRow)
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);
                    
                    // Format amount column
                    $sheet->getStyle('D6:D' . $highestRow)->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle('F6:F' . $highestRow)->getNumberFormat()->setFormatCode('#,##0.00');
                    
                    // Center align specific columns
                    $sheet->getStyle('A6:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('E6:E' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('G6:G' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    
                    // Right align amount columns
                    $sheet->getStyle('D6:D' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle('F6:F' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    
                    // Wrap text for error reason column
                    $sheet->getStyle('L6:L' . $highestRow)->getAlignment()->setWrapText(true);
                }
            },
        ];
    }
}
