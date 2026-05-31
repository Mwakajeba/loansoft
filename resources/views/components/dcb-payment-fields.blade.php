@php
    $prefix = $prefix ?? 'dcb';
    $mode = $mode ?? 'disburse'; // disburse | collect
    $customerPhone = $customerPhone ?? '';
    $customerName = $customerName ?? '';
    $defaultInstitution = config('services.dcb.default_institution_code', '');
    $dcbEnabled = filter_var(config('services.dcb.enabled'), FILTER_VALIDATE_BOOLEAN);
    $dcbConfigured = !empty(config('services.dcb.business_id'))
        && !empty(config('services.dcb.api_key'))
        && !empty(config('services.dcb.api_secret'));
@endphp

@if($dcbEnabled)
<div class="dcb-payment-fields border rounded p-3 bg-light mb-3" data-dcb-prefix="{{ $prefix }}">
    <h6 class="text-info mb-3"><i class="bx bx-transfer me-1"></i> DCB Mobile Money</h6>

    @if($mode === 'collect')
        <p class="small text-muted mb-2">Customer pays from their wallet. Your receiving account is configured on the DCB gateway (not in SmartFinance).</p>
    @endif

    @unless($dcbConfigured)
        <div class="alert alert-warning py-2 mb-3">
            DCB is enabled but API credentials are missing. Set <strong>Business ID</strong>, <strong>API Key</strong>, and <strong>API Secret</strong> under
            <a href="{{ route('settings.dcb') }}">Settings → DCB Payment Gateway</a> (or in <code>.env</code>), then run <code>php artisan config:clear</code>.
        </div>
    @endunless

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Financial Institution <span class="text-danger">*</span></label>
            <select class="form-select dcb-institution" name="{{ $prefix }}_institution_code" id="{{ $prefix }}_institution_code" data-prefix="{{ $prefix }}">
                <option value="">— Load institutions —</option>
                @if($defaultInstitution)
                    <option value="{{ $defaultInstitution }}" selected>{{ $defaultInstitution }} (default)</option>
                @endif
            </select>
            <button type="button" class="btn btn-sm btn-link p-0 dcb-load-institutions" data-prefix="{{ $prefix }}">Refresh FSP list</button>
            <span class="dcb-institutions-status small text-muted ms-1"></span>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">{{ $mode === 'collect' ? 'Customer wallet / MSISDN (payer)' : 'Account / MSISDN' }} <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="text" class="form-control dcb-account" name="{{ $prefix }}_destination_account"
                    id="{{ $prefix }}_destination_account" value="{{ $customerPhone }}"
                    placeholder="0712345678" maxlength="64">
                <button type="button" class="btn btn-outline-primary dcb-lookup" data-prefix="{{ $prefix }}">Lookup</button>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Beneficiary / Account Name</label>
            <input type="text" class="form-control dcb-beneficiary" name="{{ $prefix }}_beneficiary_name"
                id="{{ $prefix }}_beneficiary_name" value="{{ $customerName }}" maxlength="120">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Customer MSISDN (payer) <span class="text-danger">*</span></label>
            <input type="text" class="form-control dcb-msisdn" name="{{ $prefix }}_msisdn"
                id="{{ $prefix }}_msisdn" value="{{ $customerPhone }}" maxlength="20">
        </div>
    </div>

    <div class="dcb-lookup-result small text-muted mb-2" id="{{ $prefix }}_lookup_result" style="display:none;"></div>
    <input type="hidden" name="{{ $prefix }}_normalize_destination" value="1">
</div>
@endif
