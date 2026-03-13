@extends('layouts.main')
@section('title', 'Edit Complain Category')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Complain Categories', 'url' => route('settings.complain-categories.index'), 'icon' => 'bx bx-list-ul'],
            ['label' => 'Edit', 'url' => '#', 'icon' => 'bx bx-edit']
        ]" />
        <h6 class="mb-0 text-uppercase">EDIT COMPLAIN CATEGORY</h6>
        <hr/>
        <div class="card">
            <div class="card-body">
                @include('settings.complain-categories.form', ['complainCategory' => $complainCategory])
            </div>
        </div>
    </div>
</div>
@endsection
