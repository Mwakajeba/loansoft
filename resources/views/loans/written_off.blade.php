@extends('layouts.main')

@section('title', 'Written Off Loans')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.index'), 'icon' => 'bx bx-credit-card'],
            ['label' => 'Written Off Loans', 'url' => '#', 'icon' => 'bx bx-x-circle'],
        ]" />
        <h6 class="mb-0 text-uppercase">Written Off Loans</h6>
        <hr />
        <div class="card radius-10">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered dt-responsive nowrap table-striped" id="writtenOffLoansTable">
                        <thead>
                            <tr>
                                <th>Loan No</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Amount</th>
                                <th>Total</th>
                                <th>Branch</th>
                                <th>Date Applied</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            $('#writtenOffLoansTable').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                ajax: {
                    url: '{{ route("loans.writtenoff.data") }}',
                    type: 'GET'
                },
                columns: [
                    { data: 'loan_no', name: 'loan_no', orderable: true, searchable: true },
                    { data: 'customer_name', name: 'customer_name', orderable: true, searchable: true },
                    { data: 'product_name', name: 'product_name', orderable: true, searchable: true },
                    { data: 'formatted_amount', name: 'amount', orderable: true, searchable: true },
                    { data: 'formatted_total', name: 'amount_total', orderable: true, searchable: true },
                    { data: 'branch_name', name: 'branch_name', orderable: true, searchable: true },
                    { data: 'date_applied', name: 'date_applied', orderable: true, searchable: true },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'text-center' }
                ],
                order: [[6, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                language: {
                    search: "",
                    searchPlaceholder: "Search written off loans..."
                }
            });
        });
    </script>
@endpush

