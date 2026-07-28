<?php

namespace App\Exports;

use App\Models\Customer;
use App\Models\Fee;
use App\Models\LoanProduct;
use App\Support\Loans\OpeningBalanceReleaseFeeResolver;
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

    /** @var \Illuminate\Support\Collection<int, Fee> */
    protected $releaseFees;

    public function __construct(?int $productId = null)
    {
        $this->productId = $productId;
        $this->releaseFees = collect();

        if ($productId) {
            $product = LoanProduct::find($productId);
            if ($product) {
                // All active product fees become Excel columns (so the template always shows them).
                $this->releaseFees = OpeningBalanceReleaseFeeResolver::productFeesForTemplate($product);
            }
        }
    }

    public function array(): array
    {
        $defaultCycle = 'monthly';

        $branchId = auth()->user()->branch_id ?? null;
        $customers = Customer::with('groups')
            ->where('category', 'Borrower')
            ->whereDoesntHave('loans')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'customerNo']);

        $rows = [];
        foreach ($customers as $customer) {
            $group = $customer->groups->first();
            $row = [
                $customer->customerNo,
                $customer->name,
                $group ? $group->id : '',
                $group ? $group->name : '',
                '', // amount
                '', // interest
                '', // period
                date('Y-m-d'), // date_applied
                '', // first_repayment_date
                $defaultCycle, // interest_cycle
                'Business', // sector
                '', // amount_paid
            ];

            foreach ($this->releaseFees as $fee) {
                $row[] = 0;
            }

            $rows[] = $row;
        }

        // Always include at least one blank sample row so fee columns are visible.
        if (empty($rows)) {
            $row = [
                '', '', '', '', '', '', '', date('Y-m-d'), '', $defaultCycle, 'Business', '',
            ];
            foreach ($this->releaseFees as $fee) {
                $row[] = 0;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function headings(): array
    {
        $headings = [
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

        foreach ($this->releaseFees as $fee) {
            // fee_12_processing_fee — readable, still parseable by fee id prefix
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $fee->name) ?? 'fee');
            $slug = trim($slug, '_');
            $headings[] = OpeningBalanceReleaseFeeResolver::feeColumnKey((int) $fee->id).($slug !== '' ? '_'.$slug : '');
        }

        return $headings;
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
        $widths = [
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

        $colIndex = 13; // column M
        foreach ($this->releaseFees as $fee) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $widths[$letter] = 16;
            $colIndex++;
        }

        return $widths;
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

                $feeGuide = $spreadsheet->createSheet();
                $feeGuide->setTitle('Fees Guide');
                $feeGuide->setCellValue('A1', 'Column');
                $feeGuide->setCellValue('B1', 'Fee Name');
                $feeGuide->setCellValue('C1', 'Fee Type');
                $feeGuide->setCellValue('D1', 'Deduction Criteria');
                $feeGuide->setCellValue('E1', 'Settings Amount');
                $feeGuide->setCellValue('F1', 'How Excel Value Is Used');
                $feeGuide->getStyle('A1:F1')->getFont()->setBold(true);

                $guideRow = 2;
                foreach ($this->releaseFees as $fee) {
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', (string) $fee->name) ?? 'fee');
                    $slug = trim($slug, '_');
                    $colKey = OpeningBalanceReleaseFeeResolver::feeColumnKey((int) $fee->id).($slug !== '' ? '_'.$slug : '');
                    $feeGuide->setCellValue('A'.$guideRow, $colKey);
                    $feeGuide->setCellValue('B'.$guideRow, $fee->name);
                    $feeGuide->setCellValue('C'.$guideRow, $fee->fee_type);
                    $feeGuide->setCellValue('D'.$guideRow, $fee->deduction_criteria);
                    $feeGuide->setCellValue('E'.$guideRow, $fee->amount);
                    $feeGuide->setCellValue(
                        'F'.$guideRow,
                        $fee->isCustom()
                            ? 'Fill amount in Excel (required when deducting fees). 0 = no fee.'
                            : 'Leave 0 to use fee settings (fixed amount or % of loan). Enter amount > 0 to override.'
                    );
                    $guideRow++;
                }

                if ($this->releaseFees->isEmpty()) {
                    $feeGuide->setCellValue('A2', 'No active fees on this product. Attach fees to the loan product, then re-download the template.');
                }

                foreach (range('A', 'F') as $col) {
                    $feeGuide->getColumnDimension($col)->setAutoSize(true);
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

                $lastColIndex = 12 + $this->releaseFees->count();
                $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex);
                $sheet->getStyle('A1:'.$lastCol.'1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

                // Highlight fee columns
                if ($this->releaseFees->isNotEmpty()) {
                    $feeStart = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(13);
                    $sheet->getStyle($feeStart.'1:'.$lastCol.'1')->getFill()->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FF70AD47');
                }
            },
        ];
    }
}
