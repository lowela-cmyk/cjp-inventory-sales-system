{{-- Part 11: Delete a Scheduled lift (Schedule table and View modal footer). --}}
<form class="lift-delete-form" method="POST" action="{{ route($liftRoutePrefix.'.hauls.destroy', $row['haul_id']) }}"
    data-confirm="delete" data-confirm-action="Delete lift" data-confirm-typed="{{ $row['cells'][0] }}"
    data-confirm-item="{{ $row['cells'][0] }} ({{ collect([$row['cells'][1] ?? null, $row['cells'][9] ?? null, $row['cells'][4] ?? null])->filter(fn ($v) => $v && $v !== 'N/A')->implode(', ') }})"
    data-confirm-undo="This can't be undone. The lift and its schedule are removed."
    data-confirm-message="Delete lift {{ $row['cells'][0] }}? This can't be undone.">
    @csrf
    @method('DELETE')
    <button class="btn btn-danger-outline{{ ($pill ?? false) ? ' btn-pill' : '' }}" type="submit">Delete</button>
</form>
