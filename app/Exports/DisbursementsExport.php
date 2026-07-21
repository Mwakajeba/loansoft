<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DisbursementsExport implements FromView, ShouldAutoSize, WithStyles, WithEvents
{
    public function __construct(
        protected $disbursements,
        protected string $startDate,
        protected string $endDate,
        protected $branch,
        protected $company
    ) {}

    public function view(): View
    {
        return view('loans.reports.disbursed_excel', [
            'disbursements' => $this->disbursements,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'branch' => $this->branch,
            'company' => $this->company,
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                'alignment' => ['horizontal' => 'center'],
            ],
            2 => ['font' => ['bold' => true, 'size' => 12]],
            5 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '000000']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                $sheet->freezePane('A6');
                $sheet->setAutoFilter('A5:N5');
                $sheet->getStyle("A5:N{$highestRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
                $sheet->getStyle("K6:M{$highestRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
                $sheet->getStyle("A{$highestRow}:N{$highestRow}")->getFont()->setBold(true);
            },
        ];
    }
}
