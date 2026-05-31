<?php

namespace App\Exports;

use App\Support\Loans\RepaymentReportBuilder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;


class RepaymentExport implements FromCollection, WithHeadings
{
    protected $monthlyGroups;
    protected $summary;

    public function __construct($monthlyGroups, array $summary)
    {
        $this->monthlyGroups = $monthlyGroups;
        $this->summary = $summary;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return collect(RepaymentReportBuilder::exportRows($this->monthlyGroups, $this->summary));
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return RepaymentReportBuilder::exportHeadings();
    }
}
