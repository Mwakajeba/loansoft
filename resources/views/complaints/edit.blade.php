@extends('layouts.main')

@section('title', 'Jibu Malalamiko')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Malalamiko', 'url' => route('complaints.index'), 'icon' => 'bx bx-error-circle'],
            ['label' => 'Jibu', 'url' => '#', 'icon' => 'bx bx-message-detail']
        ]" />
        <h6 class="mb-0 text-uppercase">JIBU MALALAMIKO</h6>
        <hr/>

        <div class="card radius-10">
            <div class="card-body">
                <div class="mb-4">
                    <p class="mb-1 text-muted small">Mteja</p>
                    <strong>{{ $complaint->customer->name ?? 'N/A' }}</strong>
                </div>
                <div class="mb-4">
                    <p class="mb-1 text-muted small">Aina</p>
                    <strong>{{ $complaint->category->name ?? 'N/A' }}</strong>
                </div>
                <div class="mb-4">
                    <p class="mb-1 text-muted small">Maelezo</p>
                    <p class="mb-0">{{ $complaint->description }}</p>
                </div>
                <div class="mb-2 text-muted small">{{ $complaint->created_at->format('d/m/Y H:i') }}</div>
                <hr/>

                <form action="{{ route('complaints.update', $complaint) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label for="status" class="form-label">Hali</label>
                        <select name="status" id="status" class="form-select" required>
                            <option value="pending" {{ old('status', $complaint->status) === 'pending' ? 'selected' : '' }}>Inasubiri</option>
                            <option value="resolved" {{ old('status', $complaint->status) === 'resolved' ? 'selected' : '' }}>Imekwisha</option>
                            <option value="closed" {{ old('status', $complaint->status) === 'closed' ? 'selected' : '' }}>Imefungwa</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="response" class="form-label">Jibu (maelezo ya ofisi)</label>
                        <textarea name="response" id="response" class="form-control" rows="5" placeholder="Andika jibu la ofisi hapa...">{{ old('response', $complaint->response) }}</textarea>
                        <small class="text-muted">Jibu hili litaonekana kwa mteja kwenye kichupo cha Malalamiko.</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Hifadhi</button>
                        <a href="{{ route('complaints.index') }}" class="btn btn-outline-secondary">Ghairi</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
