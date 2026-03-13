@extends('layouts.main')

@section('title', __('app.user_management'))

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'User Management', 'url' => '#', 'icon' => 'bx bx-user']
        ]" />

            <div class="row row-cols-1 row-cols-lg-4">
                <div class="col">
                    <div class="card radius-10">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <p class="mb-0">{{ __('app.total_users') }}</p>
                                    <h4 class="font-weight-bold">{{ $totalUsers }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-cosmic text-white"><i class='bx bx-user'></i>
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
                                    <p class="mb-0">{{ __('app.active_users') }}</p>
                                    <h4 class="font-weight-bold">{{ $activeUsers }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-burning text-white"><i class='bx bx-check-circle'></i>
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
                                    <p class="mb-0">{{ __('app.inactive_users') }}</p>
                                    <h4 class="font-weight-bold">{{ $inactiveUsers }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-lush text-white"><i class='bx bx-time'></i>
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
                                    <p class="mb-0">{{ __('app.this_month') }}</p>
                                    <h4 class="font-weight-bold">
                                        {{ $users->where('created_at', '>=', now()->startOfMonth())->count() }}</h4>
                                </div>
                                <div class="widgets-icons bg-gradient-kyoto text-white"><i class='bx bx-calendar'></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!--end row-->

            <h6 class="mb-0 text-uppercase">{{ __('app.users') }}</h6>
            <hr />
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">{{ __('app.user_list') }}</h5>
                        @can('create user')
                        <a href="{{ route('users.create') }}" class="btn btn-primary">
                            <i class="bx bx-plus"></i> {{ __('app.add_new_user') }}
                        </a>
                        @endcan
                    </div>


                <div class="table-responsive">
                    <table id="example2" class="table table-striped table-bordered" style="width:100%">
                        <thead>
                             <tr>
                                <th>{{ __('app.name') }}</th>
                                <th>{{ __('app.email') }}</th>
                                <th>{{ __('app.phone') }}</th>
                                <th>{{ __('app.branch') }}</th>
                                <th>{{ __('app.roles') }}</th>
                                <th>{{ __('app.status') }}</th>
                                <th>{{ __('app.created_at') }}</th>
                                <th>{{ __('app.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                          @foreach($users as $user)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar avatar-sm bg-primary rounded-circle me-2 d-flex align-items-center justify-content-center shadow" style="width:36px; height:36px;">
                                            <span class="avatar-title text-white fw-bold" style="font-size:1.25rem;">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                                        </div>
                                        @can('view user profile')
                                        <div>
                                            <div class="fw-bold">
                                                <a href="{{ route('users.show', $user) }}" class="text-decoration-none">{{ $user->name }}</a>
                                            </div>
                                        </div>
                                        @endcan
                                    </div>
                                </td>
                                <td>{{ $user->email }}</td>
                                <td>{{ $user->phone }}</td>
                                <td>{{ $user->branch->name ?? __('app.not_available') }}</td>
                                <td>
                                    @foreach($user->roles as $role)
                                        <span class="badge bg-primary me-1">{{ $role->name }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    @if($user->status === 'active')
                                        <span class="badge bg-success">{{ __('app.active') }}</span>
                                    @elseif($user->status === 'inactive')
                                        <span class="badge bg-warning">{{ __('app.inactive') }}</span>
                                    @else
                                        <span class="badge bg-danger">{{ __('app.suspended') }}</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at->format('M d, Y') }}</td>
                                <td>
                                    @can('view user profile')
                                    <a href="{{ route('users.show', $user) }}" class="btn btn-sm btn-outline-info" title="View Profile"><i class="bx bx-show"></i></a>
                                    @endcan

                                    @can('edit user')
                                    <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bx bx-edit"></i></a>
                                    @endcan
                                    @can('delete user')
                                    @php
                                        $hasGL = \App\Models\GlTransaction::where('user_id', $user->id)->exists();
                                    @endphp
                                    @if($hasGL)
                                        <button class="btn btn-sm btn-outline-danger" title="Cannot delete: User has GL transactions." disabled><i class="bx bx-lock"></i></button>
                                    @else
                                    <form action="{{ route('users.destroy', $user) }}" method="POST" style="display:inline-block;" class="delete-form" data-user-name="{{ $user->name }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bx bx-trash"></i></button>
                                    </form>
                                    @endif
                                    @endcan
                                </td>
                            </tr>
                          @endforeach
                        </tbody>
                    </table>
                </div>

                    <!-- Pagination -->
                    @if($users->hasPages())
                        <div class="row mt-4">
                            <div class="col-12">
                                {{ $users->links() }}
                            </div>
                        </div>
                    @endif
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
        <p class="mb-0">{{ __('app.copyright') }} © {{ date('Y') }}. {{ __('app.all_rights_reserved') }} --
            {{ __('app.by_safco_fintech') }}</p>
    </footer>



@endsection

@push('scripts')
<script>
$(function() {
    if ($.fn.DataTable.isDataTable('#example2')) {
        $('#example2').DataTable().destroy();
    }
    $('#example2').DataTable({
        responsive: true,
        paging: true,
        searching: true,
        ordering: true,
        info: true,
        lengthChange: true,
        pageLength: 10,
        language: {
            search: "",
            searchPlaceholder: "Search users..."
        }
    });
});
</script>
<script>
// Delete user functionality with SweetAlert
$(document).on('submit', '.delete-form', function(e) {
    const $form = $(this);
    
    // Check if this submission is already confirmed
    if ($form.data('confirmed') === true) {
        return true; // Allow form to submit
    }
    
    e.preventDefault();
    e.stopPropagation();
    
    const userName = $form.data('user-name') || 'this user';
    
    Swal.fire({
        title: 'Delete "' + userName + '"?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete!',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Mark as confirmed and submit
            $form.data('confirmed', true);
            $form.submit();
        }
    });
    
    return false;
});
</script>
@endpush