<?php

namespace App\Exports;

use Illuminate\Support\Collection;
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

class PaymentVoucherTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents
{
    public const HEADINGS = [
        'voucher_group',
        'date',
        'reference',
        'bank_account',
        'payee_type',
        'payee',
        'description',
        'chart_account',
        'line_description',
        'amount',
    ];

    public const PAYEE_TYPES = ['customer', 'supplier', 'other'];

    /** @var Collection<int, string> */
    protected Collection $chartAccountLabels;

    /** @var Collection<int, string> */
    protected Collection $bankAccountLabels;

    public function __construct(Collection $chartAccountLabels, Collection $bankAccountLabels)
    {
        $this->chartAccountLabels = $chartAccountLabels->values();
        $this->bankAccountLabels = $bankAccountLabels->values();
    }

    public static function chartAccountLabel($account): string
    {
        return trim(($account->account_code ?? '') . ' - ' . ($account->account_name ?? ''));
    }

    public static function bankAccountLabel($bankAccount): string
    {
        return trim(($bankAccount->name ?? '') . ' - ' . ($bankAccount->account_number ?? ''));
    }

    public function array(): array
    {
        $today = date('Y-m-d');
        $sampleBank = $this->bankAccountLabels->first() ?? '';
        $sampleAccount = $this->chartAccountLabels->first() ?? '';

        $rows = [
            [
                'PV1',
                $today,
                '',
                $sampleBank,
                'other',
                'Sample Payee',
                'Sample payment voucher (delete this row)',
                $sampleAccount,
                'Line 1',
                '',
            ],
        ];

        for ($i = 0; $i < 19; $i++) {
            $rows[] = array_fill(0, count(self::HEADINGS), '');
        }

        return $rows;
    }

    public function headings(): array
    {
        return self::HEADINGS;
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
            'A' => 16,
            'B' => 14,
            'C' => 16,
            'D' => 36,
            'E' => 14,
            'F' => 28,
            'G' => 32,
            'H' => 42,
            'I' => 28,
            'J' => 14,
        ];
    }

    public function title(): string
    {
        return 'Payment Vouchers';
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

                $helperSheet->setCellValue('A1', 'Chart Accounts');
                foreach ($this->chartAccountLabels as $index => $label) {
                    $helperSheet->setCellValue('A' . ($index + 2), $label);
                }

                $helperSheet->setCellValue('B1', 'Bank Accounts');
                foreach ($this->bankAccountLabels as $index => $label) {
                    $helperSheet->setCellValue('B' . ($index + 2), $label);
                }

                $helperSheet->setCellValue('C1', 'Payee Types');
                foreach (self::PAYEE_TYPES as $index => $type) {
                    $helperSheet->setCellValue('C' . ($index + 2), $type);
                }

                $guide = $spreadsheet->createSheet();
                $guide->setTitle('Instructions');
                $guide->setCellValue('A1', 'Payment voucher Excel import');
                $guide->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $guide->setCellValue('A3', '1. Use the Payment Vouchers sheet. Keep the header row.');
                $guide->setCellValue('A4', '2. Chart Account (column H) and Bank Account (column D) are dropdowns from your company lists.');
                $guide->setCellValue('A5', '3. Rows with the same voucher_group become one voucher with multiple line items. Leave voucher_group blank for one voucher per row.');
                $guide->setCellValue('A6', '4. payee_type must be customer, supplier, or other. For customer use customer number or name; for supplier use supplier name; for other type the payee name.');
                $guide->setCellValue('A7', '5. Delete the sample row before uploading. Amount is required on every line.');
                $guide->getColumnDimension('A')->setWidth(120);

                $chartCount = max(1, $this->chartAccountLabels->count());
                $bankCount = max(1, $this->bankAccountLabels->count());

                $chartValidation = $this->listValidation('Lists!$A$2:$A$' . ($chartCount + 1), 'Select a chart of accounts entry');
                $bankValidation = $this->listValidation('Lists!$B$2:$B$' . ($bankCount + 1), 'Select a bank account');
                $payeeTypeValidation = $this->listValidation('Lists!$C$2:$C$' . (count(self::PAYEE_TYPES) + 1), 'Select customer, supplier, or other');

                $dataEndRow = 500;
                for ($row = 2; $row <= $dataEndRow; $row++) {
                    $sheet->getCell('D' . $row)->setDataValidation(clone $bankValidation);
                    $sheet->getCell('E' . $row)->setDataValidation(clone $payeeTypeValidation);
                    $sheet->getCell('H' . $row)->setDataValidation(clone $chartValidation);
                }

                $sheet->getStyle('A1:J1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }

    private function listValidation(string $formula, string $prompt): DataValidation
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setPromptTitle('Select');
        $validation->setPrompt($prompt);
        $validation->setErrorTitle('Invalid value');
        $validation->setError('Please select a value from the dropdown list.');
        $validation->setFormula1($formula);

        return $validation;
    }
}
