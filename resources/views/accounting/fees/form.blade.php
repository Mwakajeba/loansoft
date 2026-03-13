@php
    use Vinkla\Hashids\Facades\Hashids;
    $isEdit = isset($fee);
@endphp

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bx bx-error-circle me-2"></i>
        Please fix the following errors:
        <ul class="mb-0 mt-2">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<form
    action="{{ $isEdit ? route('accounting.fees.update', Hashids::encode($fee->id)) : route('accounting.fees.store') }}"
    method="POST">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row">
        <!-- Basic Information Section -->
        <div class="col-12">
            <div class="card radius-10 mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bx bx-info-circle me-2"></i>Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fee Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $fee->name ?? '') }}" placeholder="Enter fee name">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                <option value="">-- Select Status --</option>
                                @foreach($statusOptions as $value => $label)
                                    <option value="{{ $value }}" {{ old('status', $fee->status ?? '') == $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Chart Account <span class="text-danger">*</span></label>
                            <select name="chart_account_id"
                                class="form-select @error('chart_account_id') is-invalid @enderror" required>
                                <option value="">-- Select Chart Account --</option>
                                @foreach($chartAccounts as $account)
                                    <option value="{{ $account->id }}" {{ old('chart_account_id', $fee->chart_account_id ?? '') == $account->id ? 'selected' : '' }}>
                                        {{ $account->account_name }} ({{ $account->account_code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('chart_account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <!-- <div class="col-md-6 mb-3">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror">
                                <option value="">-- Select Branch --</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ old('branch_id', $fee->branch_id ?? '') == $branch->id ? 'selected' : '' }}>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div> -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Configuration Section -->
        <div class="col-12">
            <div class="card radius-10 mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bx bx-cog me-2"></i>Fee Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Fee Type <span class="text-danger">*</span></label>
                            <select name="fee_type" class="form-select @error('fee_type') is-invalid @enderror"
                                required>
                                <option value="">-- Select Fee Type --</option>
                                @foreach($feeTypeOptions as $value => $label)
                                    <option value="{{ $value }}" {{ old('fee_type', $fee->fee_type ?? '') == $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('fee_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3" id="amount-field-container">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="amount" id="amount-input"
                                    class="form-control @error('amount') is-invalid @enderror"
                                    value="{{ old('amount', $fee->amount ?? '') }}" min="0" step="0.01"
                                    placeholder="Enter amount">
                                <span class="input-group-text" id="amount-suffix">TZS</span>
                            </div>
                            <div class="form-text" id="amount-help">Enter the fee amount</div>
                            @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <!-- Range Fee Configuration -->
                        <div class="col-12 mb-3" id="range-fee-container" style="display: none;">
                            <label class="form-label">Fee Ranges <span class="text-danger">*</span></label>
                            <div class="card border-info">
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered" id="fee-ranges-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 30%;">From Amount (TZS)</th>
                                                    <th style="width: 30%;">To Amount (TZS)</th>
                                                    <th style="width: 30%;">Fee Amount (TZS)</th>
                                                    <th style="width: 10%;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody id="fee-ranges-tbody">
                                                @if(isset($fee) && $fee->fee_type === 'range' && $fee->feeRanges->count() > 0)
                                                    @foreach($fee->feeRanges as $index => $range)
                                                        <tr class="range-row">
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][from_amount]" 
                                                                    class="form-control range-from" 
                                                                    value="{{ old("ranges.$index.from_amount", $range->from_amount) }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][to_amount]" 
                                                                    class="form-control range-to" 
                                                                    value="{{ old("ranges.$index.to_amount", $range->to_amount) }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][amount]" 
                                                                    class="form-control range-amount" 
                                                                    value="{{ old("ranges.$index.amount", $range->amount) }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-danger remove-range-row">
                                                                    <i class="bx bx-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                @elseif(old('ranges'))
                                                    @foreach(old('ranges') as $index => $range)
                                                        <tr class="range-row">
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][from_amount]" 
                                                                    class="form-control range-from" 
                                                                    value="{{ $range['from_amount'] ?? '' }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][to_amount]" 
                                                                    class="form-control range-to" 
                                                                    value="{{ $range['to_amount'] ?? '' }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <input type="number" name="ranges[{{ $index }}][amount]" 
                                                                    class="form-control range-amount" 
                                                                    value="{{ $range['amount'] ?? '' }}" 
                                                                    min="0" step="0.01" required>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-danger remove-range-row">
                                                                    <i class="bx bx-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                @endif
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary mt-2" id="add-range-row">
                                        <i class="bx bx-plus me-1"></i>Add Line
                                    </button>
                                    <div class="form-text mt-2">
                                        <i class="bx bx-info-circle me-1"></i>
                                        Define fee ranges based on loan amount. Example: 100,000 - 500,000 = 10,000 fee
                                    </div>
                                </div>
                            </div>
                            @error('ranges') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            @error('ranges.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Deduction Criteria <span class="text-danger">*</span></label>
                            <select name="deduction_criteria"
                                class="form-select @error('deduction_criteria') is-invalid @enderror" required>
                                <option value="">-- Select Deduction Criteria --</option>
                                @foreach($deductionCriteriaOptions as $value => $label)
                                    <option value="{{ $value }}" {{ old('deduction_criteria', $fee->deduction_criteria ?? '') == $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('deduction_criteria') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Include in Schedule</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_in_schedule" id="include_in_schedule" value="1" {{ old('include_in_schedule', $fee->include_in_schedule ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="include_in_schedule">
                                    Yes
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Information Section -->
        <div class="col-12">
            <div class="card radius-10 mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bx bx-detail me-2"></i>Additional Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                                rows="3"
                                placeholder="Enter fee description">{{ old('description', $fee->description ?? '') }}</textarea>
                            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="{{ route('accounting.fees.index') }}" class="btn btn-secondary">
            Back to Fees
        </a>
        <button type="submit" class="btn btn-primary">
            {{ $isEdit ? 'Update Fee' : 'Create Fee' }}
        </button>
    </div>
</form>

@push('scripts')
    <script>
        $(document).ready(function () {
            let rangeRowIndex = {{ isset($fee) && $fee->fee_type === 'range' && $fee->feeRanges ? $fee->feeRanges->count() : (old('ranges') ? count(old('ranges')) : 0) }};

            // Format number with commas
            function formatNumberWithCommas(value) {
                let numValue = value.toString().replace(/[^\d]/g, '');
                return numValue.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            // Remove commas from number
            function removeCommas(value) {
                return value.toString().replace(/,/g, '');
            }

            // Update amount suffix and help text based on fee type
            function updateAmountField() {
                const feeType = $('select[name="fee_type"]').val();
                const suffix = $('#amount-suffix');
                const help = $('#amount-help');
                const amountContainer = $('#amount-field-container');
                const rangeContainer = $('#range-fee-container');
                const amountInput = $('#amount-input');

                if (feeType === 'range') {
                    amountContainer.hide();
                    rangeContainer.show();
                    amountInput.removeAttr('required');
                } else {
                    amountContainer.show();
                    rangeContainer.hide();
                    amountInput.attr('required', 'required');
                    
                    if (feeType === 'percentage') {
                        suffix.text('%');
                        help.text('Enter the percentage value (e.g., 5 for 5%)');
                    } else {
                        suffix.text('TZS');
                        help.text('Enter the fee amount');
                    }
                }
            }

            // Add range row
            function addRangeRow() {
                const tbody = $('#fee-ranges-tbody');
                const row = `
                    <tr class="range-row">
                        <td>
                            <input type="number" name="ranges[${rangeRowIndex}][from_amount]" 
                                class="form-control range-from" 
                                min="0" step="0.01" required>
                        </td>
                        <td>
                            <input type="number" name="ranges[${rangeRowIndex}][to_amount]" 
                                class="form-control range-to" 
                                min="0" step="0.01" required>
                        </td>
                        <td>
                            <input type="number" name="ranges[${rangeRowIndex}][amount]" 
                                class="form-control range-amount" 
                                min="0" step="0.01" required>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-danger remove-range-row">
                                <i class="bx bx-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.append(row);
                rangeRowIndex++;
            }

            // Remove range row
            $(document).on('click', '.remove-range-row', function() {
                const row = $(this).closest('.range-row');
                if ($('#fee-ranges-tbody .range-row').length > 1) {
                    row.remove();
                } else {
                    alert('At least one range is required.');
                }
            });

            // Add range row button
            $('#add-range-row').on('click', function() {
                addRangeRow();
            });

            // Update on page load
            updateAmountField();

            // Update when fee type changes
            $('select[name="fee_type"]').on('change', function() {
                updateAmountField();
                
                // If switching to range and no rows exist, add one
                if ($(this).val() === 'range' && $('#fee-ranges-tbody .range-row').length === 0) {
                    addRangeRow();
                }
            });

            // Format range inputs with commas on blur
            $(document).on('blur', '.range-from, .range-to, .range-amount', function() {
                let value = $(this).val();
                if (value) {
                    let numericValue = removeCommas(value);
                    $(this).val(numericValue);
                }
            });
        });
    </script>
@endpush