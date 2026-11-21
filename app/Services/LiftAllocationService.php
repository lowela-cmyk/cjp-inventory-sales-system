<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Depot to Client deliveries of a lift (Part 2 Button Revisions, October 22, 2026). Tanks and garage allocation are
 * retired: a lift carries the fuel of its purchases, and each "Delivery Details" row hands one open Depot to Client
 * order to the truck (one haul_allocations row, destination "customer", for the order's whole open quantity).
 *
 * - An order may ride on one lift only, and only on a lift that carries its purchase (or, for an order recorded
 *   before purchases were chosen, a purchase of its fuel).
 * - Deliveries add up to at most the Amount to Lift; what is left stays on the truck
 *   (Fuel Remaining in Truck = Amount to Lift - deliveries).
 * - Completing the lift delivers the orders: SaleConfirmationService deducts each order's liters from its purchases
 *   exactly once (its own fuel from its purchase, its F1 portion from its F1 purchase). The lift itself deducts nothing.
 */
class LiftAllocationService
{
    /**
     * Part 23 #8: lift lines that can still hold fuel on the truck. A line can only be Completed once nothing remains on
     * the truck (DispatchLiftingStatusController::fuelLeftOnTruck), so Completed lines are empty by definition. Imported
     * historical lifts were written as Completed without delivery links; counting them made "On Truck" add up every
     * past trip (e.g. 16,206,000 L on a 30,000 L truck) and left every truck Loaded.
     */
    public const CARRYING_STATUSES = ['lifted'];

    /** Sale statuses that can still take a delivery. */
    private const OPEN_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function __construct(
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly StandardSourceAssignmentService $sourceAssignments
    ) {}

    /**
     * Open Depot to Client orders not on a lift yet: "Customer · Sales Order No.", with the purchase they come from
     * and their Ordered Quantity ("PREMIUM - 4,000.00 L").
     *
     * @param  array<int, int>  $exceptHaulIds  a lift being edited: its own orders are listed too
     * @return Collection<int, \stdClass>
     */
    public function directSaleOptions(array $exceptHaulIds = []): Collection
    {
        $sales = $this->openDirectSales(false, ['depot_to_client', 'standard'], $exceptHaulIds);
        // Part 25: the depot liters each order holds per purchase (its own fuel and its F1 portion), which a lift carrying
        // the order may take on top of the purchase's unreserved liters.
        $reserved = $this->depotLitersByPurchase($sales->pluck('sale_id')->all());

        return $sales
            ->map(function (object $sale) use ($reserved): object {
                // Part 25: the Purchase ID the order was sold from, so Fuel Lifting recognises the sale and its purchase.
                $purchase = $sale->delivery_type === 'standard' ? null : $sale->purchase_code;
                $sale->label = ($sale->customer_name ?: $sale->company_name).' · '.($sale->sales_order_number ?: $sale->sale_code).($purchase ? ' · '.$purchase : '');
                $sale->ordered_label = ($purchase ? $purchase.' · ' : '').mb_strtoupper((string) $sale->fuel_name).' - '.number_format($sale->open_liters, 2).' L';
                $sale->reserved_by_purchase = $reserved[(int) $sale->sale_id] ?? [];

                return $sale;
            })
            ->values();
    }

    /**
     * Validates the Delivery Details rows of a Schedule Lift and assigns each order to the lift line carrying its
     * purchase. Field errors are keyed deliveries.N.sale_id; the total is keyed "deliveries".
     *
     * @param  array<int, array<string, mixed>>  $deliveries  each with sale_id
     * @param  array<int, array{purchase_item_id: int, quantity_liters: float}>  $lines  the lift's purchases
     * @param  array<int, int>  $fuelTypeByItem  purchase_item_id => fuel_type_id
     * @param  array<int, int>  $exceptHaulIds  the lift being edited: its own orders count as open
     * @return array<int, array<int, array{sale_id: int, customer_id: int, quantity_liters: float}>> line index => rows
     *
     * @throws ValidationException
     */
    public function resolveDeliveries(array $deliveries, array $lines, array $fuelTypeByItem, array $exceptHaulIds = []): array
    {
        $errors = [];
        $resolved = [];
        $open = $this->openDirectSales(true, ['depot_to_client', 'standard'], $exceptHaulIds)->keyBy('sale_id');
        $seen = [];
        $total = 0.0;

        foreach (array_values($deliveries) as $index => $delivery) {
            $saleId = (int) ($delivery['sale_id'] ?? 0);
            $sale = $open->get($saleId);
            $key = "deliveries.$index.sale_id";

            if (isset($seen[$saleId])) {
                $errors[$key] = 'This order is already in this lift.';

                continue;
            }
            $seen[$saleId] = true;

            if (! $sale) {
                $errors[$key] = DB::table('haul_allocations')->where('sale_id', $saleId)->where('destination_type', 'customer')->where('status', '!=', 'cancelled')->exists()
                    ? 'This order is already on another lift.'
                    : 'Select an open Depot to Client or Standard order.';

                continue;
            }

            // Part 24 #3: a Depot to Client order may take its fuel from several purchases (one order line each). Each
            // portion rides on the lift line that carries its purchase, so every purchase of the order must be on the
            // lift; a Standard order (or a line without a purchase) is matched by its fuel as before.
            $portions = $sale->delivery_type !== 'standard' && count($sale->portions) > 1 ? $sale->portions : [[
                'purchase_item_id' => $sale->delivery_type === 'standard' ? null : $sale->purchase_item_id,
                'purchase_code' => $sale->purchase_code,
                'liters' => $sale->open_liters,
            ]];
            $placed = [];
            $missing = [];
            foreach ($portions as $portion) {
                $lineIndex = null;
                foreach ($lines as $candidate => $line) {
                    $matches = $portion['purchase_item_id']
                        ? (int) $line['purchase_item_id'] === (int) $portion['purchase_item_id']
                        : ($fuelTypeByItem[(int) $line['purchase_item_id']] ?? null) === (int) $sale->fuel_type_id;
                    if ($matches) {
                        $lineIndex = $candidate;
                        break;
                    }
                }
                if ($lineIndex === null) {
                    $missing[] = $portion['purchase_code'] ?: 'a '.$sale->fuel_name.' purchase';
                } else {
                    $placed[] = [$lineIndex, $portion];
                }
            }

            if ($missing !== []) {
                $errors[$key] = 'Add '.implode(' and ', array_unique($missing)).' to this lift first: the order is supplied from '.(count($portions) > 1 ? 'each of its purchases' : 'it').'.';

                continue;
            }

            foreach ($placed as [$lineIndex, $portion]) {
                $total = round($total + $portion['liters'], 2);
                $resolved[$lineIndex][] = ['sale_id' => $saleId, 'customer_id' => (int) $sale->customer_id, 'quantity_liters' => round((float) $portion['liters'], 2)];
                // A purchase line can only deliver what it carries.
                $onLine = round((float) collect($resolved[$lineIndex])->sum('quantity_liters'), 2);
                if ($onLine > round((float) $lines[$lineIndex]['quantity_liters'], 2)) {
                    $errors[$key] = 'The orders from '.($portion['purchase_code'] ?: 'this purchase').' add up to '.number_format($onLine, 2).' L, more than its Amount to Lift of '.number_format((float) $lines[$lineIndex]['quantity_liters'], 2).' L.';
                }
            }
        }

        $amountToLift = round((float) collect($lines)->sum('quantity_liters'), 2);
        if ($errors === [] && $total > $amountToLift) {
            $errors['deliveries'] = 'The deliveries add up to '.number_format($total, 2).' L, more than the Amount to Lift of '.number_format($amountToLift, 2).' L.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  array<int, array{sale_id: int, customer_id: int, quantity_liters: float}>  $rows
     */
    public function createForHaul(int $haulId, int $fuelTypeId, array $rows, ?int $actorId = null): void
    {
        foreach ($rows as $row) {
            DB::table('haul_allocations')->insert([
                'haul_id' => $haulId,
                'fuel_type_id' => $fuelTypeId,
                'destination_type' => 'customer',
                'customer_id' => $row['customer_id'],
                'sale_id' => $row['sale_id'],
                'quantity_liters' => $row['quantity_liters'],
                'allocated_at' => now(),
                'status' => 'planned',
                'receive_on_completion' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($actorId) {
                $this->sourceAssignments->convertForHaul((int) $row['sale_id'], $haulId, $actorId);
            }
        }
    }

    /**
     * Runs when a lift is completed by the driver (lifted) or confirmed (completed). Safe to run twice.
     * Delivers each order on the lift, which deducts its liters from its purchases. An order that cannot be
     * delivered yet (e.g. a purchase is short) stays undelivered and is reported.
     *
     * @return array<int, string> notes for the user
     */
    public function settle(int $haulId, int $actorId): array
    {
        $notes = [];
        $saleIds = DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->where('destination_type', 'customer')
            ->where('status', 'planned')
            ->whereNotNull('sale_id')
            ->lockForUpdate()
            ->pluck('sale_id')
            ->unique();

        foreach ($saleIds as $saleId) {
            $sale = DB::table('sales')->where('id', $saleId)->first(['delivery_status', 'sale_code']);
            if (! $sale || in_array($sale->delivery_status, ['delivered', 'cancelled'], true)) {
                continue;
            }

            try {
                // Savepoint: an order that cannot be delivered yet stays undelivered without undoing the lift.
                DB::transaction(fn () => $this->saleConfirmation->updateDeliveryStatus((int) $saleId, 'delivered', $actorId));
            } catch (\RuntimeException $exception) {
                $notes[] = 'Delivery of '.$sale->sale_code.' is on the lift but the order stays undelivered: '.$exception->getMessage();
            }
        }

        return $notes;
    }

    /** Planned deliveries of a cancelled lift no longer hold their orders: the orders can go on another lift. */
    public function cancelForHauls(array $haulIds): void
    {
        DB::table('haul_allocations')->whereIn('haul_id', $haulIds)->where('status', 'planned')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Load of each lift: liters delivered to clients (Depot to Client rows) and the rest, which stays on the truck.
     * Liters of retired garage rows (lifts before October 22, 2026) left the truck too. A lift without rows has 0.
     *
     * @param  array<int, int>  $haulIds
     * @return array<int, array{delivered: float, remaining: float}>
     */
    public function loadSummaryByHaul(array $haulIds): array
    {
        if ($haulIds === []) {
            return [];
        }

        $totals = DB::table('haul_allocations')
            ->whereIn('haul_id', $haulIds)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("haul_id, COALESCE(SUM(CASE WHEN destination_type = 'garage' THEN quantity_liters ELSE 0 END), 0) as garage, COALESCE(SUM(CASE WHEN destination_type = 'customer' THEN quantity_liters ELSE 0 END), 0) as delivered")
            ->groupBy('haul_id')
            ->get()
            ->keyBy('haul_id');

        return DB::table('hauls')
            ->whereIn('id', $haulIds)
            ->pluck('quantity_liters', 'id')
            ->map(function (mixed $quantity, int $haulId) use ($totals): array {
                $garage = round((float) ($totals[$haulId]->garage ?? 0), 2);
                $delivered = round((float) ($totals[$haulId]->delivered ?? 0), 2);

                return ['delivered' => $delivered, 'remaining' => round(max(0, (float) $quantity - $garage - $delivered), 2)];
            })
            ->all();
    }

    /**
     * Part 12: open customer orders (Depot to Client or Standard, "any customer order") that fuel left on a lifted truck
     * can deliver: not on a lift yet, supplied from the purchase the lift line carries (or, for an order recorded before
     * purchases were chosen, of its fuel), and no larger than the liters still on that line.
     *
     * @param  array<int, array{haul_id: int, purchase_item_id: int, fuel_type_id: int, leftover: float}>  $lines
     * @return Collection<int, \stdClass> each with haul_id (the lift line it would come from) and label
     */
    public function truckOrderOptions(array $lines, bool $lock = false, ?Collection $openSales = null): Collection
    {
        if ($lines === []) {
            return collect();
        }

        // Garage to Client orders sell from garage stock, not from a truck.
        return ($openSales ?? $this->openDirectSales($lock, ['depot_to_client', 'standard']))
            ->map(function (object $sale) use ($lines): ?object {
                // Part 24 #3: an order taking fuel from several purchases needs a lift carrying all of them.
                if (count($sale->portions) > 1) {
                    return null;
                }
                $sale = clone $sale;
                foreach ($lines as $line) {
                    $carries = $sale->purchase_item_id
                        ? $sale->purchase_item_id === (int) $line['purchase_item_id']
                        : $sale->fuel_type_id === (int) $line['fuel_type_id'];
                    if ($carries && $sale->open_liters <= round((float) $line['leftover'], 2)) {
                        $sale->haul_id = (int) $line['haul_id'];
                        $sale->label = ($sale->company_name ?: $sale->customer_name).' · '.($sale->sales_order_number ?: $sale->sale_code)
                            .' · '.mb_strtoupper((string) $sale->fuel_name).' '.number_format($sale->open_liters, 2).' L';

                        return $sale;
                    }
                }

                return null;
            })
            ->filter()
            ->values();
    }

    /**
     * Part 25: undelivered liters each open order takes from the depot, per purchase (its own fuel unless it sells from
     * a garage lot, and its F1 portion unless that comes from a garage lot), as PurchaseStockService reserves them.
     *
     * @param  array<int, int>  $saleIds
     * @return array<int, array<int, float>> sale_id => [purchase_item_id => liters]
     */
    private function depotLitersByPurchase(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }
        $released = DB::table('stock_outs')->where('status', 'released')->whereNotNull('sale_item_id')->whereIn('sale_id', $saleIds)
            ->groupBy('sale_item_id')
            ->selectRaw("stock_outs.sale_item_id, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN stock_outs.quantity_liters ELSE 0 END), 0) as f1, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE stock_outs.quantity_liters END), 0) as main")
            ->get()->keyBy('sale_item_id');
        $totals = [];
        DB::table('sale_items')->whereIn('sale_id', $saleIds)
            ->get(['id', 'sale_id', 'purchase_item_id', 'f1_purchase_item_id', 'garage_stock_id', 'f1_garage_stock_id', 'main_quantity_liters', 'f1_quantity_liters'])
            ->each(function (object $item) use ($released, &$totals): void {
                $done = $released->get($item->id);
                $portions = [
                    [$item->garage_stock_id ? null : $item->purchase_item_id, (float) $item->main_quantity_liters - (float) ($done->main ?? 0)],
                    [$item->f1_garage_stock_id ? null : $item->f1_purchase_item_id, (float) $item->f1_quantity_liters - (float) ($done->f1 ?? 0)],
                ];
                foreach ($portions as [$purchaseItemId, $liters]) {
                    if ($purchaseItemId && $liters > 0) {
                        $totals[(int) $item->sale_id][(int) $purchaseItemId] = round(($totals[(int) $item->sale_id][(int) $purchaseItemId] ?? 0) + $liters, 2);
                    }
                }
            });

        return $totals;
    }

    /**
     * Open orders of the given delivery types without a lift (planned or delivered), with their purchase and open liters.
     *
     * @param  array<int, string>  $deliveryTypes
     * @param  array<int, int>  $exceptHaulIds
     * @return Collection<int, \stdClass>
     */
    private function openDirectSales(bool $lock = false, array $deliveryTypes = ['depot_to_client'], array $exceptHaulIds = []): Collection
    {
        // Part 16 #4: editing a lift, its own orders stay selectable ($exceptHaulIds are the lift's hauls).
        $onLift = DB::table('haul_allocations')
            ->select('sale_id')
            ->where('destination_type', 'customer')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('sale_id')
            ->when($exceptHaulIds !== [], fn ($query) => $query->whereNotIn('haul_id', $exceptHaulIds));

        $sales = DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->leftJoin('purchase_items', 'purchase_items.id', '=', 'sale_items.purchase_item_id')
            ->leftJoin('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('sales.delivery_type', $deliveryTypes)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->whereNotIn('sales.id', $onLift)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->groupBy('sales.id', 'sales.sale_code', 'sales.sales_order_number', 'sales.customer_id', 'customers.name', 'customers.company_name', 'sales.sale_date')
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->selectRaw('sales.id as sale_id, sales.sale_code, sales.sales_order_number, sales.customer_id, customers.name as customer_name, customers.company_name, MIN(sales.delivery_type) as delivery_type')
            ->selectRaw('MIN(sale_items.purchase_item_id) as purchase_item_id, MIN(sale_items.fuel_type_id) as fuel_type_id, MIN(fuel_types.name) as fuel_name, MIN(purchases.purchase_code) as purchase_code')
            ->selectRaw('COALESCE(SUM(sale_items.quantity_liters - sale_items.fulfilled_quantity_liters), 0) as open_liters')
            ->get();

        // Part 24 #3: the open liters of each order per purchase (one portion per purchase its lines name).
        $portions = $sales->isEmpty() ? collect() : DB::table('sale_items')
            ->leftJoin('purchase_items', 'purchase_items.id', '=', 'sale_items.purchase_item_id')
            ->leftJoin('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('sale_items.sale_id', $sales->pluck('sale_id')->all())
            ->groupBy('sale_items.sale_id', 'sale_items.purchase_item_id', 'purchases.purchase_code')
            ->orderBy('sale_items.sale_id')
            ->orderByRaw('MIN(sale_items.id)')
            ->selectRaw('sale_items.sale_id, sale_items.purchase_item_id, purchases.purchase_code, COALESCE(SUM(sale_items.quantity_liters - sale_items.fulfilled_quantity_liters), 0) as liters')
            ->get()
            ->groupBy('sale_id');

        return $sales
            ->map(function (object $sale) use ($portions): object {
                $sale->open_liters = round((float) $sale->open_liters, 2);
                $sale->purchase_item_id = $sale->purchase_item_id ? (int) $sale->purchase_item_id : null;
                $sale->fuel_type_id = (int) $sale->fuel_type_id;
                $sale->portions = collect($portions->get($sale->sale_id, []))
                    ->map(fn (object $row): array => ['purchase_item_id' => $row->purchase_item_id ? (int) $row->purchase_item_id : null, 'purchase_code' => $row->purchase_code, 'liters' => round((float) $row->liters, 2)])
                    ->filter(fn (array $row): bool => $row['liters'] > 0)
                    ->values()->all();
                if (count($sale->portions) > 1) {
                    $sale->purchase_code = implode(' + ', array_filter(array_column($sale->portions, 'purchase_code')));
                }

                return $sale;
            })
            ->filter(fn (object $sale): bool => $sale->open_liters > 0)
            ->values();
    }
}
