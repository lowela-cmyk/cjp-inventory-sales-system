@component('layouts.admin', ['title' => 'Data Import', 'subtitle' => 'BULK ENCODING · SAFE EXCEL AND CSV IMPORTS', 'active' => 'imports'])
    <div class="import-page">
        @if (session('import_result'))
            @php($importResult = session('import_result'))
            <section class="import-result-panel import-result-{{ $importResult['status'] ?? 'success' }}" role="status" data-import-result-panel>
                <div class="import-result-icon" aria-hidden="true">{{ ($importResult['status'] ?? 'success') === 'success' ? '✓' : '!' }}</div>
                <div class="import-result-content">
                    <p class="eyebrow">
                        @if (($importResult['status'] ?? 'success') === 'success')
                            Import Successful
                        @elseif (($importResult['status'] ?? 'success') === 'warning')
                            Import Completed with Warnings
                        @else
                            Import Failed
                        @endif
                    </p>
                    <h2>{{ $importResult['filename'] ?? 'Imported file' }}</h2>
                    <div class="import-result-counts">
                        <span><strong>{{ number_format((int) ($importResult['imported'] ?? 0)) }}</strong> imported</span>
                        <span><strong>{{ number_format((int) ($importResult['duplicates'] ?? 0)) }}</strong> duplicates skipped</span>
                        <span><strong>{{ number_format((int) ($importResult['failed'] ?? 0)) }}</strong> not imported</span>
                    </div>
                    @if (($importResult['status'] ?? 'success') !== 'success' && ! empty($importResult['reasons']))
                        <details class="import-result-reasons"><summary>View reasons</summary><ul>@foreach ($importResult['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></details>
                    @endif
                </div>
                <div class="import-result-actions"><a class="btn btn-primary" href="{{ route('admin.dashboard') }}">View Imported Records</a><button class="btn btn-secondary" type="button" data-import-result-close>Close</button></div>
            </section>
        @endif
        @if (session('status'))
            <p class="form-success" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="form-alert" role="alert">{{ $errors->first() }}</p>
        @endif

        @if (! ($importReady ?? true))
            <p class="form-alert" role="alert">Data Import is unavailable until the administrator completes the required database update. Existing records are safe.</p>
        @endif
        <nav class="import-steps" aria-label="Import workflow">
            @foreach (['Upload', 'Analyze', 'Preview', 'Confirm', 'Import'] as $step => $label)
                <div class="import-step {{ $step === 0 ? 'is-active' : '' }}"><span>{{ $step + 1 }}</span><strong>{{ $label }}</strong></div>
                @if ($step < 4)<i aria-hidden="true">→</i>@endif
            @endforeach
        </nav>

        <section class="import-hero import-upload-card">
            <div class="import-hero-copy">
                <div class="import-brand-mark" aria-hidden="true"><span>▤</span></div>
                <div>
                    <p class="eyebrow">CJP Fuel Vision · Safe workspace</p>
                    <h2>Encode data in bulk</h2>
                    <p>Upload a workbook to automatically detect sheets, map fields, validate relationships, and prepare the same records used by the CJP system modules.</p>
                    <div class="import-trust-points"><span>✓ No records saved during analysis</span><span>✓ Existing records are protected</span></div>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.imports.upload') }}" enctype="multipart/form-data" class="import-upload-form" data-analysis-form data-confirm="none">
                @csrf
                <div class="form-row"><label for="dataset_kind">Dataset origin</label><select id="dataset_kind" name="dataset_kind"><option value="unknown">Unknown / needs verification</option><option value="original">Original transactions</option><option value="mock_demo">Mock / demo data</option></select></div>
                <p class="table-help">Completion statuses that require stock movements must be reviewed and completed through the normal workflow. Paid amounts require explicit payment date, method and reference. Existing records are never overwritten.</p>
                <label class="import-dropzone" for="import-file" data-import-dropzone tabindex="0">
                    <input id="import-file" type="file" name="file" accept=".xlsx,.xls,.csv" required>
                    <span class="import-file-icon" aria-hidden="true">⌁</span>
                    <strong data-dropzone-title>Drop your Excel or CSV file here</strong>
                    <span data-dropzone-help>or choose a file from your computer</span>
                    <span class="btn btn-secondary import-browse-button">Browse Files</span>
                </label>
                <div class="import-selected-file" data-selected-file hidden>
                    <span class="import-file-icon is-small" aria-hidden="true">▤</span>
                    <span><strong data-selected-name></strong><small data-selected-size></small></span>
                    <button type="button" class="import-remove-file" data-remove-file aria-label="Remove selected file">×</button>
                </div>
                <small class="import-format-note">Supported formats: .xlsx, .xls, .csv · Analysis runs before any database write.</small>
                <button class="btn btn-primary import-analyze-button" @disabled(! ($importReady ?? true)) type="submit" data-analysis-submit><span aria-hidden="true">✦</span> Analyze File</button>
            </form>
        </section>

        <section class="import-panel import-history-panel" id="import-history">
            <div class="page-heading-row"><div><p class="eyebrow">Activity</p><h2>Import history</h2></div><div class="import-history-heading-actions"><a class="btn btn-secondary" href="{{ route('admin.imports.template') }}">Export Import Template</a><span class="import-muted">Only confirmed imports write records.</span></div></div>
            <x-filter.bar :action="route('admin.imports')">
                <x-filter.search label="Search import files" :value="request('search')" />
                <x-filter.select name="import_status" label="Import Status" :options="['preview' => 'Preview', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed']" :selected="request('import_status')" />
            </x-filter.bar>
            <div class="table-wrap" data-import-history-container>
            @if ($batches->isEmpty())
                <div class="import-empty-state"><span class="import-empty-icon" aria-hidden="true">▤</span><h3>No import history available.</h3><p>Upload your first workbook above. You’ll get a full analysis and preview before anything is saved.</p></div>
            @else
                <table class="admin-table import-table import-history-table">
                    <thead><tr><th>File</th><th>Status</th><th>Detected rows</th><th>Created</th><th>Skipped</th><th>Failed</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($batches as $batch)
                        <tr data-import-history-row data-file-name="{{ strtolower($batch->original_filename) }}" data-status="{{ $batch->status }}">
                            <td><div class="import-file-cell"><span class="import-file-icon is-tiny" aria-hidden="true">▤</span><strong>{{ $batch->original_filename }}</strong></div></td>
                            <td><span class="import-status import-status-{{ $batch->status }}">{{ ucfirst($batch->status) }}</span></td>
                            <td>{{ number_format((int) ($batch->workbook_summary['total_rows'] ?? 0)) }}</td>
                            <td>{{ number_format(array_sum($batch->result_summary['created'] ?? [])) }}</td>
                            <td>{{ number_format(array_sum($batch->result_summary['skipped'] ?? [])) }}</td>
                            <td>{{ number_format(count($batch->result_summary['errors'] ?? [])) }}</td>
                            <td>{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                @if ($batch->status === 'preview')
                                    <div class="import-history-actions">
                                        <a class="btn btn-secondary" href="{{ route('admin.imports.preview', $batch) }}">Review</a>
                                        <form method="POST" action="{{ route('admin.imports.cancel', $batch) }}" data-import-cancel data-confirm="simple" data-confirm-title="Cancel Import?" data-confirm-cancel="Keep Import" data-confirm-ok="Yes, Cancel Import" data-confirm-busy-label="Cancelling…" data-confirm-tone="danger" data-confirm-message="Are you sure you want to cancel this import? No records will be added to the system.">@csrf<button class="btn btn-danger import-cancel-button" type="submit">Cancel</button></form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            </div>
            {{ $batches->links() }}
        </section>
    </div>
@endcomponent
