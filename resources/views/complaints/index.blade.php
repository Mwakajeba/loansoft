@extends('layouts.main')

@section('title', 'Malalamiko (Complaints)')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Malalamiko', 'url' => '#', 'icon' => 'bx bx-error-circle']
        ]" />
        <h6 class="mb-0 text-uppercase">MALALAMIKO (COMPLAINTS)</h6>
        <hr/>

        <div class="card radius-10">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <h4 class="card-title mb-0">All Complaints</h4>
                    <form action="{{ route('complaints.index') }}" method="GET" class="d-flex gap-2">
                        <select name="status" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Inasubiri</option>
                            <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>Imekwisha</option>
                        </select>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($complaints as $c)
                                <tr>
                                    <td>{{ $c->created_at->format('d/m/Y H:i') }}</td>
                                    <td>
                                        <a href="{{ route('customers.show', \Vinkla\Hashids\Facades\Hashids::encode($c->customer_id)) }}">
                                            {{ $c->customer->name ?? 'N/A' }}
                                        </a>
                                    </td>
                                    <td>{{ $c->category->name ?? 'N/A' }}</td>
                                    <td>{{ Str::limit($c->description, 50) }}</td>
                                    <td>
                                        @if($c->status === 'pending')
                                            <span class="badge bg-warning">Inasubiri</span>
                                        @else
                                            <span class="badge bg-success">Imekwisha</span>
                                        @endif
                                    </td>
                                    <td>{{ $c->response ? Str::limit($c->response, 40) : '—' }}</td>
                                    <td>
                                        <a href="{{ route('complaints.edit', $c) }}" class="btn btn-sm btn-outline-primary">Jibu</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No complaints yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-center mt-3">
                    {{ $complaints->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
