<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GroupRepaymentTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths, WithEvents, ShouldAutoSize
{
    /** @var array<int, array<int, mixed>> */
    protected array $rows;

    protected string $groupName;

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(array $rows, string $groupName = 'Group')
    {
        $this->rows = $rows;
        $this->groupName = $groupName;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'customer_no',
            'customer_name',
            'loan_no',
            'customer_id',
            'loan_id',
            'schedule_id',
            'due_date',
            'installment_amount',
            'fee',
            'penalty',
            'already_paid',
            'total_due',
            'amount_to_pay',
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
            'C' => 14,
            'D' => 12,
            'E' => 10,
            'F' => 12,
            'G' => 14,
            'H' => 16,
            'I' => 10,
            'J' => 10,
            'K' => 14,
            'L' => 12,
            'M' => 16,
        ];
    }

    public function title(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9 _-]+/', '', $this->groupName) ?: 'Group';

        return substr($safe, 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = max(2, (int) $sheet->getHighestRow());

                $sheet->insertNewRowBefore(1, 2);
                $sheet->setCellValue('A1', 'Group Repayment Import Template');
                $sheet->setCellValue('A2', 'Edit amount_to_pay only. Do not change customer_id, loan_id, or schedule_id.');
                $sheet->mergeCells('A1:M1');
                $sheet->mergeCells('A2:M2');
                $sheet->getStyle('A1:A2')->getFont()->setBold(true);
                $sheet->getStyle('A2')->getFont()->setItalic(true);

                $headerRow = 3;
                $sheet->getStyle('A'.$headerRow.':M'.$headerRow)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF4472C4'],
                    ],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                ]);

                if ($highestRow + 2 >= 4) {
                    $sheet->getStyle('A4:M'.($highestRow + 2))->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle('M4:M'.($highestRow + 2))->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFF2CC');
                }
            },
        ];
    }
}
