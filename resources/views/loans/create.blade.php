@extends('layouts.main')
@section('title', 'Create Loan')

@section('content')
<div class="page-wrapper">
    <div class="page-content"> 
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.list'), 'icon' => 'bx bx-money'],
            ['label' => 'Create Loan', 'url' => '#', 'icon' => 'bx bx-plus']
        ]" />
        <h6 class="mb-0 text-uppercase">CREATE NEW LOAN</h6>
        <hr/>
        
        <div class="row">
            <!-- Left Column: Form -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        @include('loans.form')
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Guidelines -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">
                            <i class="bx bx-info-circle me-2"></i>Loan Creation Guidelines
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h6 class="fw-bold text-primary mb-3">
                                <i class="bx bx-check-circle me-2"></i>Required Information
                            </h6>
                            <ul class="list-unstyled ms-3">
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Customer:</strong> Select an existing customer from the list
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Loan Product:</strong> Choose the appropriate loan product
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Amount:</strong> Enter amount with comma separators (e.g., 1,000,000)
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Interest Rate:</strong> Must be within product limits
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Period:</strong> Loan duration in months
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Date Disbursed:</strong> Cannot be a future date
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Loan Officer:</strong> Assign responsible officer
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Bank Account:</strong> Select disbursement account
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-check text-success me-2"></i>
                                    <strong>Sector:</strong> Choose business sector
                                </li>
                            </ul>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold text-warning mb-3">
                                <i class="bx bx-error-circle me-2"></i>Important Notes
                            </h6>
                            <ul class="list-unstyled ms-3">
                                <li class="mb-2">
                                    <i class="bx bx-info-circle text-warning me-2"></i>
                                    Amount must be within the product's min/max limits
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-info-circle text-warning me-2"></i>
                                    Interest rate must match product specifications
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-info-circle text-warning me-2"></i>
                                    Period must be within allowed range for the product
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-info-circle text-warning me-2"></i>
                                    Group is automatically assigned based on customer
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-info-circle text-warning me-2"></i>
                                    Repayment schedule will be generated automatically
                                </li>
                            </ul>
                        </div>

                        <div class="mb-4">
                            <h6 class="fw-bold text-info mb-3">
                                <i class="bx bx-help-circle me-2"></i>Tips
                            </h6>
                            <ul class="list-unstyled ms-3">
                                <li class="mb-2">
                                    <i class="bx bx-bulb text-info me-2"></i>
                                    Use the loan calculator to estimate payments
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-bulb text-info me-2"></i>
                                    Verify customer details before submission
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-bulb text-info me-2"></i>
                                    Check product limits before entering amounts
                                </li>
                                <li class="mb-2">
                                    <i class="bx bx-bulb text-info me-2"></i>
                                    Review all information before submitting
                                </li>
                            </ul>
                        </div>

                        <div class="alert alert-light border">
                            <h6 class="fw-bold mb-2">
                                <i class="bx bx-time me-2"></i>Quick Reference
                            </h6>
                            <p class="mb-1 small">
                                <strong>Amount Format:</strong> Use commas for readability<br>
                                <span class="text-muted">Example: 1,000,000</span>
                            </p>
                            <p class="mb-0 small">
                                <strong>Date Format:</strong> YYYY-MM-DD<br>
                                <span class="text-muted">Example: 2024-01-15</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection