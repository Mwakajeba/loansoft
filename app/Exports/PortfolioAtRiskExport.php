<?php

namespace App\Exports;

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PortfolioAtRiskExport implements FromView, WithTitle, ShouldAutoSize, WithStyles
{
    /** @var array<int, array<string, mixed>> */
    protected $par_data;

    /** @var array<string, mixed> */
    protected $filters;

    protected $company;

    /**
     * @param  array{par_data?: array, as_of_date?: string, par_days?: int, branch_name?: string, group_name?: string, loan_officer_name?: string, company?: mixed}  $payload
     */
    public function __construct(array $payload)
    {
        $this->par_data = $payload['par_data'] ?? [];
        $this->filters = [
            'as_of_date' => $payload['as_of_date'] ?? now()->format('Y-m-d'),
            'par_days' => $payload['par_days'] ?? 30,
            'branch_name' => $payload['branch_name'] ?? 'All Branches',
            'group_name' => $payload['group_name'] ?? 'All Groups',
            'loan_officer_name' => $payload['loan_officer_name'] ?? 'All Officers',
        ];
        $this->company = $payload['company'] ?? Company::first();
    }

    public function view(): View
    {
        $total_outstanding = array_sum(array_column($this->par_data, 'total_outstanding'));
        $total_at_risk = array_sum(array_column($this->par_data, 'at_risk_amount'));
        $par_ratio = $total_outstanding > 0 ? ($total_at_risk / $total_outstanding) * 100 : 0;
        $loans_at_risk = count(array_filter($this->par_data, function ($item) {
            return !empty($item['is_at_risk']);
        }));

        $par_categories = ['Current' => 0, 'PAR1' => 0, 'PAR30' => 0, 'PAR90' => 0];
        foreach ($this->par_data as $loan) {
            $c = $loan['par_category'] ?? 'Current';
            if (isset($par_categories[$c])) {
                $par_categories[$c]++;
            }
        }

        return view('loans.reports.portfolio_at_risk_excel', [
            'par_data' => $this->par_data,
            'filters' => $this->filters,
            'company' => $this->company,
            'generated_date' => now()->format('d-m-Y H:i:s'),
            'as_of_date' => $this->filters['as_of_date'] ?? now()->format('Y-m-d'),
            'par_days' => $this->filters['par_days'] ?? 30,
            'branch_name' => $this->filters['branch_name'] ?? 'All Branches',
            'group_name' => $this->filters['group_name'] ?? 'All Groups',
            'loan_officer_name' => $this->filters['loan_officer_name'] ?? 'All Officers',
            'total_outstanding' => $total_outstanding,
            'total_at_risk' => $total_at_risk,
            'par_ratio' => $par_ratio,
            'loans_at_risk' => $loans_at_risk,
            'total_loans' => count($this->par_data),
            'par_categories' => $par_categories,
        ]);
    }

    public function title(): string
    {
        $par_days = $this->filters['par_days'] ?? 30;

        return "PAR {$par_days} Report";
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        $sheet->getStyle('A1:' . $highestColumn . '8')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
                'color' => ['rgb' => 'fd7e14'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'f8f9fa'],
            ],
        ]);

        $sheet->getStyle('A10:D16')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e9ecef'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        $sheet->getStyle('A18:' . $highestColumn . '18')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'ffffff'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '495057'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        if ($highestRow > 18) {
            $sheet->getStyle('A19:' . $highestColumn . $highestRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);

            $sheet->getStyle('A' . $highestRow . ':' . $highestColumn . $highestRow)->applyFromArray([
                'font' => [
                    'bold' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'f8f9fa'],
                ],
            ]);
        }

        foreach (range('A', $highestColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        for ($row = 1; $row <= $highestRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(20);
        }

        $sheet->freezePane('A19');

        return [];
    }
}
