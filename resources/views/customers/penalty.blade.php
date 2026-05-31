@extends('layouts.main')

@section('title', 'Customer Penalty List')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Penalty List', 'url' => '#', 'icon' => 'bx bx-error']
        ]" />
        <h6 class="mb-0 text-uppercase">CUSTOMER PENALTY LIST</h6>
        <hr />

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card radius-10 border-start border-0 border-4 border-success">
                    <div class="card-body">
                        <p class="mb-1 text-muted">Outstanding Penalties (Loans)</p>
                        <h5 class="mb-0 text-success">TZS {{ number_format($penaltyBalance, 2) }}</h5>
                        <small class="text-muted">From active loan schedules minus repayments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card radius-10 border-start border-0 border-4 border-primary">
                    <div class="card-body">
                        <p class="mb-1 text-muted">GL Penalty Receivable</p>
                        <h5 class="mb-0 text-primary">TZS {{ number_format($penaltyGlBalance, 2) }}</h5>
                        <small class="text-muted">Debit − credit on penalty receivable accounts</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card radius-10 border-start border-0 border-4 {{ abs($penaltyDifference) > 0.01 ? 'border-warning' : 'border-secondary' }}">
                    <div class="card-body">
                        <p class="mb-1 text-muted">Difference (GL − Outstanding)</p>
                        <h5 class="mb-0 {{ abs($penaltyDifference) > 0.01 ? 'text-warning' : 'text-secondary' }}">
                            TZS {{ number_format($penaltyDifference, 2) }}
                        </h5>
                        <small class="text-muted">
                            @if(abs($penaltyDifference) > 0.01)
                                Orphan or duplicate GL entries may need cleanup
                            @else
                                GL matches loan outstanding penalties
                            @endif
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered nowrap" id="customersTable">
                                <thead>
                                    <tr>
                                        <th>Customer Name</th>
                                        <th>Phone</th>
                                        <th class="text-end">Outstanding Penalty</th>
                                        <th class="text-end">GL Balance</th>
                                        <th class="text-end">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($customerPenalties as $penaltyItem)
                                    <tr>
                                        <td>{{ $penaltyItem['customer_name'] }}</td>
                                        <td>{{ $penaltyItem['customer_phone'] }}</td>
                                        <td class="text-end">{{ number_format($penaltyItem['penalty_balance'], 2) }}</td>
                                        <td class="text-end">{{ number_format($penaltyItem['gl_balance'], 2) }}</td>
                                        <td class="text-end {{ abs($penaltyItem['difference']) > 0.01 ? 'text-warning fw-semibold' : '' }}">
                                            {{ number_format($penaltyItem['difference'], 2) }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="2" class="text-end">Totals</td>
                                        <td class="text-end">{{ number_format($penaltyBalance, 2) }}</td>
                                        <td class="text-end">{{ number_format($penaltyGlBalance, 2) }}</td>
                                        <td class="text-end">{{ number_format($penaltyDifference, 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#customersTable').DataTable({
            responsive: false,
            order: [[0, 'asc']],
            pageLength: 10,
            language: {
                search: '',
                searchPlaceholder: 'Search customers...'
            },
            columnDefs: [
                { targets: [2, 3, 4], className: 'text-end' }
            ]
        });
    });
</script>
@endpush
