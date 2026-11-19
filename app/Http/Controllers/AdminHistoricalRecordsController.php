<?php

namespace App\Http\Controllers;

use App\Support\HistoricalReconciliation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Read-only view of historical source rows kept in the reconciliation register because they match no imported
 * record (the Driver Schedule, Driver Hauled and Driver Deliveries sheets of CJP_DATA.pdf). They are shown exactly
 * as in the source and are never counted as lifts, deliveries or sales.
 */
class AdminHistoricalRecordsController extends Controller
{
    /** Register sheet name => tab key. */
    public const SHEETS = ['schedule' => 'Driver Schedule', 'hauled' => 'Driver Hauled', 'deliveries' => 'Driver Deliveries'];

    public function driverRecords(Request $request): View
    {
        $data = $request->validate([
            'sheet' => ['nullable', 'in:'.implode(',', array_keys(self::SHEETS))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $sheetKey = $data['sheet'] ?? 'schedule';
        $search = trim((string) ($data['search'] ?? ''));
        $available = HistoricalReconciliation::available();

        $base = fn () => DB::table('historical_reconciliation_items')->where('category', HistoricalReconciliation::SOURCE_ROW_UNMATCHED);
        $counts = $available
            ? $base()->groupBy('source_sheet')->selectRaw('source_sheet, COUNT(*) as n')->pluck('n', 'source_sheet')->map(fn ($n) => (int) $n)->all()
            : [];

        $rows = $available
            ? $base()->where('source_sheet', self::SHEETS[$sheetKey])
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('source_reference', 'like', '%'.$search.'%')
                    ->orWhere('source_values', 'like', '%'.$search.'%')))
                ->orderBy('source_row')
                ->paginate(\App\Support\TablePagination::size(), ['id', 'source_document', 'source_sheet', 'source_row', 'source_reference', 'detail', 'source_values'], 'records_page')
                ->withQueryString()
            : null;
        $rows?->getCollection()->transform(function (object $row): object {
            $row->values = json_decode((string) $row->source_values, true) ?: [];

            return $row;
        });
        $columns = $rows && $rows->count() > 0 ? array_keys($rows->first()->values) : [];

        return view('admin.historical.driver-records', [
            'sheets' => self::SHEETS,
            'sheetKey' => $sheetKey,
            'counts' => $counts,
            'rows' => $rows,
            'columns' => $columns,
            'search' => $search,
        ]);
    }
}
