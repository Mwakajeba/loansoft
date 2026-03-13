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

class CustomerBulkUploadFailedExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
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
                $record['row_number'] ?? '',
                $record['name'] ?? '',
                $record['phone1'] ?? '',
                $record['phone2'] ?? '',
                $record['dob'] ?? '',
                $record['sex'] ?? '',
                $record['region_id'] ?? '',
                $record['district_id'] ?? '',
                $record['work'] ?? '',
                $record['workaddress'] ?? '',
                $record['idtype'] ?? '',
                $record['idnumber'] ?? '',
                $record['relation'] ?? '',
                $record['description'] ?? '',
                $record['error_reason'] ?? 'Unknown error',
            ];
        }

        return $exportData;
    }

    public function headings(): array
    {
        return [
            ['FAILED CUSTOMER UPLOAD RECORDS'],
            ['Generated: ' . now()->format('Y-m-d H:i:s')],
            ['Total Failed Records: ' . count($this->failedRecords)],
            [],
            [
                'Row Number',
                'Name',
                'Phone1',
                'Phone2',
                'Date of Birth',
                'Sex',
                'Region ID',
                'District ID',
                'Work',
                'Work Address',
                'ID Type',
                'ID Number',
                'Relation',
                'Description',
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
            'B' => 25,  // Name
            'C' => 15,  // Phone1
            'D' => 15,  // Phone2
            'E' => 15,  // Date of Birth
            'F' => 10,  // Sex
            'G' => 15,  // Region ID
            'H' => 15,  // District ID
            'I' => 20,  // Work
            'J' => 30,  // Work Address
            'K' => 15,  // ID Type
            'L' => 15,  // ID Number
            'M' => 15,  // Relation
            'N' => 30,  // Description
            'O' => 50,  // Error Reason
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
                $sheet->mergeCells('A1:O1');
                
                // Add borders to data
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                
                if ($highestRow > 5) {
                    $sheet->getStyle('A5:' . $highestColumn . $highestRow)
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);
                    
                    // Center align specific columns
                    $sheet->getStyle('A6:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('F6:F' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    
                    // Wrap text for error reason column
                    $sheet->getStyle('O6:O' . $highestRow)->getAlignment()->setWrapText(true);
                }
            },
        ];
    }
}
