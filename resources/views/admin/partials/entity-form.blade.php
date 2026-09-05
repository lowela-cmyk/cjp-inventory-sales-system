<div class="modal-card">
    <div class="form-grid">
        @foreach ($fields as $field)
            <div class="form-row">
                <label>{{ $field }}</label>
                <input type="{{ str_contains($field, 'Email') ? 'email' : (str_contains($field, 'Contact') ? 'tel' : 'text') }}" placeholder="Enter {{ $field }}">
            </div>
        @endforeach
    </div>
</div>
<div class="modal-actions">
    <button class="btn btn-pill btn-secondary" type="submit">Add</button>
    <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
</div>
