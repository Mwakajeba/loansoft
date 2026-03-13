@extends('layouts.main')

@section('title', 'Create Announcement')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Announcements', 'url' => route('settings.announcements.index'), 'icon' => 'bx bx-megaphone'],
            ['label' => 'Create', 'url' => '#', 'icon' => 'bx bx-plus']
        ]" />
        <h6 class="mb-0 text-uppercase">CREATE ANNOUNCEMENT</h6>
        <hr/>

        <div class="card radius-10">
            <div class="card-body">
                <form action="{{ route('settings.announcements.store') }}" method="POST" enctype="multipart/form-data">
                    @include('settings.announcements.form')
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

