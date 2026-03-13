<?php

namespace App\Exports;

use App\Models\ActivityLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ActivityLogsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected Collection $logs;

    public function __construct(Collection $logs)
    {
        $this->logs = $logs;
    }

    public function collection(): Collection
    {
        return $this->logs;
    }

    public function map($log): array
    {
        $activityTime = $log->activity_time
            ? $log->activity_time->format('Y-m-d H:i:s')
            : ($log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '');
        return [
            $activityTime,
            $log->user->name ?? 'Guest',
            $log->model ?? '',
            ucfirst(str_replace('_', ' ', $log->action ?? '')),
            $log->description ?? '',
            $log->changes_summary ?? '',
            $log->ip_address ?? '',
            $log->device ?? '',
        ];
    }

    public function headings(): array
    {
        return [
            'Date',
            'User',
            'Model',
            'Action',
            'Description',
            'Changes (before → after)',
            'IP Address',
            'Device',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
