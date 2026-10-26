{{-- Part 11: Delete a Scheduled lift (Schedule table and View modal footer). --}}
<form class="lift-delete-form" method="POST" action="{{ route($liftRoutePrefix.'.hauls.destroy', $row['haul_id']) }}" data-confirm-message="Delete lift {{ $row['cells'][0] }}? This can't be undone.">
    @csrf
    @method('DELETE')
    <button class="btn btn-danger-outline{{ ($pill ?? false) ? ' btn-pill' : '' }}" type="submit">Delete</button>
</form>
