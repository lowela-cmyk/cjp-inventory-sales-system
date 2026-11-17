<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Services\SpreadsheetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminImportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'import_status' => ['nullable', 'in:preview,processing,completed,failed']]);
        return view('admin.imports.index', [
            'importReady' => \App\Support\ImportReadiness::ready(),
            'batches' => ImportBatch::query()->where('status', '!=', 'cancelled')
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('original_filename', 'like', '%'.$search.'%'))
                ->when($filters['import_status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->latest()->orderByDesc('id')->paginate(\App\Support\TablePagination::size(), ['*'], 'imports_page')->withQueryString(),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        $path = storage_path('app/templates/CJP_System_Import_Template.xlsx');
        abort_unless(is_file($path), 404, 'The import template is not available yet.');

        return response()->download($path, 'CJP_System_Import_Template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function upload(Request $request, SpreadsheetImportService $imports): RedirectResponse
    {
        $uploaded = $request->file('file');
        if ($uploaded !== null && ! $uploaded->isValid()) {
            return back()->withInput()->withErrors(['file' => $this->uploadErrorMessage($uploaded->getError())]);
        }
        if ($uploaded === null && (int) $request->server('CONTENT_LENGTH', 0) > $this->iniBytes((string) ini_get('post_max_size'))) {
            return back()->withInput()->withErrors(['file' => 'The request was larger than PHP post_max_size, so the upload never reached Laravel. Increase post_max_size and retry.']);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimes:xlsx,xls,csv'],
            'dataset_kind' => ['nullable', 'in:unknown,original,mock_demo'],
        ]);

        try {
            $batch = $imports->store($data['file'], (int) $request->user()->id);
            $batch->update(['workbook_summary' => $batch->workbook_summary + ['dataset_kind' => $data['dataset_kind'] ?? 'unknown']]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['file' => 'The file could not be analyzed: '.$e->getMessage()]);
        }

        return redirect()->route('admin.imports.preview', $batch)->with('status', 'File analyzed. Review the import preview before confirming.');
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'The selected file is larger than PHP allows for uploads. Ask the administrator to raise upload_max_filesize and post_max_size, then retry.',
            UPLOAD_ERR_FORM_SIZE => 'The selected file exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted before it finished. Check the connection and retry.',
            UPLOAD_ERR_NO_FILE => 'Choose an Excel or CSV file first.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP has no writable temporary upload directory. Check the server temp path and permissions.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to temporary storage. Check disk space and permissions.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload. Check the PHP upload configuration.',
            default => 'The file upload failed before analysis. Check the PHP upload configuration and retry.',
        };
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function preview(ImportBatch $batch, SpreadsheetImportService $imports): View|RedirectResponse
    {
        abort_unless($batch->created_by === auth()->id() && $batch->status !== 'cancelled', 404);

        if ($batch->status === 'completed') {
            return redirect()->route('admin.imports')->with('status', 'This import has already completed. No records were imported again.');
        }
        if ($batch->status === 'failed') {
            return redirect()->route('admin.imports')->withErrors(['import' => $batch->failure_message ?: 'This import failed and cannot be confirmed again.']);
        }

        return view('admin.imports.preview', [
            'batch' => $batch,
            'referenceOptions' => $imports->referenceOptions(),
            'isProcessing' => $batch->status === 'processing',
        ]);
    }

    public function updateMapping(Request $request, ImportBatch $batch, SpreadsheetImportService $imports): RedirectResponse
    {
        abort_unless($batch->created_by === auth()->id() && $batch->status === 'preview', 403);
        $data = $request->validate(['mapping' => ['required', 'array'], 'references' => ['nullable', 'array']]);
        $mapping = $batch->mapping_summary ?? [];
        foreach ($data['mapping'] as $sheet => $sheetMapping) {
            if (! isset($mapping[$sheet]) || ! is_array($sheetMapping)) {
                continue;
            }
            $category = (string) ($mapping[$sheet]['category'] ?? '');
            $allowed = $imports->fieldsFor($category);
            $mapping[$sheet]['columns'] = collect($sheetMapping['columns'] ?? [])->map(fn ($field) => in_array($field, $allowed, true) ? $field : null)->all();
        }

        $referenceOverrides = $mapping['_reference_overrides'] ?? [];
        $referenceAllowed = ['customers' => 'customers', 'drivers' => 'users', 'trucks' => 'trucks', 'depots' => 'depots', 'fuel_types' => 'fuel_types'];
        foreach (($data['references'] ?? []) as $category => $fields) {
            if (! isset($referenceAllowed[$category])) continue;
            foreach ($fields as $field => $values) {
                foreach ($values as $raw => $id) {
                    $exists = DB::table($referenceAllowed[$category])->where('id', (int) $id)->exists();
                    if ($exists) {
                        $referenceOverrides[$category][$field][(string) $raw] = (int) $id;
                    } else {
                        unset($referenceOverrides[$category][$field][(string) $raw]);
                    }
                }
            }
        }
        $mapping['_reference_overrides'] = $referenceOverrides;

        $path = Storage::disk('local')->path($batch->stored_path);
        try {
            $plan = $imports->analyze($path, $mapping);
            $plan['mappings']['_reference_overrides'] = $referenceOverrides;
            $batch->update(['mapping_summary' => $plan['mappings'], 'workbook_summary' => $plan['workbook'] + ['dataset_kind' => $batch->workbook_summary['dataset_kind'] ?? 'unknown'], 'validation_summary' => $plan['validation']]);
        } catch (Throwable $e) {
            return back()->withErrors(['mapping' => 'Mapping could not be rechecked: '.$e->getMessage()]);
        }

        return back()->with('status', 'Column matching saved. The import was revalidated and the preview counts were updated.');
    }

    public function confirm(Request $request, ImportBatch $batch, SpreadsheetImportService $imports): RedirectResponse
    {
        abort_unless($batch->created_by === auth()->id() && $batch->status !== 'cancelled', 404);
        if ($batch->status !== 'preview') {
            return redirect()->route('admin.imports')->withErrors(['import' => match ($batch->status) {
                'completed' => 'This import has already completed and cannot be imported again.',
                'processing' => 'This import is already being processed. Please wait for it to finish.',
                default => 'This import is no longer available for confirmation.',
            }]);
        }
        $request->validate(['confirm_import' => ['accepted']]);

        try {
            $result = $imports->import($batch, (int) $request->user()->id);
        } catch (Throwable $e) {
            return redirect()->route('admin.imports')->with('import_result', [
                'status' => 'failed', 'filename' => $batch->original_filename, 'imported' => 0, 'duplicates' => 0,
                'failed' => 1, 'reasons' => [$e->getMessage()],
            ]);
        }

        $skipped = array_sum($result['skipped']);
        $duplicates = (int) ($batch->fresh()->validation_summary['total_duplicates'] ?? 0);
        $failed = max(0, $skipped - $duplicates);
        $reasons = array_slice($result['errors'] ?? [], 0, 5);
        if ($failed > 0 && $reasons === []) {
            $reasons = ['Some records did not meet the import requirements and were not added.'];
        }

        return redirect()->route('admin.imports')->with('import_result', [
            'status' => $failed > 0 ? 'warning' : 'success',
            'filename' => $batch->original_filename,
            'imported' => array_sum($result['created']),
            'duplicates' => $duplicates,
            'failed' => $failed,
            'reasons' => $reasons,
        ]);
    }

    public function cancel(Request $request, ImportBatch $batch): RedirectResponse|JsonResponse
    {
        abort_unless($batch->created_by === auth()->id(), 403);
        if ($batch->status === 'preview') {
            $storedPath = $batch->stored_path;
            $cancelled = DB::table('import_batches')->where('id', $batch->id)->where('status', 'preview')->update([
                'status' => 'cancelled', 'updated_at' => now(), 'completed_at' => now(),
            ]);
            if ($cancelled !== 1) {
                if ($request->expectsJson()) return response()->json(['removed' => false, 'message' => 'This import is already being processed.'], 409);
                return redirect()->route('admin.imports')->with('status', 'This import is already being processed. No records were changed.');
            }
            Storage::disk('local')->delete($storedPath);

            if ($request->expectsJson()) {
                return response()->json(['removed' => true, 'message' => 'Import cancelled. No records were added to the system.']);
            }

            return redirect()->route('admin.imports')->with('status', 'Import cancelled. No records were added to the system.');
        }

        if ($request->expectsJson()) {
            return response()->json(['removed' => false, 'message' => 'This import is already '.strtolower($batch->status).'.'], 409);
        }

        return redirect()->route('admin.imports')->with('status', 'This import is already '.strtolower($batch->status).'. No records were changed.');
    }
}
