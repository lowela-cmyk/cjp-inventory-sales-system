<?php

namespace App\Services;

use App\Support\FilterDates;
use App\Support\TruckLabel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type LedgerLift array{sequence: int, code: mixed, quantity: string, display_quantity: string, display_date: string, status: string, counts_as_lifted: bool, details: array{'Lift ID': mixed, 'Date Lifted': string, Quantity: string, Driver: mixed, Truck: string, Status: string, Receipt: string}, receipt: array{uploaded_at: string, uploaded_by: mixed, notes: string|null, url: string}|null, search_text: string}
 * @phpstan-type LedgerCells array{mixed, mixed, mixed, string, string, string, string}
 * @phpstan-type LedgerTransaction array{id: string, purchase_id: int, purchase_item_id: int, purchase_code: mixed, status: string, status_class: string, purchased_liters: string, lifted_liters: string, remaining_liters: string, driver_names: string, truck_names: string, inventory_status: string, lifts: Collection<int, LedgerLift>, lift_export: string, receipt_count: int, search_text: string, cells: LedgerCells, details: array{'Purchase ID': mixed, 'Purchase Date': string, 'Fuel Type': mixed, Depot: mixed, 'Purchased Quantity': string, 'Total Lifted': string, 'Remaining Quantity': string, 'Lift Transactions': string, Deliveries: string, Status: string}}
 */
class InventoryLedgerService
{
    /** Lifting statuses a ledger row can have, keyed by filter value. */
    public const STATUSES = ['incomplete' => 'Incomplete', 'partially_lifted' => 'Partially Lifted', 'complete' => 'Complete'];

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, depot_id?: mixed, fuel_type_id?: mixed, status?: ?string}  $filters
     * @return array{ledger: Collection<int, LedgerCells>, transactions: Collection<int, LedgerTransaction>, latestBalances: array<string, float>}
     */
    public function rows(?string $search = null, array $filters = []): array
    {
        $purchaseRows = $this->purchaseProgressRows($search, $filters);
        $purchaseItemIds = $purchaseRows->pluck('purchase_item_id')->map(fn (mixed $id): int => (int) $id)->all();
        $liftsByPurchaseItem = $this->liftsByPurchaseItem($purchaseItemIds);
        $movementsByPurchaseItem = $this->movementSummariesByPurchaseItem($purchaseItemIds);
        $stockByPurchaseItem = app(PurchaseStockService::class)->lines($purchaseItemIds);
        $latestBalances = $this->latestBalances();

        $transactions = $purchaseRows->map(function (object $row) use ($liftsByPurchaseItem, $movementsByPurchaseItem, $stockByPurchaseItem): array {
            $purchased = round((float) $row->quantity_ordered_liters, 2);
            $lifted = round(min($purchased, (float) $row->total_lifted_liters), 2);
            $remaining = round(max(0, $purchased - $lifted), 2);
            $lifts = $liftsByPurchaseItem[(int) $row->purchase_item_id] ?? $this->noLifts();
            $movementSummary = $movementsByPurchaseItem[(int) $row->purchase_item_id] ?? 'No deliveries recorded';
            $status = $this->liftingStatus($lifted, $purchased);
            $stock = $stockByPurchaseItem->get((int) $row->purchase_item_id);
            $inventoryStatus = match (true) {
                $stock === null => 'N/A',
                (float) $stock->on_hand <= 0 => 'Depleted',
                (float) $stock->released > 0 => 'Partially Delivered',
                default => 'In Stock',
            };
            $driverNames = $lifts->pluck('details.Driver')->filter()->unique()->values()->implode(', ') ?: 'Unassigned';
            $truckNames = $lifts->pluck('details.Truck')->filter()->unique()->values()->implode(', ') ?: 'Unassigned';

            return [
                'id' => 'ledger-purchase-'.$row->purchase_item_id,
                'purchase_id' => (int) $row->purchase_id,
                'purchase_item_id' => (int) $row->purchase_item_id,
                'purchase_code' => $row->purchase_code,
                'status' => $status,
                'status_class' => $remaining <= 0 ? 'modal-complete' : 'modal-incomplete',
                'purchased_liters' => $this->formatDisplayLiters($purchased),
                'lifted_liters' => $this->formatDisplayLiters($lifted),
                'remaining_liters' => $this->formatDisplayLiters($remaining),
                'driver_names' => $driverNames,
                'truck_names' => $truckNames,
                'inventory_status' => $inventoryStatus,
                'lifts' => $lifts,
                // Part 20 #5: the Transaction export carries every lift with its own truck, driver and receipt status.
                'lift_export' => $lifts->map(fn (array $lift): string => $lift['code'].' ('.$lift['quantity'].', '.$lift['display_date'].', Truck: '
                    .($lift['details']['Truck'] ?: 'Unassigned').', Driver: '.($lift['details']['Driver'] ?: 'Unassigned').', Receipt: '.$lift['details']['Receipt'].')')
                    ->implode('; ') ?: 'No lifts recorded',
                'receipt_count' => $lifts->whereNotNull('receipt')->count(),
                'search_text' => strtolower(implode(' ', [
                    $row->purchase_code,
                    $row->fuel_name,
                    $row->depot_name,
                    $status,
                    $movementSummary,
                    $lifts->pluck('search_text')->implode(' '),
                ])),
                'cells' => [
                    $row->purchase_code,
                    $row->fuel_name,
                    $row->depot_name,
                    $this->formatNumber($purchased),
                    $this->formatNumber($lifted),
                    $this->formatNumber($remaining),
                    $status,
                ],
                'details' => [
                    'Purchase ID' => $row->purchase_code,
                    'Purchase Date' => $this->formatDate($row->purchase_date),
                    'Fuel Type' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'Purchased Quantity' => $this->formatLiters($purchased),
                    'Total Lifted' => $this->formatLiters($lifted),
                    'Remaining Quantity' => $this->formatLiters($remaining),
                    'Lift Transactions' => (string) $lifts->count(),
                    'Deliveries' => $movementSummary,
                    'Status' => $status,
                ],
            ];
        });

        // Status is derived from lifted quantities, so it is filtered after the rows are built.
        $statusLabel = self::STATUSES[$filters['status'] ?? ''] ?? null;
        if ($statusLabel !== null) {
            $transactions = $transactions->where('status', $statusLabel);
        }

        return [
            'ledger' => $transactions
                ->filter(fn (array $row): bool => (float) str_replace(',', '', $row['cells'][5]) > 0)
                ->map(fn (array $row): array => $row['cells'])
                ->values(),
            'transactions' => $transactions->values(),
            'latestBalances' => $latestBalances,
        ];
    }

    /**
     * A purchase line without lifts.
     *
     * @return Collection<int, LedgerLift>
     */
    private function noLifts(): Collection
    {
        return collect();
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, depot_id?: mixed, fuel_type_id?: mixed, status?: ?string}  $filters
     * @return Collection<int, \stdClass>
     */
    private function purchaseProgressRows(?string $search, array $filters = []): Collection
    {
        $completedLifts = DB::table('hauls')
            ->whereNull('deleted_at')
            ->whereIn('status', ['lifted', 'completed'])
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as total_lifted_liters')
            ->groupBy('purchase_item_id');

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoinSub($completedLifts, 'completed_lifts', 'completed_lifts.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                foreach ([
                    'purchases.purchase_code',
                    'depots.name',
                    'fuel_types.name',
                    'purchases.status',
                    'purchase_items.status',
                ] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->tap(fn (Builder $query): Builder => FilterDates::apply($query, 'purchases.purchase_date', ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null]))
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $depotId): Builder => $query->where('purchases.depot_id', (int) $depotId))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('purchase_items.fuel_type_id', (int) $fuelTypeId))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.id as purchase_item_id',
                'purchase_items.purchase_id',
                'purchase_items.quantity_ordered_liters',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                DB::raw('COALESCE(completed_lifts.total_lifted_liters, 0) as total_lifted_liters'),
            ]);
    }

    /**
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int|string, Collection<int, LedgerLift>>  purchase_item_id => its lifts
     */
    private function liftsByPurchaseItem(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        return DB::table('hauls')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'drivers.id')
            ->leftJoin('users as receipt_uploaders', 'receipt_uploaders.id', '=', 'hauls.withdrawal_receipt_uploaded_by')
            ->whereIn('hauls.purchase_item_id', $purchaseItemIds)
            ->whereNull('hauls.deleted_at')
            ->orderBy('hauls.scheduled_at')
            ->orderBy('hauls.id')
            ->get([
                'hauls.id',
                'hauls.purchase_item_id',
                'hauls.haul_code',
                'hauls.dr_number',
                'hauls.quantity_liters',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.status',
                'trucks.truck_code',
                'trucks.name as truck_name',
                'trucks.capacity_liters',
                'drivers.name as driver_name',
                'driver_profiles.driver_code',
                'hauls.withdrawal_receipt_path',
                'hauls.withdrawal_receipt_notes',
                'hauls.withdrawal_receipt_uploaded_at',
                'receipt_uploaders.name as receipt_uploaded_by_name',
            ])
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $rows): Collection => $rows->values()->map(function (object $row, int $index): array {
                $truckLabel = TruckLabel::make($row->truck_name, $row->truck_code);
                $truck = trim((string) $row->truck_name) !== '' && trim((string) $row->truck_code) !== ''
                    ? $truckLabel.' / '.$row->truck_code
                    : ($truckLabel !== '' ? $truckLabel : 'N/A');
                $details = [
                    'Lift ID' => $row->haul_code,
                    'Date Lifted' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : (in_array($row->status, ['lifted', 'completed'], true) ? 'Date unavailable' : 'Not yet lifted'),
                    'Quantity' => $this->formatLiters($row->quantity_liters),
                    'Driver' => $row->driver_name,
                    'Truck' => $truck,
                    'Status' => $this->label($row->status),
                    // Part 20 #1: each lift keeps its own withdrawal receipt (one purchase can have several lifts).
                    'Receipt' => $row->withdrawal_receipt_path
                        ? 'Uploaded '.$this->formatDateTime($row->withdrawal_receipt_uploaded_at).($row->receipt_uploaded_by_name ? ' by '.$row->receipt_uploaded_by_name : '')
                        : 'Not uploaded',
                ];

                return [
                    'sequence' => $index + 1,
                    'code' => $row->haul_code,
                    'quantity' => $this->formatLiters($row->quantity_liters),
                    'display_quantity' => $this->formatDisplayLiters($row->quantity_liters),
                    'display_date' => $row->hauled_at ? $this->formatDate($row->hauled_at) : (in_array($row->status, ['lifted', 'completed'], true) ? 'Date unavailable' : 'Not yet lifted'),
                    'status' => $this->label($row->status),
                    'counts_as_lifted' => in_array($row->status, ['lifted', 'completed'], true),
                    'details' => $details,
                    'receipt' => $row->withdrawal_receipt_path ? [
                        'uploaded_at' => $this->formatDateTime($row->withdrawal_receipt_uploaded_at),
                        'uploaded_by' => $row->receipt_uploaded_by_name,
                        'notes' => is_string($row->withdrawal_receipt_notes) && $row->withdrawal_receipt_notes !== '' ? $row->withdrawal_receipt_notes : null,
                        // The file is served by the role-gated withdrawal-receipts.show route, never a public URL.
                        'url' => route('withdrawal-receipts.show', $row->id).'?v='.strtotime((string) $row->withdrawal_receipt_uploaded_at),
                    ] : null,
                    'search_text' => strtolower(implode(' ', $details)),
                ];
            }))
            ->all();
    }

    private function liftingStatus(float $lifted, float $purchased): string
    {
        return match (true) {
            $lifted <= 0 => 'Incomplete',
            round($lifted, 2) >= round($purchased, 2) => 'Complete',
            default => 'Partially Lifted',
        };
    }

    /**
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, string>
     */
    private function movementSummariesByPurchaseItem(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        // Part 2 revisions: what deliveries took from the purchase (its stock), by delivery type.
        // Part 18 add-on: a tank release counts for the purchases it drew from.
        return PurchaseStockService::releasesByPurchaseQuery()
            ->join('sales', 'sales.id', '=', 'purchase_releases.sale_id')
            ->whereIn('purchase_releases.purchase_item_id', $purchaseItemIds)
            ->groupBy('purchase_releases.purchase_item_id', 'sales.delivery_type')
            ->selectRaw('purchase_releases.purchase_item_id, sales.delivery_type, COALESCE(SUM(purchase_releases.liters), 0) as liters')
            ->get()
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $rows): string => 'Delivered: '.$rows
                ->map(fn (object $row): string => match ($row->delivery_type) {
                    'depot_to_client' => 'Depot to Client',
                    'garage_to_client' => 'Garage to Client',
                    default => 'Standard',
                }.' '.$this->formatLiters($row->liters))
                ->implode(', '))
            ->all();
    }

    /**
     * Stock per fuel (Part 2 revisions): the remaining liters of its purchases, keyed "fuel_type_id".
     *
     * @return array<string, float>
     */
    private function latestBalances(): array
    {
        $released = PurchaseStockService::releasesByPurchaseQuery()
            ->groupBy('purchase_item_id')->selectRaw('purchase_item_id, COALESCE(SUM(liters), 0) as released');

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoinSub($released, 'released', 'released.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->groupBy('purchase_items.fuel_type_id')
            ->selectRaw('purchase_items.fuel_type_id, COALESCE(SUM(CASE WHEN purchase_items.quantity_ordered_liters > COALESCE(released.released, 0) THEN purchase_items.quantity_ordered_liters - COALESCE(released.released, 0) ELSE 0 END), 0) as balance')
            ->pluck('balance', 'fuel_type_id')
            ->mapWithKeys(fn (mixed $balance, mixed $fuelTypeId): array => [(string) $fuelTypeId => round((float) $balance, 2)])
            ->all();
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    private function formatDisplayLiters(mixed $value): string
    {
        $liters = (float) ($value ?? 0);
        $precision = abs($liters - round($liters)) < 0.005 ? 0 : 2;

        return number_format($liters, $precision).' L';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
