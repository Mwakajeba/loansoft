@extends('layouts.main')

@section('title', 'Announcements')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Announcements', 'url' => '#', 'icon' => 'bx bx-megaphone']
        ]" />
        <h6 class="mb-0 text-uppercase">ANNOUNCEMENTS</h6>
        <hr/>

        <div class="card radius-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="card-title mb-0">List of Announcements</h4>
                    <a href="{{ route('settings.announcements.create') }}" class="btn btn-primary">
                        <i class="bx bx-plus"></i> Add Announcement
                    </a>
                </div>

                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bx bx-check-circle me-2"></i>
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bx bx-error-circle me-2"></i>
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th>Publish Date</th>
                                <th>End Date</th>
                                <th>Active Window</th>
                                <th>Image</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($announcements as $index => $announcement)
                                @php
                                    $now = \Carbon\Carbon::now();
                                    $isWithinWindow = $announcement->publish_date->isSameDay($now) ||
                                        ($announcement->publish_date->lessThanOrEqualTo($now)
                                         && (is_null($announcement->end_date) || $announcement->end_date->greaterThanOrEqualTo($now)));
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $announcement->title }}</td>
                                    <td>{{ $announcement->publish_date->format('Y-m-d') }}</td>
                                    <td>{{ $announcement->end_date ? $announcement->end_date->format('Y-m-d') : '—' }}</td>
                                    <td>
                                        @if($isWithinWindow)
                                            <span class="badge bg-success">Currently Visible</span>
                                        @else
                                            <span class="badge bg-secondary">Outside Window</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($announcement->image_path)
                                            <img src="{{ Storage::disk(config('upload.storage_disk', 'public'))->url($announcement->image_path) }}"
                                                 alt="Image"
                                                 style="width: 60px; height: 40px; object-fit: cover; border-radius: 4px;">
                                        @else
                                            <span class="text-muted">No Image</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($announcement->is_active)
                                            <span class="badge bg-primary">Active</span>
                                        @else
                                            <span class="badge bg-light text-muted">Disabled</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="{{ route('settings.announcements.edit', $announcement) }}" class="btn btn-sm btn-outline-warning">
                                            Edit
                                        </a>
                                        <form action="{{ route('settings.announcements.destroy', $announcement) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Are you sure you want to delete this announcement?')">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No announcements found.</td>
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

