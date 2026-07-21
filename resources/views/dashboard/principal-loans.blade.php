@php
    use Vinkla\Hashids\Facades\Hashids;
@endphp

@extends('layouts.main')

@section('title', 'Dashboard Total Principal Loans')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard', array_filter(['branch_id' => $selectedBranchId])), 'icon' => 'bx bx-home'],
                ['label' => 'Total Principal Loans', 'url' => '#', 'icon' => 'bx bx-money'],
            ]" />

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h6 class="mb-1 text-uppercase">Dashboard Total Principal — Loan Breakdown</h6>
                    <p class="text-muted mb-0">
                        Branch: <strong>{{ $branchName }}</strong>
                        · Statuses: active, written off, defaulted, completed, complete top-up
                    </p>
                </div>
                <form method="GET" action="{{ route('dashboard.principal-loans') }}" class="d-flex align-items-center gap-2">
                    <label for="branch_id" class="form-label mb-0 text-nowrap"><strong>Branch:</strong></label>
                    <select name="branch_id" id="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($selectedBranchId == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
                <div class="col">
                    <div class="card radius-10 border-primary border-start border-3">
                        <div class="card-body">
                            <p class="mb-0 text-muted">Total Principal</p>
                            <h4 class="mb-0">TZS {{ number_format($totalPrincipal, 2) }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <p class="mb-0 text-muted">Total Interest</p>
                            <h4 class="mb-0">TZS {{ number_format($totalInterest, 2) }}</h4>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <p class="mb-0 text-muted">Loans Count</p>
                            <h4 class="mb-0">{{ number_format($loans->count()) }}</h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card radius-10">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered align-middle mb-0" id="principalLoansTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Loan No</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Branch</th>
                                    <th>Bank</th>
                                    <th class="text-end">Principal</th>
                                    <th class="text-end">Interest</th>
                                    <th class="text-end">Total</th>
                                    <th>Status</th>
                                    <th>Disbursed</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($loans as $index => $loan)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $loan->loanNo }}</td>
                                        <td>{{ $loan->customer->name ?? '—' }}</td>
                                        <td>{{ $loan->product->name ?? '—' }}</td>
                                        <td>{{ $loan->branch->name ?? '—' }}</td>
                                        <td>{{ $loan->bankAccount->name ?? '—' }}</td>
                                        <td class="text-end">{{ number_format($loan->amount, 2) }}</td>
                                        <td class="text-end">{{ number_format($loan->interest_amount ?? 0, 2) }}</td>
                                        <td class="text-end">{{ number_format($loan->amount_total ?? 0, 2) }}</td>
                                        <td>
                                            <span class="badge bg-secondary text-uppercase">{{ str_replace('_', ' ', $loan->status) }}</span>
                                        </td>
                                        <td>{{ $loan->disbursed_on ? \Carbon\Carbon::parse($loan->disbursed_on)->format('M d, Y') : '—' }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('loans.show', Hashids::encode($loan->id)) }}" class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center text-muted py-4">No loans found for this filter.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($loans->isNotEmpty())
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="6" class="text-end">Totals</td>
                                        <td class="text-end">{{ number_format($totalPrincipal, 2) }}</td>
                                        <td class="text-end">{{ number_format($totalInterest, 2) }}</td>
                                        <td class="text-end">{{ number_format($loans->sum('amount_total'), 2) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function () {
        if ($.fn.DataTable && $('#principalLoansTable tbody tr').length > 0 && $('#principalLoansTable tbody tr td').length > 1) {
            $('#principalLoansTable').DataTable({
                order: [[10, 'desc']],
                pageLength: 25,
                dom: 'Bfrtip',
                buttons: ['copy', 'excel', 'pdf', 'print'],
            });
        }
    });
</script>
@endpush
