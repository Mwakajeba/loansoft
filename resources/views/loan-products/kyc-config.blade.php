@php
use Vinkla\Hashids\Facades\Hashids;
@endphp

@extends('layouts.main')

@section('title', 'KYC Configuration - ' . $loanProduct->name)

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loan Products', 'url' => route('loan-products.index'), 'icon' => 'bx bx-credit-card'],
            ['label' => $loanProduct->name, 'url' => route('loan-products.show', Hashids::encode($loanProduct->id)), 'icon' => 'bx bx-info-circle'],
            ['label' => 'KYC Configuration', 'url' => '#', 'icon' => 'bx bx-file']
        ]" />

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bx bx-file me-2"></i>KYC Configuration - {{ $loanProduct->name }}
                </h5>
                <a href="{{ route('loan-products.show', Hashids::encode($loanProduct->id)) }}" class="btn btn-sm btn-light">
                    <i class="bx bx-arrow-back"></i> Back to Product
                </a>
            </div>
            <div class="card-body">
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

                <p class="text-muted mb-4">
                    Select the file types (KYC documents) required for customers applying for this loan product.
                </p>

                <form action="{{ route('loan-products.kyc-config.update', Hashids::encode($loanProduct->id)) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        @forelse($allFiletypes as $filetype)
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="filetype_ids[]" 
                                           value="{{ $filetype->id }}" 
                                           id="filetype_{{ $filetype->id }}"
                                           {{ in_array($filetype->id, $selectedFiletypeIds) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="filetype_{{ $filetype->id }}">
                                        {{ $filetype->name }}
                                    </label>
                                </div>
                            </div>
                        @empty
                            <div class="col-12">
                                <div class="alert alert-info">
                                    <i class="bx bx-info-circle"></i> No file types available. Please create file types in Settings first.
                                </div>
                            </div>
                        @endforelse
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <a href="{{ route('loan-products.show', Hashids::encode($loanProduct->id)) }}" class="btn btn-secondary">
                            <i class="bx bx-x"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bx bx-save"></i> Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
