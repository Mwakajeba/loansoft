@extends('layouts.main')
@section('title', 'Bulk Upload Customers')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Customers', 'url' => route('customers.index'), 'icon' => 'bx bx-group'],
            ['label' => 'Bulk Upload', 'url' => '#', 'icon' => 'bx bx-upload']
        ]" />

            <h6 class="mb-0 text-uppercase">BULK UPLOAD CUSTOMERS</h6>
            <hr />

            <div class="row">
                <div class="col-12">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="row">
                                <!-- Sample Download Section -->
                                <div class="col-md-6 mb-4">
                                    <div class="card border-primary">
                                        <div class="card-header bg-primary text-white">
                                            <h6 class="mb-0"><i class="bx bx-download me-2"></i>Download Sample Excel</h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="text-muted mb-3">Download the sample Excel file with 100 sample customers. The file includes dropdowns for Sex, Region, and District.</p>
                                            <a href="{{ route('customers.download-sample') }}"
                                                class="btn btn-outline-primary">
                                                <i class="bx bx-download me-2"></i>Download Sample Excel (100 Customers)
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Instructions Section -->
                                <div class="col-md-6 mb-4">
                                    <div class="card border-info">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bx bx-info-circle me-2"></i>Instructions</h6>
                                        </div>
                                        <div class="card-body">
                                            <ul class="mb-0">
                                                <li>Download the sample Excel file first (includes 100 sample customers)</li>
                                                <li>Use dropdowns for Sex (M/F), Region, and District</li>
                                                <li>Delete instruction rows and sample data before uploading</li>
                                                <li>Upload Excel (.xlsx, .xls) or CSV (.csv) format</li>
                                                <li>Select cash deposit options if needed</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if($errors->any())
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="bx bx-error-circle me-2"></i>
                                    <strong>Upload failed!</strong> Please fix the following errors:
                                    <ul class="mb-0 mt-2">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            @if(session('upload_errors'))
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                    <i class="bx bx-warning me-2"></i>
                                    <strong>Upload completed with warnings!</strong> {{ session('failed_count', 0) }} row(s) had issues.
                                    @if(session('failed_export_key'))
                                        <div class="mt-3">
                                            <a href="{{ route('customers.download-failed-records', ['key' => session('failed_export_key')]) }}" 
                                               class="btn btn-sm btn-danger">
                                                <i class="bx bx-download me-1"></i>Download Failed Records (Excel)
                                            </a>
                                        </div>
                                    @endif
                                    <ul class="mb-0 mt-2">
                                        @foreach(session('upload_errors') as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            @if(session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="bx bx-check-circle me-2"></i>
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Upload Form -->
                            <form action="{{ route('customers.bulk-upload.store') }}" method="POST"
                                enctype="multipart/form-data" id="bulkUploadForm">
                                @csrf

                                <div class="row">
                                    <!-- File Upload -->
                                    <div class="col-md-12 mb-4">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0"><i class="bx bx-file me-2"></i>Upload Excel/CSV File</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label for="csv_file" class="form-label">Select Excel or CSV File <span
                                                            class="text-danger">*</span></label>
                                                    <input type="file" name="csv_file" id="csv_file"
                                                        class="form-control @error('csv_file') is-invalid @enderror"
                                                        accept=".xlsx,.xls,.csv" required>
                                                    <div class="form-text">Excel (.xlsx, .xls) or CSV (.csv) files are allowed. Maximum size: 10MB
                                                    </div>
                                                    @error('csv_file')
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                
                                                <!-- Progress Bar -->
                                                <div id="progressContainer" class="mt-3" style="display: none;">
                                                    <div class="d-flex justify-content-between mb-2">
                                                        <span id="progressText">Uploading...</span>
                                                        <span id="progressPercent">0%</span>
                                                    </div>
                                                    <div class="progress" style="height: 25px;">
                                                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                                             role="progressbar" style="width: 0%" aria-valuenow="0" 
                                                             aria-valuemin="0" aria-valuemax="100">
                                                            <span id="progressBarText">0%</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Cash Deposit Options -->
                                    <div class="col-md-12 mb-4">
                                        <div class="card">
                                            <div class="card-header">
                                                <h6 class="mb-0"><i class="bx bx-money me-2"></i>Cash Deposit Options
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                    <div class="form-check">
                                                            <input type="checkbox" class="form-check-input" value="1"
                                                                name="has_cash_collateral" id="has_cash_collateral">
                                                            <label class="form-check-label" for="has_cash_collateral">
                                                                Apply Cash Deposit to All Customers
                                                            </label>
                                                        </div>
                                                    </div>

                                                    <div class="col-md-6 mb-3" id="collateral-type-container">
                                                        <label class="form-label">Deposit Type</label>
                                                        <select name="collateral_type_id" class="form-select">
                                                            <option value="">Select Deposit Type</option>
                                                            @foreach($collateralTypes as $index => $type)
                                                                <option value="{{ $type->id }}" {{ $index === 0 ? 'selected' : '' }}>{{ $type->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Buttons -->
                                <div class="d-flex justify-content-between">
                                    <a href="{{ route('customers.index') }}" class="btn btn-secondary">
                                        <i class="bx bx-arrow-back me-1"></i> Back to Customers
                                    </a>
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="bx bx-upload me-1"></i>
                                        <span id="submitText">Upload Customers</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const checkbox = document.querySelector('#has_cash_collateral');
            const collateralContainer = document.querySelector('#collateral-type-container');
            const form = document.querySelector('#bulkUploadForm');
            const submitBtn = document.querySelector('#submitBtn');
            const submitText = document.querySelector('#submitText');

            // Show/hide collateral type
            function toggleCollateralField() {
                if (checkbox.checked) {
                    collateralContainer.style.display = 'block';
                } else {
                    collateralContainer.style.display = 'none';
                }
            }

            checkbox.addEventListener('change', toggleCollateralField);
            // Initialize the state on page load
            toggleCollateralField();

            // Handle form submission with progress bar
            form.addEventListener('submit', function (e) {
                const fileInput = document.getElementById('csv_file');
                const file = fileInput.files[0];
                
                if (!file) {
                    return;
                }
                
                // Show progress bar
                document.getElementById('progressContainer').style.display = 'block';
                submitBtn.disabled = true;
                submitText.textContent = 'Uploading...';
                submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i>Uploading...';
                
                // Simulate progress (actual progress would come from server via AJAX)
                let progress = 0;
                const progressInterval = setInterval(function() {
                    progress += 5;
                    if (progress > 90) {
                        clearInterval(progressInterval);
                        progress = 90; // Don't go to 100% until server responds
                    }
                    updateProgress(progress);
                }, 200);
                
                // Store interval to clear on form submit completion
                form.dataset.progressInterval = progressInterval;
            });
            
            function updateProgress(percent) {
                const progressBar = document.getElementById('progressBar');
                const progressBarText = document.getElementById('progressBarText');
                const progressPercent = document.getElementById('progressPercent');
                
                progressBar.style.width = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
                progressBarText.textContent = Math.round(percent) + '%';
                progressPercent.textContent = Math.round(percent) + '%';
            }
        });
    </script>
@endpush