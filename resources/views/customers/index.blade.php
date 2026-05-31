@extends('layouts.main')

@section('title', 'Customer Management')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Customers', 'url' => '#', 'icon' => 'bx bx-group']
             ]" />
        <h6 class="mb-0 text-uppercase">CUSTOMER LIST</h6>
        <hr />

        <!-- Dashboard Stats -->
        <div class="row row-cols-1 row-cols-lg-4" id="customerStatsCards">
            <div class="col mb-4">
                <div class="card radius-10 customer-filter-card active" role="button" data-filter="all" title="Show all customers">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Total Customers</p>
                            <h4 class="mb-0">{{ $customerCount ?? 0 }}</h4>
                        </div>
                        <div class="widgets-icons bg-gradient-burning text-white"><i class='bx bx-group'></i></div>
                    </div>
                </div>
            </div>
            <div class="col mb-4">
                <div class="card radius-10 customer-filter-card" role="button" data-filter="borrower" title="Show borrowers only">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Total Borrowers</p>
                            <h4 class="mb-0">{{ $borrowerCount ?? 0 }}</h4>
                        </div>
                        <div class="widgets-icons bg-gradient-burning text-white"><i class='bx bx-user'></i></div>
                    </div>
                </div>
            </div>
            <div class="col mb-4">
                <div class="card radius-10 customer-filter-card" role="button" data-filter="guarantor" title="Show guarantors only">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Total Guarantors</p>
                            <h4 class="mb-0">{{ $guarantorCount ?? 0 }}</h4>
                        </div>
                        <div class="widgets-icons bg-gradient-burning text-white"><i class='bx bx-shield'></i></div>
                    </div>
                </div>
            </div>
            <div class="col mb-4">
                <div class="card radius-10 customer-filter-card" role="button" data-filter="today" title="Show customers added today">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Today New Customers</p>
                            <h4 class="mb-0">{{ $todayNewCustomerCount ?? 0 }}</h4>
                        </div>
                        <div class="widgets-icons bg-gradient-success text-white"><i class='bx bx-user-plus'></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="row">
            <div class="col-12">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h6 class="card-title mb-0">
                                Customers List
                                <span id="customerFilterBadge" class="badge bg-primary ms-2 d-none"></span>
                            </h6>
                            <div>
                                @can('create customer')
                                <a href="{{ route('customers.bulk-upload') }}" class="btn btn-success me-2">
                                    <i class="bx bx-upload"></i> Bulk Upload
                                </a>
                                <a href="{{ route('customers.create') }}" class="btn btn-primary">
                                    <i class="bx bx-plus"></i> Add Customer
                                </a>
                                @endcan
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped nowrap" id="customersTable">
                                <thead>
                                    <tr>
                                        <th>Customer No</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Region</th>
                                        <th>District</th>
                                        <th>Branch</th>
                                        <th>Category</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Data will be loaded via Ajax -->
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@push('styles')
<style>
    /* Custom DataTables styling */
    .dataTables_processing {
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        font-size: 16px;
        z-index: 9999;
    }
    
    .dataTables_length label,
    .dataTables_filter label {
        font-weight: 500;
        margin-bottom: 0;
    }
    
    .dataTables_filter input {
        border-radius: 6px;
        border: 1px solid #ddd;
        padding: 8px 12px;
        margin-left: 8px;
    }
    
    .table-responsive .table {
        margin-bottom: 0;
    }
    
    .avatar {
        flex-shrink: 0;
    }

    .customer-filter-card {
        cursor: pointer;
        transition: box-shadow 0.2s ease, transform 0.15s ease;
    }

    .customer-filter-card:hover {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.12);
        transform: translateY(-2px);
    }

    .customer-filter-card.active {
        outline: 2px solid var(--bs-primary);
        outline-offset: 2px;
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        var currentFilter = 'all';
        var filterLabels = {
            all: 'All customers',
            borrower: 'Borrowers',
            guarantor: 'Guarantors',
            today: 'New today'
        };

        function updateFilterUi(filter) {
            currentFilter = filter;
            $('.customer-filter-card').removeClass('active');
            $('.customer-filter-card[data-filter="' + filter + '"]').addClass('active');

            var badge = $('#customerFilterBadge');
            if (filter === 'all') {
                badge.addClass('d-none');
            } else {
                badge.removeClass('d-none').text(filterLabels[filter] || filter);
            }
        }

        // Initialize DataTable with Ajax
        var table = $('#customersTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("customers.data") }}',
                type: 'GET',
                data: function(d) {
                    d.filter = currentFilter;
                },
                error: function(xhr, error, code) {
                    console.error('DataTables Ajax Error:', error, code);
                    Swal.fire({
                        title: 'Error!',
                        text: 'Failed to load customers data. Please refresh the page.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                }
            },
            columns: [
                { data: 'customerNo', name: 'customerNo', title: 'Customer No' },
                { data: 'avatar_name', name: 'name', title: 'Name', orderable: true, searchable: true },
                { data: 'phone1', name: 'phone1', title: 'Phone' },
                { data: 'region_name', name: 'region.name', title: 'Region' },
                { data: 'district_name', name: 'district.name', title: 'District' },
                { data: 'branch_name', name: 'branch.name', title: 'Branch' },
                { data: 'category', name: 'category', title: 'Category' },
                { data: 'actions', name: 'actions', title: 'Actions', orderable: false, searchable: false }
            ],
            responsive: true,
            order: [[1, 'asc']],
            pageLength: 10,
            engthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            language: {
                search: "",
                searchPlaceholder: "Search customers...",
                processing: '<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>',
                emptyTable: "No customers found",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                infoEmpty: "Showing 0 to 0 of 0 customers",
                infoFiltered: "(filtered from _MAX_ total customers)",
                lengthMenu: "Show _MENU_ customers per page",
                zeroRecords: "No matching customers found"
            },
            columnDefs: [
                {
                    targets: -1, // Actions column
                    orderable: false,
                    searchable: false,
                    className: 'text-center'
                },
                {
                    targets: [0, 1, 2], // Priority columns for responsive
                    responsivePriority: 2
                }
            ],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
            drawCallback: function(settings) {
                // Reinitialize tooltips after each draw
                $('[data-bs-toggle="tooltip"]').tooltip();
            }
        });

        // Handle delete button clicks
        $('#customersTable').on('click', '.delete-btn', function(e) {
            e.preventDefault();
            
            var customerId = $(this).data('id');
            var customerName = $(this).data('name');
            
            Swal.fire({
                title: 'Are you sure?',
                text: `You want to delete customer "${customerName}"? This action cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form to submit the delete request
                    var form = $('<form>', {
                        'method': 'POST',
                        'action': '{{ route("customers.destroy", ":id") }}'.replace(':id', customerId)
                    });
                    
                    var csrfToken = $('<input>', {
                        'type': 'hidden',
                        'name': '_token',
                        'value': '{{ csrf_token() }}'
                    });
                    
                    var methodField = $('<input>', {
                        'type': 'hidden',
                        'name': '_method',
                        'value': 'DELETE'
                    });
                    
                    form.append(csrfToken, methodField);
                    $('body').append(form);
                    
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        text: 'Please wait while we delete the customer.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    form.submit();
                }
            });
        });

        // Filter by stat card click
        $('.customer-filter-card').on('click', function() {
            var filter = $(this).data('filter');
            if (filter === currentFilter) {
                return;
            }
            updateFilterUi(filter);
            table.ajax.reload();
        });

        // Refresh table data function
        window.refreshCustomersTable = function() {
            table.ajax.reload(null, false);
        };
    });
</script>
@endpush