{{--
    Standard PDF report header opener.
    Required: $company, $reportTitle
    Optional: $reportInfo (HTML string), $pageSize (default A3 landscape)
--}}
@php
    $pageSize = $pageSize ?? 'A3 landscape';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle }}</title>
    <style>
        @page { size: {{ $pageSize }}; margin: 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @include('loans.reports.partials.pdf_page_shell_styles')
        @include('loans.reports.partials.pdf_company_header_styles')
        @include('loans.reports.partials.pdf_report_table_styles')
        {!! $extraStyles ?? '' !!}
    </style>
</head>
<body>
    <div class="pdf-page-container">
        @include('loans.reports.partials.pdf_company_header', [
            'company' => $company ?? null,
            'reportTitle' => $reportTitle,
            'reportInfo' => $reportInfo ?? null,
        ])
