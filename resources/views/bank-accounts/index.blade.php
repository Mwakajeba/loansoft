@extends('layouts.main')

@section('title', 'Bank Accounts')
@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'Bank Accounts', 'url' => '#', 'icon' => 'bx bx-bank']
            ]" />
            
            <div class="row row-cols-1 row-cols-lg-4">
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <p class="mb-0">Total Accounts</p>
                                    <h4 class="font-weight-bold">{{ $totalAccounts }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-cosmic text-white"><i class='bx bx-dollar'></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <p class="mb-0">Total Balance</p>
                                    <h4 class="font-weight-bold">{{ number_format($totalBalance ?? 0, 2) }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-cosmic text-white"><i class='bx bx-wallet'></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <p class="mb-0">Positive Balance</p>
                                    <h4 class="font-weight-bold text-success">{{ $positiveBalanceAccounts }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-success text-white"><i class='bx bx-trending-up'></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <p class="mb-0">Negative Balance</p>
                                    <h4 class="font-weight-bold text-danger">{{ $negativeBalanceAccounts }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-danger text-white"><i class='bx bx-trending-down'></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                <!--end row-->

                <h6 class="mb-0 text-uppercase">BANK ACCOUNTS</h6>
                <hr />
                <div class="card">
                    <div class="card-body">
                        @can('create bank account')
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Bank Accounts</h5>
                            <a href="{{ route('accounting.bank-accounts.create') }}" class="btn btn-primary">
                                <i class="bx bx-plus"></i> Add New Bank Account
                            </a>
                        </div>
                        @endcan
                        <div class="table-responsive">
                            <table id="bankAccountsTable" class="table table-striped table-bordered" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bank Name</th>
                                        <th>Account Number</th>
                                        <th>Chart Account</th>
                                        <th>Account Class</th>
                                        <th>Account Group</th>
                                        <th>Balance</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!--end page wrapper -->
        <!--start overlay-->
        <div class="overlay toggle-icon"></div>
        <!--end overlay-->
        <!--Start Back To Top Button--> <a href="javaScript:;" class="back-to-top"><i class='bx bxs-up-arrow-alt'></i></a>
        <!--End Back To Top Button-->
        <footer class="page-footer">
            <p class="mb-0">Copyright © 2021. All right reserved.</p>
        </footer>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            const table = $('#bankAccountsTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route('accounting.bank-accounts.data') }}',
                order: [[0, 'asc']],
                columns: [
                    { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
                    { data: 'name', name: 'name' },
                    { data: 'account_number', name: 'account_number' },
                    { data: 'chart_account', name: 'chart_account', orderable: false },
                    { data: 'account_class', name: 'account_class', orderable: false },
                    { data: 'account_group', name: 'account_group', orderable: false },
                    { data: 'balance_display', name: 'balance', orderable: false, searchable: false },
                    { data: 'created_at', name: 'created_at' },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false },
                ],
                language: {
                    emptyTable: 'No bank accounts found.',
                }
            });

            // Delete confirmation (delegated for dynamically loaded rows)
            $(document).on('submit', '.delete-form', function (e) {
                e.preventDefault();
                const form = this;
                const name = $(form).find('button[type="submit"]').data('name');

                Swal.fire({
                    title: 'Are you sure?',
                    text: `Do you want to delete "${name}"?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            });
        });
    </script>
@endpush