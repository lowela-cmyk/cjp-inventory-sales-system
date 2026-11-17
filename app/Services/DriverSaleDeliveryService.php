<?php

namespace App\Services;

use App\Support\TruckLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Part 21 #1: the delivery status of an order is the assigned Driver's responsibility. This service answers who that
 * driver is and what they may do next; the status change itself (stock-out included) stays in SaleConfirmationService.
 *
 * A sale is assigned to a driver when
 * - its Delivery Details name them (sales.delivery_driver_user_id: Garage to Client, or a Standard order with a driver), or
 * - it rides on one of their lifts (a customer delivery row of a lift that is not cancelled: Depot to Client).
 */
class DriverSaleDeliveryService
{
    /** The statuses a driver may set. Cancelling an order stays a Sales action (Cancel Sale). */
    public const STATUSES = ['pending', 'out_for_delivery', 'delivered'];

    public function isAssigned(int $saleId, int $driverId): bool
    {
        return $this->assignedSales($driverId)->where('sales.id', $saleId)->exists();
    }

    /** Sales (not deleted) assigned to the driver. */
    public function assignedSales(int $driverId): Builder
    {
        return DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where(fn (Builder $query): Builder => $query
                ->where('sales.delivery_driver_user_id', $driverId)
                ->orWhereExists(fn (Builder $lift): Builder => $this->customerLiftRows($lift)
                    ->whereColumn('haul_allocations.sale_id', 'sales.id')
                    ->where('hauls.driver_user_id', $driverId)));
    }

    /**
     * What the driver may do with the delivery now: the existing Pending <-> Out for Delivery -> Delivered flow. A
     * draft, cancelled or delivered order has no action. A Depot to Client order offers Delivered only once its lift
     * is Lifted (SaleConfirmationService refuses it before that anyway).
     *
     * @return array{available: bool, current: string, options: array<int, string>, note: ?string}
     */
    public function action(string $saleStatus, ?string $deliveryStatus, string $deliveryType, bool $onLiftedLift): array
    {
        $current = $deliveryStatus ?: 'pending';
        $open = ! in_array($current, ['delivered', 'cancelled'], true) && ! in_array($saleStatus, ['draft', 'cancelled'], true);
        $canDeliver = $deliveryType !== 'depot_to_client' || $onLiftedLift;
        $options = $open
            ? array_values(array_filter(self::STATUSES, fn (string $status): bool => $status !== $current && ($status !== 'delivered' || $canDeliver)))
            : [];

        return [
            'available' => $options !== [],
            'current' => $current,
            'options' => $options,
            'note' => $open && ! $canDeliver ? 'Delivered is set when this order\'s lift is marked Lifted.' : null,
        ];
    }

    /**
     * The Deliveries tab of the driver: their assigned orders, open ones first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $driverId, ?string $search = null): Collection
    {
        $sales = $this->assignedSales($driverId)
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'sales.delivery_truck_id')
            ->leftJoin('users as delivery_updaters', 'delivery_updaters.id', '=', 'sales.delivery_status_updated_by')
            ->where('sales.status', '!=', 'draft')
            ->when($search, fn (Builder $query, string $term): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('sales.sale_code', 'like', '%'.$term.'%')
                ->orWhere('sales.sales_order_number', 'like', '%'.$term.'%')
                ->orWhere('customers.name', 'like', '%'.$term.'%')
                ->orWhere('customers.company_name', 'like', '%'.$term.'%')))
            ->orderByRaw("CASE WHEN sales.delivery_status IN ('delivered', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByDesc('sales.id')
            ->limit(200)
            ->get([
                'sales.id', 'sales.sale_code', 'sales.sales_order_number', 'sales.status', 'sales.delivery_type', 'sales.delivery_status',
                'sales.delivery_date', 'sales.delivered_at', 'sales.delivery_status_updated_at',
                'customers.name as customer_name', 'customers.company_name', 'customers.location',
                'trucks.name as truck_name', 'trucks.truck_code',
                'delivery_updaters.name as delivery_updated_by_name',
            ]);
        $saleIds = $sales->pluck('id')->all();

        $fuelBySale = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->whereIn('sale_items.sale_id', $saleIds)
            ->orderBy('sale_items.id')
            ->get(['sale_items.sale_id', 'sale_items.quantity_liters', 'fuel_types.name'])
            ->groupBy('sale_id');
        $liftsBySale = $this->customerLiftRows(DB::table('haul_allocations'))
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->whereIn('haul_allocations.sale_id', $saleIds)
            ->orderBy('hauls.id')
            ->get(['haul_allocations.sale_id', 'hauls.haul_code', 'hauls.status', 'hauls.scheduled_at', 'hauls.hauled_at', 'trucks.name as truck_name', 'trucks.truck_code'])
            ->groupBy('sale_id');

        return $sales->map(function (object $sale) use ($fuelBySale, $liftsBySale): array {
            $lifts = $liftsBySale->get($sale->id, collect());
            $lift = $lifts->first();
            $items = $fuelBySale->get($sale->id, collect());
            $date = $sale->delivered_at ?: ($sale->delivery_date ?: ($lift->hauled_at ?? $lift->scheduled_at ?? null));

            return [
                'id' => (int) $sale->id,
                'modal_id' => 'driver-delivery-'.$sale->id,
                'sale_code' => $sale->sale_code,
                'sales_order_number' => $sale->sales_order_number ?: 'N/A',
                'delivery_type_label' => ucwords(str_replace('_', ' ', (string) $sale->delivery_type)),
                'customer' => $sale->company_name ?: $sale->customer_name,
                'location' => $sale->location ?: 'N/A',
                'fuel' => $items->pluck('name')->unique()->implode(', ') ?: 'N/A',
                'quantity' => number_format((float) $items->sum('quantity_liters'), 2).' L',
                'truck' => $sale->truck_code
                    ? TruckLabel::make($sale->truck_name, $sale->truck_code)
                    : ($lift ? TruckLabel::make($lift->truck_name, $lift->truck_code) : 'N/A'),
                'lift' => $lifts->pluck('haul_code')->unique()->implode(', ') ?: 'N/A',
                'date' => $date ? CarbonImmutable::parse($date)->format('M d, Y h:i A') : 'N/A',
                'delivery_status' => $sale->delivery_status ?: 'pending',
                'delivery_update' => $this->action(
                    (string) $sale->status,
                    $sale->delivery_status,
                    (string) $sale->delivery_type,
                    $lifts->contains(fn (object $row): bool => in_array($row->status, ['lifted', 'completed'], true))
                ) + [
                    'last_update' => $sale->delivery_status_updated_at
                        ? ($sale->delivery_updated_by_name ?: 'N/A').' · '.CarbonImmutable::parse($sale->delivery_status_updated_at)->format('n/j/Y h:i A')
                        : null,
                ],
            ];
        });
    }

    /** Customer delivery rows of lifts that still count (the row and its lift are not cancelled or deleted). */
    private function customerLiftRows(Builder $query): Builder
    {
        return $query
            ->from('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('haul_allocations.destination_type', 'customer')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', '!=', 'cancelled')
            ->whereNull('hauls.deleted_at');
    }
}
