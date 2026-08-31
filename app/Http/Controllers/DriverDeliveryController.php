<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DriverDeliveryController extends Controller
{
    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $rows = $this->rows((int) $request->user()->id, $search === '' ? null : $search);

        return view('driver.fuel-lifting', [
            'activeTab' => in_array($state, ['hauled', 'no-hauled'], true) ? 'hauled' : 'schedule',
            'driverName' => $request->user()->name,
            'search' => $search === '' ? null : $search,
            'scheduleRows' => $rows->whereIn('raw_status', ['scheduled', 'in_transit', 'incomplete'])->values(),
            'hauledRows' => $rows->where('raw_status', 'delivered')->values(),
        ]);
    }

    private function rows(int $driverId, ?string $search)
    {
        return DB::table('deliveries')
            ->join('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->leftJoin('sales', 'sales.id', '=', 'deliveries.sale_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'deliveries.truck_id')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->where('deliveries.driver_user_id', $driverId)
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'deliveries.delivery_code',
                'sales.sale_code',
                'customers.company_name',
                'customers.location',
                'fuel_types.name',
                'trucks.truck_code',
                'deliveries.status',
            ]))
            ->orderByDesc('deliveries.scheduled_at')
            ->orderByDesc('deliveries.id')
            ->get([
                'deliveries.id',
                'deliveries.delivery_code',
                'deliveries.scheduled_at',
                'deliveries.delivered_at',
                'deliveries.scheduled_quantity_liters',
                'deliveries.actual_quantity_liters',
                'deliveries.status',
                'deliveries.source_type',
                'sales.sale_code',
                'customers.company_name',
                'customers.location',
                'fuel_types.name as fuel_name',
                'trucks.truck_code',
                'trucks.capacity_liters',
                'stock_outs.stock_out_code',
                'haul_allocations.id as allocation_id',
            ])
            ->map(function (object $row): array {
                $quantity = (float) ($row->actual_quantity_liters ?? $row->scheduled_quantity_liters ?? 0);
                $sourceRef = $row->source_type === 'garage'
                    ? ($row->stock_out_code ?: 'Garage Release')
                    : ('Allocation #'.($row->allocation_id ?: 'N/A'));

                return [
                    'id' => 'driver-delivery-'.$row->id,
                    'raw_status' => $row->status,
                    'cells' => [
                        $row->delivery_code,
                        $row->sale_code ?: 'N/A',
                        $sourceRef,
                        $this->formatDateTime($row->delivered_at ?: $row->scheduled_at),
                        $row->location ?: $row->company_name,
                        $row->truck_code ?: 'N/A',
                        $this->formatNumber($row->capacity_liters),
                        $this->formatNumber($quantity),
                        $this->label($row->status),
                    ],
                    'details' => [
                        'Delivery Reference' => $row->delivery_code,
                        'Sale Reference' => $row->sale_code ?: 'N/A',
                        'Source' => $this->label($row->source_type),
                        'Source Reference' => $sourceRef,
                        'Customer' => $row->company_name,
                        'Location' => $row->location ?: 'N/A',
                        'Fuel Type' => $row->fuel_name,
                        'Truck-ID' => $row->truck_code ?: 'N/A',
                        'Capacity' => $this->formatLiters($row->capacity_liters),
                        'Quantity to Lift' => $this->formatLiters($row->scheduled_quantity_liters),
                        'Actual Quantity' => $row->actual_quantity_liters ? $this->formatLiters($row->actual_quantity_liters) : 'N/A',
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Delivered Date' => $row->delivered_at ? $this->formatDateTime($row->delivered_at) : 'N/A',
                        'Status' => $this->label($row->status),
                    ],
                ];
            });
    }

    /**
     * @param array<int, string> $columns
     */
    private function search(Builder $query, string $term, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($term, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function label(?string $value): string
    {
        return $value ? ucwords(str_replace('_', ' ', $value)) : 'N/A';
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('M d, Y h:i A') : 'N/A';
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
