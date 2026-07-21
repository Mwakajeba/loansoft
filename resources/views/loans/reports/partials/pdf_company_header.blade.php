@php
    $logoBase64 = \App\Support\Pdf\PdfLogo::dataUri($company ?? null);
@endphp

<table class="pdf-header-table">
    <tr>
        <td class="pdf-logo-cell">
            @if($logoBase64)
                <img src="{{ $logoBase64 }}" alt="{{ ($company->name ?? config('app.name')) . ' logo' }}" class="pdf-company-logo">
            @endif
        </td>
        <td width="24%"></td>
        <td class="pdf-company-info-cell">
            <div class="pdf-company-name">{{ $company->name ?? config('app.name') }}</div>
            <div class="pdf-company-details">
                @if($company && $company->address)
                    P.O Box: {{ $company->address }}<br>
                @endif
                @if($company && $company->phone)
                    Phone: {{ $company->phone }}<br>
                @endif
                @if($company && $company->email)
                    Email: {{ $company->email }}
                @endif
            </div>
        </td>
    </tr>
</table>

@if(!empty($reportTitle))
    <div class="pdf-report-title">{{ $reportTitle }}</div>
    <hr class="pdf-title-rule">
@endif

@if(!empty($reportInfo))
    <div class="pdf-report-info">{!! $reportInfo !!}</div>
@endif
