<?php

namespace App\Exports;

use App\Models\Customer;
use App\Models\LoanProduct;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OpeningBalanceTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    /** Same keys as loans/create form */
    public const INTEREST_CYCLES = [
        'daily',
        'weekly',
        'bimonthly',
        'monthly',
        'quarterly',
        'semi_annually',
        'annually',
    ];

    public const SECTORS = [
        'Agriculture',
        'Business',
        'Education',
        'Health',
        'Other',
    ];

    protected ?int $productId;

    public function __construct(?int $productId = null)
    {
        $this->productId = $productId;
    }

    public function array(): array
    {
        // Default value is independent of loan product; user can select per row.
        $defaultCycle = 'monthly';

        $branchId = auth()->user()->branch_id ?? null;
        $customers = Customer::with('groups')
            ->where('category', 'Borrower')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'customerNo']);

        $rows = [];
        foreach ($customers as $customer) {
            $group = $customer->groups->first();
            $rows[] = [
                $customer->customerNo,
                $customer->name,
                $group ? $group->id : '',
                $group ? $group->name : '',
                '', // amount
                '', // interest
                '', // period
                date('Y-m-d'), // date_applied
                '', // first_repayment_date
                $defaultCycle, // interest_cycle (dropdown)
                'Business', // sector (dropdown)
                '', // amount_paid
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'customer_no',
            'customer_name',
            'group_id',
            'group_name',
            'amount',
            'interest',
            'period',
            'date_applied',
            'first_repayment_date',
            'interest_cycle',
            'sector',
            'amount_paid',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14,
            'B' => 28,
            'C' => 10,
            'D' => 22,
            'E' => 14,
            'F' => 10,
            'G' => 8,
            'H' => 14,
            'I' => 18,
            'J' => 16,
            'K' => 14,
            'L' => 14,
        ];
    }

    public function title(): string
    {
        return 'Opening Balance';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();

                $helperSheet = $spreadsheet->createSheet();
                $helperSheet->setTitle('Lists');
                $helperSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                $helperSheet->setCellValue('A1', 'Interest Cycles');
                foreach (self::INTEREST_CYCLES as $index => $cycle) {
                    $helperSheet->setCellValue('A'.($index + 2), $cycle);
                }

                $helperSheet->setCellValue('B1', 'Sectors');
                foreach (self::SECTORS as $index => $sector) {
                    $helperSheet->setCellValue('B'.($index + 2), $sector);
                }

                $highestRow = max(2, (int) $sheet->getHighestRow());
                $dataEndRow = min($highestRow, 5002);

                $cycleValidation = new DataValidation();
                $cycleValidation->setType(DataValidation::TYPE_LIST);
                $cycleValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $cycleValidation->setAllowBlank(true);
                $cycleValidation->setShowInputMessage(true);
                $cycleValidation->setShowErrorMessage(true);
                $cycleValidation->setShowDropDown(true);
                $cycleValidation->setPromptTitle('Interest cycle');
                $cycleValidation->setPrompt('Select the same options as on Create Loan (daily, weekly, monthly, etc.)');
                $cycleValidation->setFormula1('Lists!$A$2:$A$'.(count(self::INTEREST_CYCLES) + 1));

                $sectorValidation = new DataValidation();
                $sectorValidation->setType(DataValidation::TYPE_LIST);
                $sectorValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $sectorValidation->setAllowBlank(true);
                $sectorValidation->setShowInputMessage(true);
                $sectorValidation->setShowErrorMessage(true);
                $sectorValidation->setShowDropDown(true);
                $sectorValidation->setFormula1('Lists!$B$2:$B$'.(count(self::SECTORS) + 1));

                for ($row = 2; $row <= $dataEndRow; $row++) {
                    $sheet->getCell('J'.$row)->setDataValidation(clone $cycleValidation);
                    $sheet->getCell('K'.$row)->setDataValidation(clone $sectorValidation);
                }

                $sheet->getStyle('A1:L1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}
