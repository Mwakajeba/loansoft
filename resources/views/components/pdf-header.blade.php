@props(['company', 'title' => 'Report', 'subtitle' => null])

<div class="header" style="text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 15px;">
    @php
        $logoPath = null;
        // Try company logo from storage first
        if (isset($company) && $company && !empty($company->logo)) {
            $storagePath = public_path('storage/' . $company->logo);
            if (file_exists($storagePath)) {
                $logoPath = $storagePath;
            }
        }
        // Fallback to default logo
        if (!$logoPath) {
            $defaultLogos = [
                public_path('assets/images/logo-img.png'),
                public_path('assets/images/logo1.png'),
                public_path('assets/images/logo2.png'),
            ];
            foreach ($defaultLogos as $defaultLogo) {
                if (file_exists($defaultLogo)) {
                    $logoPath = $defaultLogo;
                    break;
                }
            }
        }
    @endphp

    @if($logoPath)
        <div style="margin-bottom: 10px;">
            <img src="{{ $logoPath }}" alt="Company Logo" style="max-height: 70px; max-width: 200px;">
        </div>
    @endif

    <div class="company-details">
        <div style="font-size: 20px; font-weight: bold; color: #333; margin: 5px 0;">
            {{ $company->name ?? config('app.name', 'SmartFinance') }}
        </div>
        @if(isset($company) && $company)
            @if($company->address)
                <div style="font-size: 11px; color: #666; margin: 2px 0;">{{ $company->address }}</div>
            @endif
            <div style="font-size: 11px; color: #666; margin: 2px 0;">
                @if($company->phone)
                    Phone: {{ $company->phone }}
                @endif
                @if($company->phone && $company->email) | @endif
                @if($company->email)
                    Email: {{ $company->email }}
                @endif
            </div>
            @if($company->tin)
                <div style="font-size: 11px; color: #666; margin: 2px 0;">TIN: {{ $company->tin }}</div>
            @endif
        @endif
    </div>

    <h1 style="font-size: 18px; font-weight: bold; margin: 15px 0 5px 0; color: #333;">{{ $title }}</h1>
    @if($subtitle)
        <p style="margin: 0; font-size: 11px; color: #666;">{{ $subtitle }}</p>
    @endif
</div>
