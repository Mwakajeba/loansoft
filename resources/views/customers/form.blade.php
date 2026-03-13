@php
$isEdit = isset($customer);
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

<form action="{{ $isEdit ? route('customers.update', $customer) : route('customers.store') }}"
      method="POST" enctype="multipart/form-data">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row">
        <!-- Left Column - Form Fields -->
        <div class="col-lg-8">
            <div class="row">
        <!-- Name -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                value="{{ old('name', $customer->name ?? '') }}" placeholder="Enter full name">
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Sex -->
        <div class="col-md-6 mb-3">
            <label for="sex" class="form-label">Sex <span class="text-danger">*</span></label>
            <select name="sex" id="sex" class="form-control @error('sex') is-invalid @enderror" required>
                <option value="">-- Select Sex --</option>
                <option value="M" {{ old('sex', $customer->sex ?? '') == 'M' ? 'selected' : '' }}>Male</option>
                <option value="F" {{ old('sex', $customer->sex ?? '') == 'F' ? 'selected' : '' }}>Female</option>
            </select>
            @error('sex')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <!-- Description -->
        <div class="col-md-12 mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                      rows="3" placeholder="Enter customer description">{{ old('description', $customer->description ?? '') }}</textarea>
            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Phone 1 -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
            <input type="text" name="phone1" id="phone1" class="form-control @error('phone1') is-invalid @enderror"
                value="{{ old('phone1', $customer->phone1 ?? '') }}" placeholder="255XXXXXXXXX">
            <small class="form-text text-muted">Phone will be formatted to start with 255</small>
            @error('phone1') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Phone 2 -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Alternative Phone Number</label>
            <input type="text" name="phone2" class="form-control @error('phone2') is-invalid @enderror"
                value="{{ old('phone2', $customer->phone2 ?? '') }}" placeholder="Enter alternative phone">
            @error('phone2') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Region -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Region <span class="text-danger">*</span></label>
            <select name="region_id" id="region" class="form-select select2-single @error('region_id') is-invalid @enderror" required>
                <option value="">Select Region</option>
                @foreach($regions as $region)
                <option value="{{ $region->id }}" {{ old('region_id', $customer->region_id ?? '') == $region->id ? 'selected' : '' }}>
                    {{ $region->name }}
                </option>
                @endforeach
            </select>
            @error('region_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- District -->
        <div class="col-md-6 mb-3">
            <label class="form-label">District <span class="text-danger">*</span></label>
            <select name="district_id" id="district" class="form-select @error('district_id') is-invalid @enderror"
                required>
                <option value="">Select District</option>
                @if($isEdit && isset($customer) && $customer->district_id)
                <option value="{{ $customer->district_id }}" selected>
                    {{ $customer->district->name ?? 'Selected District' }}
                </option>
                @elseif(old('district_id'))
                <option value="{{ old('district_id') }}" selected>
                    {{ \App\Models\District::find(old('district_id'))->name ?? 'Selected District' }}
                </option>
                @endif
            </select>
            @error('district_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Work -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Work</label>
            <input type="text" name="work" class="form-control @error('work') is-invalid @enderror"
                value="{{ old('work', $customer->work ?? '') }}" placeholder="e.g. Teacher">
            @error('work') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Work Address -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Work Address</label>
            <input type="text" name="workAddress" class="form-control @error('workAddress') is-invalid @enderror"
                value="{{ old('workAddress', $customer->workAddress ?? '') }}" placeholder="e.g. ABC School, Dar">
            @error('workAddress') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- ID Type -->
        <div class="col-md-6 mb-3">
            <label class="form-label">ID Type</label>
            <select name="idType" id="idType" class="form-select @error('idType') is-invalid @enderror">
                <option value="">Select ID Type</option>
                @foreach(['National ID', 'License', 'Voter Registration', 'Other'] as $type)
                <option value="{{ $type }}" {{ old('idType', $customer->idType ?? '') == $type ? 'selected' : '' }}>
                    {{ $type }}
                </option>
                @endforeach
            </select>
            @error('idType') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- ID Number -->
        <div class="col-md-6 mb-3">
            <label class="form-label">ID Number</label>
            <input type="text" name="idNumber" id="idNumber" class="form-control @error('idNumber') is-invalid @enderror"
                value="{{ old('idNumber', $customer->idNumber ?? '') }}" placeholder="Enter ID Number">
            <small id="idNumberHint" class="form-text text-muted" style="display: none;"></small>
            @error('idNumber') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- DOB -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
            <input type="date" name="dob" id="dob" class="form-control @error('dob') is-invalid @enderror"
                value="{{ old('dob', isset($customer) && $customer->dob ? \Carbon\Carbon::parse($customer->dob)->format('Y-m-d') : '') }}" max="{{ date('Y-m-d', strtotime('-18 years')) }}">
            <small class="form-text text-muted">Age must be 18 years or older</small>
            @error('dob') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Relation -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Relation</label>
            <input type="text" name="relation" class="form-control @error('relation') is-invalid @enderror"
                value="{{ old('relation', $customer->relation ?? '') }}" placeholder="e.g. Spouse, Parent">
            @error('relation') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Photo Upload -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Photo</label>
            <input type="file" name="photo" accept="image/*" class="form-control" onchange="previewImage(event)">
            @error('photo') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <div id="preview" class="mt-2">
                @if($isEdit && isset($customer) && $customer->photo)
                <img src="{{ asset('storage/'.$customer->photo) }}" width="100">
                @endif
            </div>
        </div>

        <!-- Document Upload -->
        {{-- <div class="col-md-6 mb-3">
            <label class="form-label">Upload Document</label>
            <input type="file" name="document" class="form-control @error('document') is-invalid @enderror"
                accept=".pdf,.doc,.docx,image/*">
            @error('document') <div class="invalid-feedback">{{ $message }}</div> @enderror

            @if($isEdit && isset($customer) && $customer->document)
                <div class="mt-2">
                    <a href="{{ asset('storage/' . $customer->document) }}" target="_blank">
                        View Uploaded Document
                    </a>
                </div>
            @endif
        </div> --}}

        @if($isEdit)
        <!-- Password (only for edit) -->
        <div class="col-md-6 mb-3">
            <label class="form-label">New Password (leave blank to keep current)</label>
            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                placeholder="Enter new password">
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        @endif

        <!-- Cash Collateral -->
        <div class="col-md-6 mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" value="1" name="has_cash_collateral"
                    id="has_cash_collateral" {{ old('has_cash_collateral', $customer->has_cash_collateral ?? false) ? 'checked' : '' }}>
                <label class="form-check-label" for="has_cash_collateral">Has Cash Collateral</label>
            </div>
        </div>

        <!-- Collateral Type -->
        <div class="col-md-6 mb-3" id="collateral-type-container" style="display: none;">
            <label class="form-label">Collateral Type</label>
            <select name="collateral_type_id" class="form-select">
                <option value="">Select Collateral Type</option>
                @foreach($collateralTypes as $type)
                    <option value="{{ $type->id }}"
                        {{ old('collateral_type_id', isset($customer) ? ($customer->collaterals->first()->type_id ?? $customer->collateral_type_id ?? '') : '') == $type->id ? 'selected' : '' }}>
                        {{ $type->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <!-- Loan Officers -->
        <div class="col-md-12 mb-3">
            <label class="form-label">Assign Loan Officer(s)</label>
            @if($loanOfficers->count() > 0)
            <div class="row">
                @foreach($loanOfficers as $officer)
                <div class="col-md-4 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="loan_officer_ids[]"
                            value="{{ $officer->id }}"
                            {{ in_array($officer->id, old('loan_officer_ids', $customer->loan_officer_ids ?? [])) ? 'checked' : '' }}>
                        <label class="form-check-label">{{ $officer->name }}</label>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="alert alert-info">
                <i class="bx bx-info-circle me-2"></i>
                No loan officers found. Please create loan officer roles first.
            </div>
            @endif
        </div>

        <!-- Category -->
        <div class="col-md-6 mb-3">
            <label class="form-label">Category <span class="text-danger">*</span></label>
            <select name="category" class="form-select @error('category') is-invalid @enderror" required>
                <option value="">Select Category</option>
                <option value="Guarantor" {{ old('category', $customer->category ?? '') == 'Guarantor' ? 'selected' : '' }}>Guarantor</option>
                <option value="Borrower" {{ old('category', $customer->category ?? '') == 'Borrower' ? 'selected' : '' }}>Borrower</option>
            </select>
            @error('category') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <!-- Group -->
        {{-- <div class="col-md-6 mb-3 hidden">
            <label class="form-label">Group</label>
            <select name="group_id" class="form-select selectpicker" data-live-search="true">
                <option value="">Select Group</option>
                @foreach($groups as $group)
                    @if($group)
                        <option value="{{ $group->id }}"
                            {{ (old('group_id', (isset($customer) && $customer->group_id) ? $customer->group_id : ((isset($customer) && isset($customer->groups) && $customer->groups->first()) ? $customer->groups->first()->id : ''))) == $group->id ? 'selected' : '' }}>
                            {{ $group->name }}
                        </option>
                    @endif
                @endforeach
            </select>
            @error('group_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div> --}}

        <hr class="my-4">

        <div class="d-flex justify-content-between">
            @can('view borrower')
            <a href="{{ route('customers.index') }}" class="btn btn-secondary">
                <i class="bx bx-arrow-back me-1"></i> Back to Customers
            </a>
            @endcan
            <button type="submit" class="btn btn-primary">
                <i class="bx bx-save me-1"></i> {{ $isEdit ? 'Update Customer' : 'Create Customer' }}
            </button>
        </div>
            </div>
        </div>

        <!-- Right Column - Guidelines -->
        <div class="col-lg-4">
            <div class="card border-info shadow-sm sticky-top" style="top: 20px;">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bx bx-info-circle me-2"></i>Guidelines & Information</h6>
                </div>
                <div class="card-body">
                    <h6 class="fw-bold text-primary mb-3"><i class="bx bx-phone me-2"></i>Phone Number</h6>
                    <ul class="list-unstyled mb-4">
                        <li><i class="bx bx-check text-success me-2"></i>Phone numbers are automatically formatted to start with <strong>255</strong></li>
                        <li><i class="bx bx-check text-success me-2"></i>Enter with or without country code - system will format it</li>
                        <li><i class="bx bx-check text-success me-2"></i>Example: 0712345678 → 255712345678</li>
                    </ul>

                    <h6 class="fw-bold text-primary mb-3"><i class="bx bx-calendar me-2"></i>Age Requirement</h6>
                    <ul class="list-unstyled mb-4">
                        <li><i class="bx bx-check text-success me-2"></i>Customer must be <strong>18 years or older</strong></li>
                        <li><i class="bx bx-check text-success me-2"></i>Date of birth is automatically validated</li>
                    </ul>

                    <h6 class="fw-bold text-primary mb-3"><i class="bx bx-id-card me-2"></i>National ID Validation</h6>
                    <ul class="list-unstyled mb-4">
                        <li><i class="bx bx-check text-success me-2"></i>Format: <code>YYYYMMDD-XXXXX-XXXXX-XX</code></li>
                        <li><i class="bx bx-check text-success me-2"></i>Date of Birth must match the first 8 digits (YYYYMMDD)</li>
                        <li><i class="bx bx-check text-success me-2"></i>Age from National ID must be 18+ years</li>
                        <li><i class="bx bx-check text-success me-2"></i><strong>Sex Validation:</strong></li>
                        <li class="ms-4"><i class="bx bx-male text-primary me-2"></i>Male (M): Second digit from last must be <strong>2</strong></li>
                        <li class="ms-4"><i class="bx bx-female text-danger me-2"></i>Female (F): Second digit from last must be <strong>1</strong></li>
                    </ul>

                    <h6 class="fw-bold text-primary mb-3"><i class="bx bx-file me-2"></i>Other ID Types</h6>
                    <ul class="list-unstyled mb-4">
                        <li><i class="bx bx-check text-success me-2"></i><strong>Voter Registration:</strong> 12 digits</li>
                        <li><i class="bx bx-check text-success me-2"></i><strong>License:</strong> 8 digits</li>
                        <li><i class="bx bx-check text-success me-2"></i><strong>Other:</strong> No format restrictions</li>
                    </ul>

                    <h6 class="fw-bold text-primary mb-3"><i class="bx bx-info-circle me-2"></i>General Notes</h6>
                    <ul class="list-unstyled mb-0">
                        <li><i class="bx bx-info text-info me-2"></i>Fields marked with <span class="text-danger">*</span> are required</li>
                        <li><i class="bx bx-info text-info me-2"></i>All validations are performed in real-time</li>
                        <li><i class="bx bx-info text-info me-2"></i>Ensure all information is accurate before submitting</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkbox = document.querySelector('#has_cash_collateral');
        const collateralContainer = document.querySelector('#collateral-type-container');
        const regionSelect = document.querySelector('#region');
        const districtSelect = document.querySelector('#district');

        // Show/hide collateral type
        function toggleCollateralField() {
            if (checkbox.checked) {
                collateralContainer.style.display = 'block';
            } else {
                collateralContainer.style.display = 'none';
            }
        }

        checkbox.addEventListener('change', toggleCollateralField);
        toggleCollateralField(); // On load

        // Load districts on region change
        regionSelect.addEventListener('change', function() {
            const regionId = this.value;

            if (!regionId) {
                districtSelect.innerHTML = '<option value="">Select District</option>';
                return;
            }

            fetch(`/get-districts/${regionId}`)
                .then(response => response.json())
                .then(data => {
                    districtSelect.innerHTML = '<option value="">Select District</option>';
                    Object.entries(data).forEach(([id, name]) => {
                        const option = document.createElement('option');
                        option.value = id;
                        option.textContent = name;
                        districtSelect.appendChild(option);
                    });
                })
                .catch(error => console.error('Error loading districts:', error));
        });

        // ID Type format handling
        const idTypeSelect = document.querySelector('#idType');
        const idNumberInput = document.querySelector('#idNumber');
        const idNumberHint = document.querySelector('#idNumberHint');
        
        function handleIdTypeChange() {
            const idType = idTypeSelect.value;
            let placeholder = 'Enter ID Number';
            let hint = '';
            let maxLength = null;
            let pattern = null;
            
            // Remove any existing formatting
            idNumberInput.removeEventListener('input', formatNationalId);
            idNumberInput.removeEventListener('keypress', restrictToDigits);
            
            switch(idType) {
                case 'National ID':
                    placeholder = '19901234-12345-00001-12';
                    hint = 'Format: YYYYMMDD-XXXXX-XXXXX-XX (e.g., 19901234-12345-00001-12)';
                    maxLength = 25; // 8-5-5-2 with 3 dashes
                    pattern = /^\d{8}-\d{5}-\d{5}-\d{2}$/;
                    idNumberInput.addEventListener('input', formatNationalId);
                    break;
                case 'Voter Registration':
                    placeholder = '123456789012';
                    hint = 'Format: 12 digits (e.g., 123456789012)';
                    maxLength = 12;
                    pattern = /^\d{12}$/;
                    idNumberInput.addEventListener('keypress', restrictToDigits);
                    break;
                case 'License':
                    placeholder = '12345678';
                    hint = 'Format: 8 digits (e.g., 12345678)';
                    maxLength = 8;
                    pattern = /^\d{8}$/;
                    idNumberInput.addEventListener('keypress', restrictToDigits);
                    break;
                default:
                    placeholder = 'Enter ID Number';
                    hint = '';
                    maxLength = null;
                    pattern = null;
                    break;
            }
            
            idNumberInput.placeholder = placeholder;
            idNumberInput.maxLength = maxLength || 524288; // Default max length
            idNumberInput.setAttribute('data-pattern', pattern ? pattern.source : '');
            
            if (hint) {
                idNumberHint.textContent = hint;
                idNumberHint.style.display = 'block';
            } else {
                idNumberHint.style.display = 'none';
            }
        }
        
        // Format National ID: 19901234-12345-00001-12
        function formatNationalId(e) {
            let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
            
            if (value.length > 0) {
                // Format: YYYYMMDD-XXXXX-XXXXX-XX
                let formatted = value.substring(0, 8); // First 8 digits
                if (value.length > 8) {
                    formatted += '-' + value.substring(8, 13); // Next 5 digits
                }
                if (value.length > 13) {
                    formatted += '-' + value.substring(13, 18); // Next 5 digits
                }
                if (value.length > 18) {
                    formatted += '-' + value.substring(18, 20); // Last 2 digits
                }
                e.target.value = formatted;
            }
        }
        
        // Restrict to digits only
        function restrictToDigits(e) {
            const char = String.fromCharCode(e.which);
            if (!/[0-9]/.test(char)) {
                e.preventDefault();
            }
        }
        
        // Phone number formatting - always start with 255
        const phone1Input = document.querySelector('#phone1');
        phone1Input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
            
            // If it doesn't start with 255, add it
            if (value.length > 0 && !value.startsWith('255')) {
                // Remove leading 0 if present
                if (value.startsWith('0')) {
                    value = '255' + value.substring(1);
                } else {
                    value = '255' + value;
                }
            }
            
            // Limit to 12 digits total (255 + 9 digits)
            if (value.length > 12) {
                value = value.substring(0, 12);
            }
            
            e.target.value = value;
        });
        
        // Age validation - must be 18 or older
        const dobInput = document.querySelector('#dob');
        const sexSelect = document.querySelector('#sex');
        
        function validateAge() {
            if (dobInput.value) {
                const dob = new Date(dobInput.value);
                const today = new Date();
                const age = today.getFullYear() - dob.getFullYear();
                const monthDiff = today.getMonth() - dob.getMonth();
                const actualAge = monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate()) ? age - 1 : age;
                
                if (actualAge < 18) {
                    dobInput.classList.add('is-invalid');
                    const existingError = dobInput.parentNode.querySelector('.invalid-feedback:not([data-laravel])');
                    if (!existingError || existingError.textContent.includes('@' + 'error')) {
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'invalid-feedback';
                        errorDiv.textContent = 'Age must be 18 years or older';
                        dobInput.parentNode.insertBefore(errorDiv, dobInput.nextSibling);
                    }
                    return false;
                } else {
                    dobInput.classList.remove('is-invalid');
                    const existingError = dobInput.parentNode.querySelector('.invalid-feedback:not([data-laravel])');
                    if (existingError && !existingError.textContent.includes('@' + 'error')) {
                        existingError.remove();
                    }
                }
            }
            return true;
        }
        
        dobInput.addEventListener('change', validateAge);
        
        // National ID validation functions
        function extractDateFromNationalId(nationalId) {
            // Format: YYYYMMDD-XXXXX-XXXXX-XX
            const match = nationalId.match(/^(\d{4})(\d{2})(\d{2})-/);
            if (match) {
                const year = parseInt(match[1]);
                const month = parseInt(match[2]);
                const day = parseInt(match[3]);
                
                // Validate date
                const date = new Date(year, month - 1, day);
                if (date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day) {
                    return date;
                }
            }
            return null;
        }
        
        function getSexFromNationalId(nationalId) {
            // Second digit from last (second-to-last digit)
            // Format: YYYYMMDD-XXXXX-XXXXX-XX
            // Last part is XX, so second from last is the first digit of XX
            const match = nationalId.match(/-(\d{2})$/);
            if (match) {
                const lastTwoDigits = match[1];
                const secondFromLast = parseInt(lastTwoDigits.charAt(0));
                return secondFromLast === 2 ? 'M' : (secondFromLast === 1 ? 'F' : null);
            }
            return null;
        }
        
        function validateNationalId() {
            const idType = idTypeSelect.value;
            const idNumber = idNumberInput.value.trim();
            const dob = dobInput.value;
            const sex = sexSelect.value;
            
            if (idType === 'National ID' && idNumber && dob && sex) {
                // Extract date from National ID
                const idDate = extractDateFromNationalId(idNumber);
                if (!idDate) {
                    return { valid: false, message: 'Invalid date in National ID' };
                }
                
                // Compare with entered DOB
                const enteredDob = new Date(dob);
                if (idDate.getTime() !== enteredDob.getTime()) {
                    return { valid: false, message: 'Date of Birth does not match National ID date' };
                }
                
                // Validate age from National ID
                const today = new Date();
                const age = today.getFullYear() - idDate.getFullYear();
                const monthDiff = today.getMonth() - idDate.getMonth();
                const actualAge = monthDiff < 0 || (monthDiff === 0 && today.getDate() < idDate.getDate()) ? age - 1 : age;
                
                if (actualAge < 18) {
                    return { valid: false, message: 'Age from National ID must be 18 years or older' };
                }
                
                // Validate sex
                const idSex = getSexFromNationalId(idNumber);
                if (idSex === null) {
                    return { valid: false, message: 'Invalid sex code in National ID' };
                }
                
                if (idSex !== sex) {
                    return { valid: false, message: `Sex does not match National ID. National ID indicates ${idSex === 'M' ? 'Male' : 'Female'}` };
                }
            }
            
            return { valid: true };
        }
        
        // Initialize on page load
        idTypeSelect.addEventListener('change', handleIdTypeChange);
        handleIdTypeChange(); // Call on page load to set initial state
        
        // Validate on form submit
        const form = document.querySelector('form');
        form.addEventListener('submit', function(e) {
            let hasErrors = false;
            
            // Validate age
            if (!validateAge()) {
                hasErrors = true;
            }
            
            // Validate ID format and National ID specific validations
            const idType = idTypeSelect.value;
            const idNumber = idNumberInput.value.trim();
            
            if (idNumber && idType !== 'Other' && idType !== '') {
                let pattern = idNumberInput.getAttribute('data-pattern');
                if (pattern) {
                    const regex = new RegExp(pattern);
                    if (!regex.test(idNumber)) {
                        e.preventDefault();
                        hasErrors = true;
                        idNumberInput.classList.add('is-invalid');
                        let errorMsg = '';
                        switch(idType) {
                            case 'National ID':
                                errorMsg = 'Invalid National ID format. Expected: YYYYMMDD-XXXXX-XXXXX-XX';
                                break;
                            case 'Voter Registration':
                                errorMsg = 'Invalid Voter ID format. Expected: 12 digits';
                                break;
                            case 'License':
                                errorMsg = 'Invalid License format. Expected: 8 digits';
                                break;
                        }
                        // Remove existing error message if any
                        const existingError = idNumberInput.parentNode.querySelector('.invalid-feedback');
                        if (existingError && !existingError.textContent.includes('@' + 'error')) {
                            existingError.remove();
                        }
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'invalid-feedback';
                        errorDiv.textContent = errorMsg;
                        idNumberInput.parentNode.insertBefore(errorDiv, idNumberInput.nextSibling);
                    } else {
                        // Validate National ID specific rules
                        if (idType === 'National ID') {
                            const validation = validateNationalId();
                            if (!validation.valid) {
                                e.preventDefault();
                                hasErrors = true;
                                idNumberInput.classList.add('is-invalid');
                                const existingError = idNumberInput.parentNode.querySelector('.invalid-feedback');
                                if (existingError && !existingError.textContent.includes('@' + 'error')) {
                                    existingError.remove();
                                }
                                const errorDiv = document.createElement('div');
                                errorDiv.className = 'invalid-feedback';
                                errorDiv.textContent = validation.message;
                                idNumberInput.parentNode.insertBefore(errorDiv, idNumberInput.nextSibling);
                            } else {
                                idNumberInput.classList.remove('is-invalid');
                            }
                        } else {
                            idNumberInput.classList.remove('is-invalid');
                        }
                    }
                }
            }
            
            if (hasErrors) {
                e.preventDefault();
                return false;
            }
        });
        
        // Auto-validate National ID when fields change
        idNumberInput.addEventListener('blur', function() {
            if (idTypeSelect.value === 'National ID' && idNumberInput.value.trim()) {
                const validation = validateNationalId();
                if (!validation.valid) {
                    idNumberInput.classList.add('is-invalid');
                    const existingError = idNumberInput.parentNode.querySelector('.invalid-feedback');
                    if (existingError && !existingError.textContent.includes('@' + 'error')) {
                        existingError.textContent = validation.message;
                    } else if (!existingError || existingError.textContent.includes('@' + 'error')) {
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'invalid-feedback';
                        errorDiv.textContent = validation.message;
                        idNumberInput.parentNode.insertBefore(errorDiv, idNumberInput.nextSibling);
                    }
                } else {
                    idNumberInput.classList.remove('is-invalid');
                }
            }
        });
        
        dobInput.addEventListener('blur', function() {
            if (idTypeSelect.value === 'National ID' && idNumberInput.value.trim()) {
                const validation = validateNationalId();
                if (!validation.valid) {
                    dobInput.classList.add('is-invalid');
                } else {
                    dobInput.classList.remove('is-invalid');
                }
            }
        });
        
        sexSelect.addEventListener('change', function() {
            if (idTypeSelect.value === 'National ID' && idNumberInput.value.trim()) {
                const validation = validateNationalId();
                if (!validation.valid) {
                    sexSelect.classList.add('is-invalid');
                } else {
                    sexSelect.classList.remove('is-invalid');
                }
            }
        });

        // Initialize Select2 for region only (not district)
        if (window.jQuery) {
            $('#region').select2({
                placeholder: 'Select Region',
                allowClear: true,
                width: '100%',
                theme: 'bootstrap-5'
            });
            // Use jQuery event for region change
            $('#region').on('change', function() {
                const regionId = this.value;
                const districtSelect = document.getElementById('district');
                if (!regionId) {
                    districtSelect.innerHTML = '<option value="">Select District</option>';
                    return;
                }
                fetch(`/get-districts/${regionId}`)
                    .then(response => response.json())
                    .then(data => {
                        districtSelect.innerHTML = '<option value="">Select District</option>';
                        Object.entries(data).forEach(([id, name]) => {
                            const option = document.createElement('option');
                            option.value = id;
                            option.textContent = name;
                            districtSelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error loading districts:', error));
            });
        } else {
            // Fallback for non-jQuery environments
            regionSelect.addEventListener('change', function() {
                const regionId = this.value;
                if (!regionId) {
                    districtSelect.innerHTML = '<option value="">Select District</option>';
                    return;
                }
                fetch(`/get-districts/${regionId}`)
                    .then(response => response.json())
                    .then(data => {
                        districtSelect.innerHTML = '<option value="">Select District</option>';
                        Object.entries(data).forEach(([id, name]) => {
                            const option = document.createElement('option');
                            option.value = id;
                            option.textContent = name;
                            districtSelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error loading districts:', error));
            });
        }
    });

    // Image preview function
    function previewImage(event) {
        const file = event.target.files[0];
        const preview = document.getElementById('preview');

        if (file) {
            const reader = new FileReader();
            reader.onload = function (e) {
                preview.innerHTML = `<img src="${e.target.result}" width="100" class="mt-2">`;
            }
            reader.readAsDataURL(file);
        }
    }


    // Add/remove filetype-document upload rows
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('file-type-upload-container');
        const addBtn = document.getElementById('add-filetype-row');

        // Ensure there's always at least one row for new customers
        if (!container.querySelector('.file-type-upload-row')) {
            addBtn.click(); // This will add the first row
        }

        addBtn.addEventListener('click', function() {
            const row = document.querySelector('.file-type-upload-row');
            const newRow = row.cloneNode(true);

            // Clear values
            newRow.querySelector('select').selectedIndex = 0;
            newRow.querySelector('input[type="file"]').value = '';

            container.appendChild(newRow);
        });

        container.addEventListener('click', function(e) {
            if (e.target.closest('.remove-filetype-row')) {
                const rows = container.querySelectorAll('.file-type-upload-row');
                if (rows.length > 1) {
                    e.target.closest('.file-type-upload-row').remove();
                }
            }
        });
    });
</script>
