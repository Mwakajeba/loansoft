@extends('layouts.main')

@section('title', 'DCB Payment Gateway')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'DCB Gateway', 'url' => '#', 'icon' => 'bx bx-transfer']
        ]" />
        <h6 class="mb-0 text-uppercase">DCB PAYMENT GATEWAY</h6>
        <hr />

        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body">
                        @role('super-admin')
                        <h4 class="card-title mb-4">SmartSoft DCB Gateway Configuration</h4>
                        <p class="text-muted">
                            Connect to the SmartSoft DCB Gateway for TIPS transfers (account lookup and disbursements).
                            Your server outbound IP must be whitelisted on the gateway.
                        </p>

                        @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        @endif

                        @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        @endif

                        <form action="{{ route('settings.dcb.update') }}" method="POST" id="dcbSettingsForm">
                            @csrf
                            @method('PUT')

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="dcb_enabled" name="dcb_enabled" value="1"
                                    {{ old('dcb_enabled', config('services.dcb.enabled')) ? 'checked' : '' }}>
                                <label class="form-check-label" for="dcb_enabled">Enable DCB payments</label>
                            </div>

                            <div class="mb-3">
                                <label for="dcb_base_url" class="form-label">Gateway Base URL</label>
                                <input type="url" class="form-control" id="dcb_base_url" name="dcb_base_url" required
                                    value="{{ old('dcb_base_url', config('services.dcb.base_url', 'https://gateway.smartsot.tz')) }}">
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="dcb_business_id" class="form-label">Business ID</label>
                                    <input type="text" class="form-control" id="dcb_business_id" name="dcb_business_id" required
                                        value="{{ old('dcb_business_id', config('services.dcb.business_id')) }}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="dcb_api_key" class="form-label">API Key</label>
                                    <input type="text" class="form-control" id="dcb_api_key" name="dcb_api_key" required
                                        value="{{ old('dcb_api_key', config('services.dcb.api_key')) }}">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="dcb_api_secret" class="form-label">API Secret</label>
                                <input type="password" class="form-control" id="dcb_api_secret" name="dcb_api_secret" required
                                    value="{{ old('dcb_api_secret', config('services.dcb.api_secret')) }}">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="show_dcb_secret" onchange="toggleDcbSecret()">
                                    <label class="form-check-label" for="show_dcb_secret">Show secret</label>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="dcb_sender_name" class="form-label">Sender / Company Name</label>
                                    <input type="text" class="form-control" id="dcb_sender_name" name="dcb_sender_name" required maxlength="120"
                                        value="{{ old('dcb_sender_name', config('services.dcb.sender_name')) }}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="dcb_default_institution_code" class="form-label">Default Institution Code</label>
                                    <input type="text" class="form-control" id="dcb_default_institution_code" name="dcb_default_institution_code"
                                        value="{{ old('dcb_default_institution_code', config('services.dcb.default_institution_code')) }}"
                                        placeholder="Optional — e.g. mobile money FSP code">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="dcb_settlement_bank_account_id" class="form-label">Default Settlement Bank (GL)</label>
                                    <select class="form-select" id="dcb_settlement_bank_account_id" name="dcb_settlement_bank_account_id">
                                        <option value="">— Select —</option>
                                        @foreach($bankAccounts ?? [] as $ba)
                                            <option value="{{ $ba->id }}" {{ (string) old('dcb_settlement_bank_account_id', config('services.dcb.settlement_bank_account_id')) === (string) $ba->id ? 'selected' : '' }}>
                                                {{ $ba->name }} — {{ $ba->account_number }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">Bank account used for GL when a DCB repayment is confirmed. Repayment funds are received on the account configured on the DCB gateway server.</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="dcb_callback_secret" class="form-label">Callback Secret (optional)</label>
                                <input type="password" class="form-control" id="dcb_callback_secret" name="dcb_callback_secret"
                                    value="{{ old('dcb_callback_secret', config('services.dcb.callback_secret')) }}"
                                    placeholder="Validate incoming callbacks via X-DCB-Callback-Secret header">
                            </div>

                            <div class="alert alert-info">
                                <strong>Callback URL</strong> (configure on the gateway server):<br>
                                <code>{{ $callbackUrl }}</code>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bx bx-save me-1"></i> Save Settings
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnTestDcb">
                                    <i class="bx bx-plug me-1"></i> Test Connection
                                </button>
                                <a href="{{ route('settings.index') }}" class="btn btn-secondary">Back</a>
                            </div>
                        </form>
                        @else
                        <div class="alert alert-warning">You don't have permission to manage DCB settings.</div>
                        @endrole
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Test Transfer</h5>
                        <p class="text-muted small">Lookup account then submit a TIPS transfer (requires enabled DCB).</p>

                        <div class="mb-2">
                            <label class="form-label">Institution</label>
                            <select class="form-select" id="test_institution_code">
                                <option value="">— Load institutions —</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-link p-0 mt-1" id="btnLoadInstitutions">Refresh FSP list</button>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Account / MSISDN</label>
                            <input type="text" class="form-control" id="test_account_no" placeholder="0712345678">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="btnLookup">Account Lookup</button>
                        <div id="lookupResult" class="small text-muted mb-3"></div>

                        <div class="mb-2">
                            <label class="form-label">Beneficiary Name</label>
                            <input type="text" class="form-control" id="test_beneficiary_name" maxlength="120">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Sender MSISDN</label>
                            <input type="text" class="form-control" id="test_msisdn" placeholder="0712000000">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Amount (TZS)</label>
                            <input type="number" class="form-control" id="test_amount" min="1" step="1">
                        </div>
                        <button type="button" class="btn btn-warning w-100" id="btnTransfer">
                            <i class="bx bx-send me-1"></i> Initiate Transfer
                        </button>
                        <div id="transferResult" class="mt-2 small"></div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">Recent Transactions</h5>
                        @if($recentTransactions->isEmpty())
                            <p class="text-muted small mb-0">No DCB transactions yet.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Ref</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentTransactions as $tx)
                                        <tr>
                                            <td class="small">{{ $tx->client_reference }}</td>
                                            <td>{{ number_format($tx->amount) }}</td>
                                            <td>
                                                <span class="badge bg-{{ $tx->status === 'success' ? 'success' : ($tx->status === 'failed' ? 'danger' : 'secondary') }}">
                                                    {{ $tx->status }}
                                                </span>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleDcbSecret() {
    const input = document.getElementById('dcb_api_secret');
    input.type = document.getElementById('show_dcb_secret').checked ? 'text' : 'password';
}

const csrf = '{{ csrf_token() }}';

document.getElementById('btnTestDcb')?.addEventListener('click', function () {
    const form = document.getElementById('dcbSettingsForm');
    fetch('{{ route("settings.dcb.test") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            dcb_base_url: document.getElementById('dcb_base_url').value,
            dcb_business_id: document.getElementById('dcb_business_id').value,
            dcb_api_key: document.getElementById('dcb_api_key').value,
            dcb_api_secret: document.getElementById('dcb_api_secret').value,
        }),
    })
    .then(r => r.json())
    .then(data => alert((data.success ? '✓ ' : '✗ ') + (data.message || 'Done')))
    .catch(e => alert('Error: ' + e.message));
});

document.getElementById('btnLoadInstitutions')?.addEventListener('click', function () {
    fetch('{{ route("settings.dcb.financial-institutions") }}', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('test_institution_code');
            sel.innerHTML = '<option value="">— Select —</option>';
            const list = data.financial_institutions || [];
            list.forEach(fsp => {
                const code = fsp.institutionCode || fsp.institution_code || fsp.code || '';
                const name = fsp.institutionName || fsp.name || code;
                if (code) {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = name + ' (' + code + ')';
                    sel.appendChild(opt);
                }
            });
            if (!list.length) alert(data.message || 'No institutions returned.');
        })
        .catch(e => alert('Failed to load institutions: ' + e.message));
});

document.getElementById('btnLookup')?.addEventListener('click', function () {
    const account = document.getElementById('test_account_no').value;
    const code = document.getElementById('test_institution_code').value;
    if (!account || !code) { alert('Account and institution are required.'); return; }

    fetch('{{ route("settings.dcb.account-lookup") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ account_no: account, institution_code: code, normalize: true }),
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('lookupResult').textContent = JSON.stringify(data, null, 2);
        if (data.success && data.data) {
            const name = data.data.accountName || data.data.beneficiary_name || data.data.name;
            if (name) document.getElementById('test_beneficiary_name').value = name;
        }
    })
    .catch(e => document.getElementById('lookupResult').textContent = e.message);
});

document.getElementById('btnTransfer')?.addEventListener('click', function () {
    const payload = {
        destination_account: document.getElementById('test_account_no').value,
        institution_code: document.getElementById('test_institution_code').value,
        amount: parseInt(document.getElementById('test_amount').value, 10),
        beneficiary_name: document.getElementById('test_beneficiary_name').value,
        msisdn: document.getElementById('test_msisdn').value,
        normalize_destination: true,
    };

    fetch('{{ route("settings.dcb.transfer") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('transferResult').textContent = JSON.stringify(data, null, 2);
    })
    .catch(e => document.getElementById('transferResult').textContent = e.message);
});
</script>
@endpush
