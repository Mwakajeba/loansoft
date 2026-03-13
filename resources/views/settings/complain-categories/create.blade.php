@extends('layouts.main')
@section('title', 'Create Complain Category')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Complain Categories', 'url' => route('settings.complain-categories.index'), 'icon' => 'bx bx-list-ul'],
            ['label' => 'Create', 'url' => '#', 'icon' => 'bx bx-plus']
        ]" />
        <h6 class="mb-0 text-uppercase">CREATE COMPLAIN CATEGORY</h6>
        <hr/>
        <div class="card">
            <div class="card-body">
                @include('settings.complain-categories.form', ['complainCategory' => null])
            </div>
        </div>
    </div>
</div>
@endsection
