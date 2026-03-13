@php
    $showUrl = route('accounting.bank-accounts.show', $encodedId);
    $editUrl = route('accounting.bank-accounts.edit', $encodedId);
    $deleteUrl = route('accounting.bank-accounts.destroy', $encodedId);
    $isLocked = $bankAccount->glTransactions()->exists();
@endphp

@can('view bank  account details')
    <a href="{{ $showUrl }}" class="btn btn-sm btn-info">View</a>
@endcan

@can('edit bank account')
    <a href="{{ $editUrl }}" class="btn btn-sm btn-primary">Edit</a>
@endcan

@can('delete bank account')
    @if($isLocked)
        <button class="btn btn-sm btn-outline-secondary" title="Bank account's chart account is used in GL Transactions and cannot be deleted" disabled>
            <i class="bx bx-lock"></i> Locked
        </button>
    @else
        <form action="{{ $deleteUrl }}" method="POST" class="d-inline delete-form">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm btn-danger" data-name="{{ $bankAccount->name }}">Delete</button>
        </form>
    @endif
@endcan

