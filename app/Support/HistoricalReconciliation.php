<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read access to the historical reconciliation register (historical_reconciliation_items): evidence of historical
 * source-data limitations recorded during an import. Calculations use it only to keep unverified historical figures
 * out of verified totals and to report them separately; operational records are never changed.
 */
class HistoricalReconciliation
{
    /** Liters the source documents as consumed (F1 blended into gasoline sales) without naming the sale. */
    public const F1_UNATTRIBUTED = 'f1_blend_unattributed';

    /** Gasoline purchase lines whose source stock-outs carry the unattributed F1 liters. */
    public const F1_IN_GASOLINE = 'f1_blend_in_gasoline_release';

    /** The source's own Stock In row for a purchase (Current QTY, Sold QTY, Status), shown instead of derived figures. */
    public const HISTORICAL_STOCK_IN = 'historical_stock_in_source';

    /** Orders whose source stock-out lines add up to more or less than the order (rounding in the source split). */
    public const ROUNDING_VARIANCE = 'stock_out_rounding_variance';

    /** Garage releases whose source names the purchase but not the tank. */
    public const GARAGE_TANK_UNKNOWN = 'garage_tank_unknown';

    /** Stock-outs whose lift the source does not identify. */
    public const LIFT_LINK_UNRESOLVED = 'lift_link_unresolved';

    /** Purchase lines the reconciliation report flags after import (snapshot). */
    public const PURCHASE_LINE_UNRECONCILED = 'purchase_line_unreconciled';

    /** Source rows that match no imported record (kept for audit, not imported). */
    public const SOURCE_ROW_UNMATCHED = 'source_row_unmatched';

    /** Account requests in the source that could not become accounts (no email in the source). */
    public const ACCOUNT_REQUEST_NOT_CREATED = 'account_request_not_created';

    public const LABELS = [
        self::F1_UNATTRIBUTED => 'F1 consumption not attributed to a sale (excluded from verified stock)',
        self::F1_IN_GASOLINE => 'Gasoline releases that include unattributed F1',
        self::HISTORICAL_STOCK_IN => 'Purchases shown with the source Stock In figures',
        self::ROUNDING_VARIANCE => 'Stock-out rounding variance (documented in the source)',
        self::GARAGE_TANK_UNKNOWN => 'Garage releases with no recorded tank',
        self::LIFT_LINK_UNRESOLVED => 'Stock-outs without a verified lift',
        self::PURCHASE_LINE_UNRECONCILED => 'Purchase lines flagged by reconciliation',
        self::SOURCE_ROW_UNMATCHED => 'Source rows not imported (no matching record)',
        self::ACCOUNT_REQUEST_NOT_CREATED => 'Account requests not created (no email in the source)',
    ];

    private static ?bool $available = null;

    public static function available(): bool
    {
        return self::$available ??= Schema::hasTable('historical_reconciliation_items');
    }

    /** Forget the cached table check (tests that migrate mid-run). */
    public static function flush(): void
    {
        self::$available = null;
    }

    /** purchase_item_id, liters: historical liters that are not verified stock. */
    public static function unverifiedStockByPurchaseItem(): Builder
    {
        if (! self::available()) {
            return DB::query()->selectRaw('0 as purchase_item_id, 0 as liters')->whereRaw('1 = 0');
        }

        return DB::table('historical_reconciliation_items')
            ->where('category', self::F1_UNATTRIBUTED)
            ->where('subject_type', 'purchase_item')
            ->groupBy('subject_id')
            ->selectRaw('subject_id as purchase_item_id, SUM(liters) as liters');
    }

    /**
     * purchase_item_id, unverified, excess, source_rows: per purchase line, the F1 the source documents as consumed
     * without a sale (unverified), the blended F1 carried by its gasoline releases (excess), and whether the source has
     * a Stock In row for it. Remaining = ordered - (released + reserved - excess + unverified) and Sold = the same
     * consumption; both equal the source's Current QTY and Sold QTY. Lines without entries are unaffected.
     */
    public static function stockInAdjustmentsByPurchaseItem(): Builder
    {
        if (! self::available()) {
            return DB::query()->selectRaw('0 as purchase_item_id, 0 as unverified, 0 as excess, 0 as source_rows')->whereRaw('1 = 0');
        }

        return DB::table('historical_reconciliation_items')
            ->whereIn('category', [self::F1_UNATTRIBUTED, self::F1_IN_GASOLINE, self::HISTORICAL_STOCK_IN])
            ->where('subject_type', 'purchase_item')
            ->groupBy('subject_id')
            ->selectRaw('subject_id as purchase_item_id')
            ->selectRaw("SUM(CASE WHEN category = ? THEN liters ELSE 0 END) as unverified", [self::F1_UNATTRIBUTED])
            ->selectRaw("SUM(CASE WHEN category = ? THEN liters ELSE 0 END) as excess", [self::F1_IN_GASOLINE])
            ->selectRaw("SUM(CASE WHEN category = ? THEN 1 ELSE 0 END) as source_rows", [self::HISTORICAL_STOCK_IN]);
    }

    /**
     * Documented rounding variance (value) of the given sales.
     *
     * @param  Builder|null  $saleIds  a query selecting sales.id, or null for all
     */
    public static function roundingVariance(?Builder $saleIds = null): array
    {
        if (! self::available()) {
            return ['orders' => 0, 'liters' => 0.0, 'amount' => 0.0];
        }
        $row = DB::table('historical_reconciliation_items')
            ->where('category', self::ROUNDING_VARIANCE)->where('subject_type', 'sale')
            ->when($saleIds, fn (Builder $q) => $q->whereIn('subject_id', $saleIds))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(liters), 0) as liters, COALESCE(SUM(amount), 0) as amount')->first();

        return ['orders' => (int) $row->orders, 'liters' => round((float) $row->liters, 2), 'amount' => round((float) $row->amount, 2)];
    }

    /** @return list<array{category: string, label: string, items: int, liters: float, amount: float, source: string}> */
    public static function summary(): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table('historical_reconciliation_items')
            ->groupBy('category', 'source_document')
            ->selectRaw('category, source_document, COUNT(*) as items, COALESCE(SUM(liters), 0) as liters, COALESCE(SUM(amount), 0) as amount')
            ->orderBy('category')
            ->get()
            ->map(fn (object $r): array => [
                'category' => $r->category, 'label' => self::LABELS[$r->category] ?? $r->category, 'items' => (int) $r->items,
                'liters' => round((float) $r->liters, 2), 'amount' => round((float) $r->amount, 2), 'source' => $r->source_document,
            ])->all();
    }
}
