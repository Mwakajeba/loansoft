@extends('layouts.main')

@section('title', 'Edit Announcement')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Announcements', 'url' => route('settings.announcements.index'), 'icon' => 'bx bx-megaphone'],
            ['label' => 'Edit', 'url' => '#', 'icon' => 'bx bx-edit']
        ]" />
        <h6 class="mb-0 text-uppercase">EDIT ANNOUNCEMENT</h6>
        <hr/>

        <div class="card radius-10">
            <div class="card-body">
                <form action="{{ route('settings.announcements.update', $announcement) }}" method="POST" enctype="multipart/form-data">
                    @method('PUT')
                    @include('settings.announcements.form', ['announcement' => $announcement])
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

