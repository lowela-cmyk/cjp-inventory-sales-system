@php
    $summary = $batch->validation_summary ?? [];
    $sheets = $batch->workbook_summary['worksheets'] ?? [];
    $worksheetValidation = $summary['worksheets'] ?? [];
    $needsMappingEdit = collect($batch->mapping_summary ?? [])->contains(fn ($mapping) => is_array($mapping) && ($mapping['needs_edit'] ?? false));
    $recognizedFieldCount = collect($batch->mapping_summary ?? [])->filter(fn ($mapping) => is_array($mapping) && isset($mapping['columns']))->sum(fn ($mapping) => collect($mapping['columns'])->filter()->count());
    $totalRows = (int) ($batch->workbook_summary['total_rows'] ?? 0);
@endphp
@component('layouts.admin', ['title' => 'Preview Import', 'subtitle' => 'CHECK BEFORE ADDING TO CJP', 'active' => 'imports'])
    <div class="import-page import-preview-minimal">
        @if ($errors->any())<p class="form-alert" role="alert">{{ $errors->first() }}</p>@endif
        @if (session('status'))<p class="form-success" role="status">{{ session('status') }}</p>@endif

        <nav class="import-steps" aria-label="Import workflow">
            @foreach (['Upload', 'Analyze', 'Preview', 'Confirm', 'Import'] as $step => $label)
                <div class="import-step {{ $step === 2 ? 'is-active' : '' }} {{ $step < 2 ? 'is-complete' : '' }}"><span>{{ $step + 1 }}</span><strong>{{ $label }}</strong></div>@if ($step < 4)<i aria-hidden="true">→</i>@endif
            @endforeach
        </nav>

        <header class="import-minimal-header"><div class="import-review-file"><span class="import-file-icon is-small" aria-hidden="true">▤</span><div><p class="eyebrow">Preview Import</p><h1>{{ $batch->original_filename }}</h1></div></div><span class="import-status import-status-{{ $batch->status }}">{{ ucfirst($batch->status) }}</span></header>

        <section class="import-minimal-summary import-summary-single" aria-label="Record summary">
            <div><span>Total records detected</span><strong>{{ number_format($totalRows) }}</strong></div>
            <div><span>Detected worksheets</span><strong>{{ number_format(count($sheets)) }}</strong></div>
            <div><span>Recognized CJP fields</span><strong>{{ number_format($recognizedFieldCount) }}</strong></div>
        </section>

        <section class="import-panel import-minimal-panel">
            <div class="import-section-heading"><div><p class="eyebrow">Detected data</p><h2>Worksheets</h2></div><span class="import-section-count">{{ count($sheets) }} sheets</span></div>
            <div class="import-worksheet-list">
                @foreach ($sheets as $sheet)
                    @php($stats = $worksheetValidation[$sheet['name']] ?? ['rows' => $sheet['rows'] ?? 0, 'valid' => 0, 'duplicates' => 0, 'invalid' => 0])
                    <div class="import-worksheet-row"><div><strong>{{ $sheet['name'] }}</strong><small>{{ $sheet['category_label'] ?? 'Unrecognized' }}</small></div><span>{{ number_format((int) ($stats['rows'] ?? 0)) }} records</span><span class="import-worksheet-status is-matched">Detected</span></div>
                @endforeach
            </div>
        </section>

        @if ($needsMappingEdit)
            <button class="btn btn-secondary import-fix-button" type="button" data-modal-open="import-issues-modal">Fix Missing Columns <span aria-hidden="true">→</span></button>
            <div class="modal-backdrop" id="import-issues-modal" aria-hidden="true">
                <section class="admin-modal import-issues-modal" role="dialog" aria-modal="true" aria-labelledby="import-issues-title">
                    <div class="modal-titlebar"><h2 id="import-issues-title">Fix Missing Columns</h2></div>
                    <div class="modal-body"><p class="import-muted">Review only the columns that need fixing. Save Changes to update the preview.</p>@include('admin.imports.partials.mapping-form', ['batch' => $batch, 'referenceOptions' => $referenceOptions, 'referenceCandidates' => collect(), 'issuesOnly' => true])</div>
                </section>
            </div>
        @else
            <p class="import-mapping-ready">All required columns matched. Ready to import.</p>
        @endif

        @if ($isProcessing)
            <section class="import-confirm-note import-processing-note" role="status"><strong>This import is already processing.</strong><p>Confirmation is temporarily locked to prevent duplicate records. Refresh the Data Import page for the latest status.</p></section>
        @else
            <section class="import-confirm-note"><strong>After confirmation, valid new records are added to the relevant CJP modules.</strong><p>Existing records remain unchanged. Any records that cannot be safely imported are reported after processing.</p></section>
            <div class="import-actions import-simple-actions"><form method="POST" action="{{ route('admin.imports.cancel', $batch) }}" data-import-cancel data-cancel-redirect="{{ route('admin.imports') }}" data-confirm="simple" data-confirm-title="Cancel Import?" data-confirm-cancel="Keep Import" data-confirm-ok="Yes, Cancel Import" data-confirm-busy-label="Cancelling…" data-confirm-tone="danger" data-confirm-message="Are you sure you want to cancel this import? No records will be added to the system.">@csrf<button class="btn btn-secondary" type="submit">Cancel Import</button></form><form method="POST" action="{{ route('admin.imports.confirm', $batch) }}" data-import-confirm data-import-status="preview" data-confirm="simple" data-confirm-title="Confirm Import" data-confirm-ok="Confirm Import" data-confirm-message="Confirm this import. Valid new records will be added to CJP, while existing or invalid records will be skipped safely. Continue?">@csrf<input type="hidden" name="confirm_import" value="1"><button class="btn btn-primary" type="submit" data-import-submit data-loading-label="Importing safely…">Confirm Import</button><span class="import-progress" data-import-progress hidden>Importing safely…</span></form></div>
        @endif
    </div>
@endcomponent
