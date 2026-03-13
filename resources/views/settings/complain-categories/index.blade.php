@extends('layouts.main')

@section('title', 'Complain Categories')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Complain Categories', 'url' => '#', 'icon' => 'bx bx-list-ul']
        ]" />
        <h6 class="mb-0 text-uppercase">COMPLAIN CATEGORIES (AINA ZA MALALAMIKO)</h6>
        <hr/>

        <div class="card radius-10">
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

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="card-title mb-0">List of Complain Categories</h4>
                    <a href="{{ route('settings.complain-categories.create') }}" class="btn btn-primary">
                        <i class="bx bx-plus"></i> Add Category
                    </a>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Priority</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($categories as $index => $cat)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $cat->name }}</td>
                                    <td>{{ Str::limit($cat->description, 60) }}</td>
                                    <td>{{ $cat->priority }}</td>
                                    <td class="text-center">
                                        <a href="{{ route('settings.complain-categories.edit', $cat) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                                        <form action="{{ route('settings.complain-categories.destroy', $cat) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this category?');">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No categories yet. Add one above.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
