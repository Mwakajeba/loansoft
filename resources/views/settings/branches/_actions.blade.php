<a href="{{ $editUrl }}" class="btn btn-sm btn-primary me-1">
    <i class="bx bx-edit"></i>
</a>
<form action="{{ $deleteUrl }}" method="POST" style="display:inline-block;" class="delete-form">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-sm btn-danger" data-name="{{ $branch->branch_name }}">
        <i class="bx bx-trash"></i>
    </button>
</form>

