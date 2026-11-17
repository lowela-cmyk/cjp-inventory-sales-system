@php
    $mappingSheets = collect($batch->mapping_summary ?? [])->filter(fn ($sheet) => is_array($sheet) && isset($sheet['columns']));
    $needsFixCount = $mappingSheets->sum(fn ($sheet) => count($sheet['missing_fields'] ?? []));
@endphp
<form method="POST" action="{{ route('admin.imports.mapping', $batch) }}" data-confirm="none" data-mapping-form class="import-mapping-form">
    @csrf @method('PATCH')

    @if ($needsFixCount === 0)
        <p class="import-mapping-ready">All required columns matched. Ready to import.</p>
    @else
        <p class="import-mapping-help">Only required CJP fields missing from the uploaded file are shown. Unsupported, calculated, display-only, and action columns are ignored automatically.</p>
    @endif

    <div class="import-mapping-sheets">
        @foreach ($mappingSheets as $sheetName => $sheetMapping)
            @php($sheetInfo = collect($batch->workbook_summary['worksheets'] ?? [])->firstWhere('name', $sheetName))
            @php($missingFields = $sheetMapping['missing_fields'] ?? [])
            @php($headers = $sheetInfo['headers'] ?? [])
            @php($mappedColumns = collect($sheetMapping['columns'])->filter()->keys()->map(fn ($column) => (string) $column)->all())
            <details class="import-mapping-details" @if ($missingFields) open @endif>
                <summary><span>{{ $sheetName }}</span><small>{{ ucfirst($sheetMapping['category']) }}</small><b>{{ $missingFields ? count($missingFields).' missing' : 'Complete' }}</b></summary>

                @foreach ($sheetMapping['columns'] as $column => $field)
                    <input type="hidden" name="mapping[{{ $sheetName }}][columns][{{ $column }}]" value="{{ $field ?? '' }}" data-default-value="{{ $field ?? '' }}" data-mapping-hidden data-sheet="{{ $sheetName }}" data-column="{{ $column }}">
                @endforeach

                @if ($missingFields)
                    <div class="import-missing-fields">
                        @foreach ($missingFields as $requiredField)
                            <label class="import-missing-field">
                                <span><strong>{{ ucfirst(str_replace('_', ' ', $requiredField)) }}</strong><small>Required CJP field</small></span>
                                <select data-missing-field data-sheet="{{ $sheetName }}" data-required-field="{{ $requiredField }}" aria-label="Uploaded column for {{ $requiredField }}">
                                    <option value="">Select an uploaded column</option>
                                    @foreach ($headers as $column => $header)
                                        @if (! in_array((string) $column, $mappedColumns, true))
                                            <option value="{{ $column }}">{{ $header }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                    <p class="import-mapping-note">Choose an existing uploaded column only when it contains this required CJP value. Missing data will not be invented.</p>
                @endif
            </details>
        @endforeach
    </div>

    @if (($referenceCandidates ?? collect())->isNotEmpty())
        <div class="import-reference-matching">
            <div><strong>Match existing CJP references</strong><p>Choose an existing record only when the uploaded value refers to it.</p></div>
            @php($referenceOverrides = $batch->mapping_summary['_reference_overrides'] ?? [])
            @foreach ($referenceCandidates as $candidate)
                <label><span>{{ ucfirst($candidate['category']) }}: <strong>{{ $candidate['value'] }}</strong></span><select name="references[{{ $candidate['category'] }}][{{ $candidate['field'] }}][{{ $candidate['value'] }}]"><option value="">Leave unresolved</option>@foreach (($referenceOptions[$candidate['category']] ?? []) as $option)<option value="{{ $option['id'] }}" @selected((int) data_get($referenceOverrides, $candidate['category'].'.'.$candidate['field'].'.'.$candidate['value']) === (int) $option['id'])>{{ $option['label'] }}</option>@endforeach</select></label>
            @endforeach
        </div>
    @endif

    <div class="import-mapping-actions"><button class="btn btn-primary" type="submit">Save Changes</button><button class="btn btn-secondary" type="button" data-cancel-mapping>Cancel</button></div>
</form>
