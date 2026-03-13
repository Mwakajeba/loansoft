@csrf

<div class="row">
    <div class="col-md-8 mb-3">
        <label class="form-label">Title <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
               value="{{ old('title', $announcement->title ?? '') }}" maxlength="255" required>
        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Active?</label>
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                   value="1" {{ old('is_active', $announcement->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Show in mobile app</label>
        </div>
        @error('is_active') <div class="text-danger small">{{ $message }}</div> @enderror
    </div>

    <div class="col-12 mb-3">
        <label class="form-label">Description</label>
        <textarea name="description" rows="4"
                  class="form-control @error('description') is-invalid @enderror"
                  placeholder="Short description shown in the app...">{{ old('description', $announcement->description ?? '') }}</textarea>
        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Publish Date <span class="text-danger">*</span></label>
        <input type="date" name="publish_date" class="form-control @error('publish_date') is-invalid @enderror"
               value="{{ old('publish_date', isset($announcement) && $announcement->publish_date ? $announcement->publish_date->format('Y-m-d') : now()->format('Y-m-d')) }}"
               required>
        @error('publish_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">End Date</label>
        <input type="date" name="end_date" class="form-control @error('end_date') is-invalid @enderror"
               value="{{ old('end_date', isset($announcement) && $announcement->end_date ? $announcement->end_date->format('Y-m-d') : '') }}">
        <small class="text-muted">Leave empty to show until manually disabled.</small>
        @error('end_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Image (max 5MB)</label>
        <input type="file" name="image" class="form-control @error('image') is-invalid @enderror" accept="image/*">
        @error('image') <div class="invalid-feedback">{{ $message }}</div> @enderror
        @isset($announcement)
            @if($announcement->image_path)
                <div class="mt-2">
                    <span class="text-muted d-block mb-1">Current image:</span>
                    <img src="{{ Storage::disk(config('upload.storage_disk', 'public'))->url($announcement->image_path) }}"
                         alt="Announcement Image"
                         style="width: 120px; height: 80px; object-fit: cover; border-radius: 4px;">
                </div>
            @endif
        @endisset
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-3">
    <a href="{{ route('settings.announcements.index') }}" class="btn btn-secondary">
        <i class="bx bx-arrow-back"></i> Cancel
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="bx bx-save"></i> {{ isset($announcement) ? 'Update' : 'Create' }} Announcement
    </button>
</div>

