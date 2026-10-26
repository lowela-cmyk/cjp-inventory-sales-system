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
    /** Sale statuses that can still take a delivery. */
    private const OPEN_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function __construct(
        private readonly SaleConfirmationService $saleConfirmation
    ) {}

    /**
     * Open Depot to Client orders not on a lift yet: "Customer · Sales Order No.", with the purchase they come from
     * and their Ordered Quantity ("PREMIUM - 4,000.00 L").
     *
     * @return Collection<int, \stdClass>
     */
    public function directSaleOptions(): Collection
    {
        return $this->openDirectSales()
            ->map(function (object $sale): object {
                $sale->label = ($sale->customer_name ?: $sale->company_name).' · '.($sale->sales_order_number ?: $sale->sale_code);
                $sale->ordered_label = mb_strtoupper((string) $sale->fuel_name).' - '.number_format($sale->open_liters, 2).' L';

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
     * @return array<int, array<int, array{sale_id: int, customer_id: int, quantity_liters: float}>> line index => rows
     *
     * @throws ValidationException
     */
    public function resolveDeliveries(array $deliveries, array $lines, array $fuelTypeByItem): array
    {
        $errors = [];
        $resolved = [];
        $open = $this->openDirectSales(true)->keyBy('sale_id');
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
                    : 'Select an open Depot to Client order.';

                continue;
            }

            // The lift line that carries the order's purchase (or its fuel, for an order without a purchase).
            $lineIndex = null;
            foreach ($lines as $candidate => $line) {
                $matches = $sale->purchase_item_id
                    ? (int) $line['purchase_item_id'] === (int) $sale->purchase_item_id
                    : ($fuelTypeByItem[(int) $line['purchase_item_id']] ?? null) === (int) $sale->fuel_type_id;
                if ($matches) {
                    $lineIndex = $candidate;
                    break;
                }
            }

            if ($lineIndex === null) {
                $errors[$key] = 'Add '.($sale->purchase_code ?: 'a '.$sale->fuel_name.' purchase').' to this lift first: the order is supplied from it.';

                continue;
            }

            $total = round($total + $sale->open_liters, 2);
            $resolved[$lineIndex][] = ['sale_id' => $saleId, 'customer_id' => (int) $sale->customer_id, 'quantity_liters' => $sale->open_liters];
            // A purchase line can only deliver what it carries.
            $onLine = round((float) collect($resolved[$lineIndex])->sum('quantity_liters'), 2);
            if ($onLine > round((float) $lines[$lineIndex]['quantity_liters'], 2)) {
                $errors[$key] = 'The orders from '.($sale->purchase_code ?: 'this purchase').' add up to '.number_format($onLine, 2).' L, more than its Amount to Lift of '.number_format((float) $lines[$lineIndex]['quantity_liters'], 2).' L.';
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
    public function createForHaul(int $haulId, int $fuelTypeId, array $rows): void
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
    public function truckOrderOptions(array $lines, bool $lock = false): Collection
    {
        if ($lines === []) {
            return collect();
        }

        return $this->openDirectSales($lock, ['depot_to_client', 'standard', 'garage_to_client'])
            ->map(function (object $sale) use ($lines): ?object {
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
     * Open orders of the given delivery types without a lift (planned or delivered), with their purchase and open liters.
     *
     * @param  array<int, string>  $deliveryTypes
     * @return Collection<int, \stdClass>
     */
    private function openDirectSales(bool $lock = false, array $deliveryTypes = ['depot_to_client']): Collection
    {
        $onLift = DB::table('haul_allocations')
            ->select('sale_id')
            ->where('destination_type', 'customer')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('sale_id');

        return DB::table('sales')
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
            ->get()
            ->map(function (object $sale): object {
                $sale->open_liters = round((float) $sale->open_liters, 2);
                $sale->purchase_item_id = $sale->purchase_item_id ? (int) $sale->purchase_item_id : null;
                $sale->fuel_type_id = (int) $sale->fuel_type_id;

                return $sale;
            })
            ->filter(fn (object $sale): bool => $sale->open_liters > 0)
            ->values();
    }
}
