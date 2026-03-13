<form action="{{ isset($bankAccount) ? route('accounting.bank-accounts.update', \Vinkla\Hashids\Facades\Hashids::encode($bankAccount->id)) : route('accounting.bank-accounts.store') }}" method="POST" id="bank-account-form">
    @csrf
    @if(isset($bankAccount)) @method('PUT') @endif

    <!-- Branch Scope Section -->
    <div class="mb-4">
        <h6 class="mb-3 text-primary">
            <i class="bx bx-git-branch me-2"></i>
            Branch Scope
        </h6>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-label fw-semibold d-block">
                        <i class="bx bx-buildings me-1 text-primary"></i>
                        Apply To
                    </label>
                    @php
                        $scopeAll = old('branch_scope', isset($bankAccount) && $bankAccount->is_all_branches ? 'all' : null);
                        if (!$scopeAll && isset($bankAccount) && !$bankAccount->is_all_branches) {
                            $scopeAll = 'specific';
                        }
                        if (!$scopeAll) {
                            $scopeAll = 'all';
                        }
                    @endphp
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="branch_scope" id="branch_scope_all" value="all"
                               {{ $scopeAll === 'all' ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch_scope_all">All branches</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="branch_scope" id="branch_scope_specific" value="specific"
                               {{ $scopeAll === 'specific' ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch_scope_specific">Specific branch</label>
                    </div>
                    @error('branch_scope')
                        <div class="invalid-feedback d-block">
                            <i class="bx bx-error-circle me-1"></i>{{ $message }}
                        </div>
                    @enderror
                    <small class="form-text text-muted d-block mt-1">
                        <i class="bx bx-info-circle me-1"></i>
                        Choose whether this bank account is available company-wide or only for a specific branch.
                    </small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group" id="branch_select_container" @if($scopeAll === 'all') style="display:none;" @endif>
                    <label for="branch_id" class="form-label fw-semibold">
                        <i class="bx bx-building me-1 text-primary"></i>
                        Branch
                    </label>
                    <select class="form-select select2-single @error('branch_id') is-invalid @enderror"
                            name="branch_id"
                            id="branch_id"
                            data-placeholder="-- Select Branch --">
                        <option value="">-- Select Branch --</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}"
                                {{ (old('branch_id', $bankAccount->branch_id ?? null) == $branch->id) ? 'selected' : '' }}>
                                {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        <i class="bx bx-info-circle me-1"></i>
                        If you select a specific branch, this bank account will only be shown for that branch in branch-based reports and transaction screens.
                    </small>
                    @error('branch_id')
                        <div class="invalid-feedback d-block">
                            <i class="bx bx-error-circle me-1"></i>{{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details Section -->
    <div class="mb-4">
        <h6 class="mb-3 text-primary">
            <i class="bx bx-info-circle me-2"></i>
            Account Details
        </h6>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="chart_account_id" class="form-label fw-semibold">
                        <i class="bx bx-list-ul me-1 text-primary"></i>
                        Chart Account <span class="text-danger">*</span>
                    </label>
                    <select class="form-select select2-single @error('chart_account_id') is-invalid @enderror" 
                            name="chart_account_id" 
                            id="chart_account_id" 
                            required>
                        <option value="">-- Select Chart Account --</option>
                        @foreach($chartAccounts as $chartAccount)
                            <option value="{{ $chartAccount->id }}" 
                                    {{ (old('chart_account_id') == $chartAccount->id || (isset($bankAccount) && $bankAccount->chart_account_id == $chartAccount->id)) ? 'selected' : '' }}>
                                {{ $chartAccount->account_name }} 
                                ({{ $chartAccount->accountClassGroup->accountClass->name ?? 'N/A' }} - {{ $chartAccount->accountClassGroup->name ?? 'N/A' }})
                            </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">
                        <i class="bx bx-info-circle me-1"></i>
                        Select the chart of account that will be linked to this bank account
                    </small>
                    @error('chart_account_id')
                        <div class="invalid-feedback d-block">
                            <i class="bx bx-error-circle me-1"></i>{{ $message }}
                        </div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-group">
                    <label for="name" class="form-label fw-semibold">
                        <i class="bx bx-building me-1 text-primary"></i>
                        Bank Name <span class="text-danger">*</span>
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light">
                            <i class="bx bx-bank"></i>
                        </span>
                        <input type="text" 
                               class="form-control @error('name') is-invalid @enderror" 
                               id="name" 
                               name="name" 
                               value="{{ old('name', $bankAccount->name ?? '') }}" 
                               placeholder="e.g., National Bank of Tanzania"
                               required>
                    </div>
                    <small class="form-text text-muted">
                        <i class="bx bx-info-circle me-1"></i>
                        Enter the full name of the bank or financial institution
                    </small>
                    @error('name')
                        <div class="invalid-feedback d-block">
                            <i class="bx bx-error-circle me-1"></i>{{ $message }}
                        </div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-group">
                    <label for="account_number" class="form-label fw-semibold">
                        <i class="bx bx-credit-card me-1 text-primary"></i>
                        Account Number <span class="text-danger">*</span>
                    </label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-light">
                            <i class="bx bx-hash"></i>
                        </span>
                        <input type="text" 
                               class="form-control @error('account_number') is-invalid @enderror" 
                               id="account_number" 
                               name="account_number" 
                               value="{{ old('account_number', $bankAccount->account_number ?? '') }}" 
                               placeholder="e.g., 1234567890"
                               required>
                    </div>
                    <small class="form-text text-muted">
                        <i class="bx bx-info-circle me-1"></i>
                        Enter the unique bank account number. This must be unique across all bank accounts.
                    </small>
                    @error('account_number')
                        <div class="invalid-feedback d-block">
                            <i class="bx bx-error-circle me-1"></i>{{ $message }}
                        </div>
                    @enderror
                </div>
            </div>

        </div>
    </div>

    <!-- Action Buttons -->
    <div class="border-top pt-4 mt-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted">
                    <i class="bx bx-info-circle me-1"></i>
                    Fields marked with <span class="text-danger">*</span> are required
                </small>
            </div>
            <div class="d-flex gap-2">
                @can('view bank accounts')
                <a href="{{ route('accounting.bank-accounts') }}" class="btn btn-outline-secondary btn-lg">
                    <i class="bx bx-x me-1"></i> Cancel
                </a>
                @endcan
                <button type="submit" class="btn btn-{{ isset($bankAccount) ? 'primary' : 'success' }} btn-lg px-4">
                    <i class="bx bx-{{ isset($bankAccount) ? 'check' : 'plus' }}-circle me-1"></i>
                    {{ isset($bankAccount) ? 'Update Bank Account' : 'Create Bank Account' }}
                </button>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
$(document).ready(function() {
    // Initialize Select2 for chart account, currency and branch dropdowns
    $('.select2-single').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: function() {
            const id = $(this).attr('id');
            if (id === 'currency') {
                return '-- Select Currency --';
            }
            if (id === 'branch_id') {
                return '-- Select Branch --';
            }
            return '-- Select Chart Account --';
        },
        allowClear: true
    });

    // Toggle branch select visibility based on scope
    function toggleBranchScope() {
        const scope = $('input[name="branch_scope"]:checked').val();
        if (scope === 'specific') {
            $('#branch_select_container').show();
        } else {
            $('#branch_select_container').hide();
            $('#branch_id').val(null).trigger('change');
        }
    }

    $('input[name="branch_scope"]').on('change', toggleBranchScope);
    toggleBranchScope();

    // Auto-enable revaluation if currency is not functional currency
    const functionalCurrency = '{{ \App\Models\SystemSetting::getValue("functional_currency", Auth::user()->company->functional_currency ?? "TZS") }}';
    $('#currency').on('change', function() {
        const selectedCurrency = $(this).val();
        if (selectedCurrency && selectedCurrency !== functionalCurrency) {
            $('#revaluation_required').prop('checked', true);
        } else if (selectedCurrency === functionalCurrency) {
            $('#revaluation_required').prop('checked', false);
        }
    });
    
    // Form validation enhancement
    $('#bank-account-form').on('submit', function(e) {
        let isValid = true;
        
        // Check required fields
        $('#bank-account-form [required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            // Show toast notification
            toastr.error('Please fill in all required fields', 'Validation Error');
        }
    });
    
    // Remove invalid class on input
    $('#bank-account-form input, #bank-account-form select').on('input change', function() {
        if ($(this).val()) {
            $(this).removeClass('is-invalid');
        }
    });
});
</script>
@endpush 