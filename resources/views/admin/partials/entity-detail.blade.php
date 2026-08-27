<div class="modal-card">
    <p class="detail-id">{{ $id }}</p>
    <div class="detail-grid">
        @foreach ($rows as $label => $value)
            <div class="detail-row">
                <div class="detail-label">{{ $label }}</div>
                <div class="detail-value">{{ $value }}</div>
            </div>
        @endforeach
    </div>
</div>
<div class="modal-actions">
    <button class="btn btn-pill btn-secondary" type="button">Edit</button>
    <button class="btn btn-pill btn-danger" type="button">Delete</button>
</div>
