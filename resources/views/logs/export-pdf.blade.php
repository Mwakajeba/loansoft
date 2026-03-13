<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        h1 { font-size: 12px; margin-bottom: 4px; }
        .meta { color: #666; margin-bottom: 10px; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        th, td { border: 1px solid #ddd; padding: 4px 5px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; }
        .wrap { word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
        .changes-cell { word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
        .date { white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Activity Logs Report</h1>
    <div class="meta">
        Generated: {{ now()->format('Y-m-d H:i:s') }}
        @if($company)
            | {{ $company->name ?? '' }}
        @endif
        <br>
        Total records: {{ $logs->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th class="date" style="width: 10%;">Date</th>
                <th style="width: 12%;">User</th>
                <th style="width: 8%;">Model</th>
                <th style="width: 8%;">Action</th>
                <th class="wrap" style="width: 18%;">Description</th>
                <th class="changes-cell" style="width: 28%;">Changes (before → after)</th>
                <th style="width: 8%;">IP Address</th>
                <th class="wrap" style="width: 8%;">Device</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
            <tr>
                <td class="date">{{ $log->activity_time ? $log->activity_time->format('Y-m-d H:i') : ($log->created_at ? $log->created_at->format('Y-m-d H:i') : '') }}</td>
                <td>{{ $log->user->name ?? 'Guest' }}</td>
                <td>{{ $log->model ?? '' }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $log->action ?? '')) }}</td>
                <td class="wrap">{{ $log->description ?? '' }}</td>
                <td class="changes-cell">{{ $log->changes_summary ?? '' }}</td>
                <td>{{ $log->ip_address ?? '' }}</td>
                <td class="wrap">{{ $log->device ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($logs->isEmpty())
        <p style="margin-top: 12px;">No activity logs match the selected filters.</p>
    @endif
</body>
</html>
