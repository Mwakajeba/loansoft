@php
    $prefix = $prefix ?? 'dcb_repay';
    $customerPhone = $customerPhone ?? '';
    $dcbEnabled = filter_var(config('services.dcb.enabled'), FILTER_VALIDATE_BOOLEAN);
    $dcbConfigured = !empty(config('services.dcb.business_id'))
        && !empty(config('services.dcb.api_key'))
        && !empty(config('services.dcb.api_secret'));
@endphp

@if($dcbEnabled)
<div class="dcb-collect-fields border rounded p-3 bg-light mb-3" data-dcb-prefix="{{ $prefix }}">
    <h6 class="text-info mb-3"><i class="bx bx-mobile me-1"></i> DCB Collect (USSD / Push)</h6>
    <p class="small text-muted mb-3">
        Customer receives a payment request on their phone. Funds are credited to your account configured on the DCB gateway.
    </p>

    @unless($dcbConfigured)
        <div class="alert alert-warning py-2 mb-3">
            DCB credentials missing. Configure under <a href="{{ route('settings.dcb') }}">Settings → DCB Payment Gateway</a>.
        </div>
    @endunless

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Customer MSISDN <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="{{ $prefix }}_msisdn" id="{{ $prefix }}_msisdn"
                value="{{ $customerPhone }}" placeholder="0712345678" maxlength="20" required>
            <small class="text-muted">Normalized to 255… by the gateway.</small>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Control No (optional)</label>
            <input type="text" class="form-control" name="{{ $prefix }}_control_no" id="{{ $prefix }}_control_no"
                value="{{ $customerPhone }}" placeholder="Defaults to MSISDN if empty" maxlength="64">
        </div>
    </div>
</div>
@endif
