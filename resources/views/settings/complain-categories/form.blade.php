@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">
        @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif
<form action="{{ isset($complainCategory) ? route('settings.complain-categories.update', $complainCategory) : route('settings.complain-categories.store') }}" method="POST">
@csrf
@if(isset($complainCategory))
@method('PUT')
@endif
<div class="mb-3">
    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', isset($complainCategory) ? $complainCategory->name : '') }}" placeholder="e.g. Mikopo, Huduma">
    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
</div>
<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea name="description" id="description" rows="2" class="form-control" placeholder="Optional description">{{ old('description', isset($complainCategory) ? $complainCategory->description : '') }}</textarea>
</div>
<div class="mb-3">
    <label for="priority" class="form-label">Priority</label>
    <input type="number" name="priority" id="priority" min="0" class="form-control" value="{{ old('priority', isset($complainCategory) ? $complainCategory->priority : 0) }}">
    <small class="text-muted">Higher number = shown first in app.</small>
</div>
<div class="d-flex justify-content-between">
    <a href="{{ route('settings.complain-categories.index') }}" class="btn btn-secondary"><i class="bx bx-arrow-back me-1"></i> Back</a>
    <button type="submit" class="btn btn-primary"><i class="bx bx-save me-1"></i> {{ isset($complainCategory) ? 'Update' : 'Save' }}</button>
</div>
</form>
