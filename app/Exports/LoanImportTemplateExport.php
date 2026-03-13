<?php

namespace App\Exports;

use App\Models\Customer;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LoanImportTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    public function array(): array
    {
        $data = [];
        $branchId = auth()->user()->branch_id ?? null;
        $customers = Customer::with(['groups:id'])
            ->where('category', 'Borrower')
            ->when($branchId, function($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->limit(50)
            ->get(['id', 'name', 'customerNo', 'branch_id']);

        // Generate sample loan data
        $sectors = ['Agriculture', 'Business', 'Trade', 'Services', 'Manufacturing', 'Education', 'Health', 'Transport'];
        $interestCycles = ['monthly', 'weekly', 'daily', 'quarterly', 'yearly'];
        
        foreach ($customers as $index => $customer) {
            $groupId = optional($customer->groups->first())->id ?? '';
            $data[] = [
                $customer->name,
                $customer->customerNo,
                rand(100000, 5000000), // amount
                rand(3, 24), // period
                rand(5, 15) + (rand(0, 9) / 10), // interest (5.0 to 15.9)
                Carbon::now()->subDays(rand(0, 30))->format('Y-m-d'), // date_applied
                $interestCycles[array_rand($interestCycles)], // interest_cycle
                '', // loan_officer_id (will be filled by user)
                $groupId,
                $sectors[array_rand($sectors)], // sector
            ];
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'customer_name',
            'customer_no',
            'amount',
            'period',
            'interest',
            'date_applied',
            'interest_cycle',
            'loan_officer_id',
            'group_id',
            'sector',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4']
                ],
                'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 25,  // customer_name
            'B' => 15,  // customer_no
            'C' => 15,  // amount
            'D' => 10,  // period
            'E' => 12,  // interest
            'F' => 15,  // date_applied
            'G' => 15,  // interest_cycle
            'H' => 15,  // loan_officer_id
            'I' => 12,  // group_id
            'J' => 20,  // sector
        ];
    }

    public function title(): string
    {
        return 'Loans';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();
                
                // Create helper sheet for dropdown data (hidden)
                $helperSheet = $spreadsheet->createSheet();
                $helperSheet->setTitle('Data');
                $helperSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
                
                // Write interest cycles to helper sheet (Column A)
                $interestCycles = ['monthly', 'weekly', 'daily', 'quarterly', 'yearly'];
                $helperSheet->setCellValue('A1', 'Interest Cycles');
                foreach ($interestCycles as $index => $cycle) {
                    $helperSheet->setCellValue('A' . ($index + 2), $cycle);
                }
                
                // Write sectors to helper sheet (Column B)
                $sectors = ['Agriculture', 'Business', 'Trade', 'Services', 'Manufacturing', 'Education', 'Health', 'Transport', 'Construction', 'Tourism'];
                $helperSheet->setCellValue('B1', 'Sectors');
                foreach ($sectors as $index => $sector) {
                    $helperSheet->setCellValue('B' . ($index + 2), $sector);
                }
                
                // Headers are at row 1 (from WithHeadings), data starts at row 2
                // Insert instruction rows AFTER headers (rows 2-5)
                $sheet->insertNewRowBefore(2, 4);
                
                // Instructions (rows 2-5, header stays at row 1)
                $sheet->setCellValue('A2', 'LOAN IMPORT TEMPLATE');
                $sheet->mergeCells('A2:J2');
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A2')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF4472C4');
                $sheet->getStyle('A2')->getFont()->getColor()->setARGB('FFFFFFFF');
                
                $sheet->setCellValue('A3', 'Instructions:');
                $sheet->getStyle('A3')->getFont()->setBold(true);
                $sheet->setCellValue('A4', '1. Fill in all required fields (customer_no, amount, period, interest, date_applied, interest_cycle, loan_officer_id, group_id, sector)');
                $sheet->setCellValue('A5', '2. Use dropdowns for Interest Cycle (Column G) and Sector (Column J)');
                $sheet->setCellValue('A6', '3. IMPORTANT: Keep the header row (row 1) - DO NOT DELETE IT. You can delete instruction rows (2-6) and sample data.');
                $sheet->mergeCells('A4:J4');
                $sheet->mergeCells('A5:J5');
                $sheet->mergeCells('A6:J6');
                
                // Header row is at row 1, data starts at row 7 (after 1 header + 5 instruction rows)
                $dataStartRow = 7;
                $dataEndRow = 56; // 50 sample loans + header row
                
                // Add interest cycle dropdown (Column G)
                $interestCycleValidation = new DataValidation();
                $interestCycleValidation->setType(DataValidation::TYPE_LIST);
                $interestCycleValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $interestCycleValidation->setAllowBlank(false);
                $interestCycleValidation->setShowInputMessage(true);
                $interestCycleValidation->setShowErrorMessage(true);
                $interestCycleValidation->setShowDropDown(true);
                $interestCycleValidation->setFormula1('Data!$A$2:$A$' . (count($interestCycles) + 1));
                
                // Add sector dropdown (Column J) using helper sheet
                $sectorValidation = new DataValidation();
                $sectorValidation->setType(DataValidation::TYPE_LIST);
                $sectorValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $sectorValidation->setAllowBlank(false);
                $sectorValidation->setShowInputMessage(true);
                $sectorValidation->setShowErrorMessage(true);
                $sectorValidation->setShowDropDown(true);
                $sectorValidation->setFormula1('Data!$B$2:$B$' . (count($sectors) + 1));
                
                // Apply validations to all data rows
                for ($row = $dataStartRow; $row <= $dataEndRow; $row++) {
                    // Interest cycle dropdown (Column G)
                    $sheet->getCell('G' . $row)->setDataValidation(clone $interestCycleValidation);
                    // Sector dropdown (Column J)
                    $sheet->getCell('J' . $row)->setDataValidation(clone $sectorValidation);
                }
                
                // Add borders to header row
                $sheet->getStyle('A1:J1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
