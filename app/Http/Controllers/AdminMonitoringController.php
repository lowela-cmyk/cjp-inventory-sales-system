<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedgerService;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminMonitoringController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function inventory(Request $request): View
    {
        $search = $this->validatedSearch($request);

        $purchaseRows = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'purchases.purchase_code',
                'depots.name',
                'fuel_types.name',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status',
            ]))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.id',
                'purchases.id as purchase_id',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status as purchase_status',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchase_items.line_total',
                'purchase_items.status as item_status',
            ])
            ->map(fn (object $row): array => [
                'id' => 'purchase-detail-'.$row->id,
                'receipt_url' => $this->isStoredReceipt($row->receipt_reference) ? route('purchase-receipts.show', $row->purchase_id) : null,
                'cells' => [
                    $row->purchase_code,
                    $this->formatDate($row->purchase_date),
                    $row->fuel_name,
                    $row->depot_name,
                    $this->formatNumber($row->quantity_ordered_liters),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber($row->line_total),
                    $this->receiptStatus($row->receipt_reference),
                    $this->label($row->payment_status),
                ],
                'status' => $this->label($row->payment_status),
                'class' => $this->rowClass($row->payment_status),
                'details' => [
                    'Date' => $this->formatDate($row->purchase_date),
                    'Fuel' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'QTY Ordered (L)' => $this->formatLiters($row->quantity_ordered_liters),
                    'QTY Lifted (L)' => $this->formatLiters($row->quantity_hauled_liters),
                    'Cost / Liter' => $this->formatNumber($row->unit_cost),
                    'Total Cost' => $this->formatNumber($row->line_total),
                    'Delivery Receipt' => $this->receiptStatus($row->receipt_reference),
                    'Purchase Status' => $this->label($row->purchase_status),
                    'Item Status' => $this->label($row->item_status),
                    'Payment Status' => $this->label($row->payment_status),
                ],
            ]);

        $stockInRows = DB::table('inventory_movements')
            ->join('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->where('inventory_movements.direction', 'in')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'inventory_movements.movement_code',
                'storage_locations.name',
                'fuel_types.name',
                'inventory_movements.movement_type',
                'inventory_movements.remarks',
            ]))
            ->orderByDesc('inventory_movements.movement_date')
            ->orderByDesc('inventory_movements.id')
            ->get([
                'inventory_movements.id',
                'inventory_movements.movement_code',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'inventory_movements.remarks',
                'storage_locations.name as location_name',
                'fuel_types.name as fuel_name',
            ])
            ->map(fn (object $row): array => [
                'id' => 'stock-detail-'.$row->id,
                'cells' => [
                    $row->movement_code,
                    $this->formatDateTime($row->movement_date),
                    $row->fuel_name,
                    $row->location_name,
                    $this->formatNumber($row->quantity_liters),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber(((float) $row->quantity_liters) * ((float) ($row->unit_cost ?? 0))),
                    $this->formatNumber($row->quantity_liters),
                    $this->stockStatus((float) $row->quantity_liters),
                ],
                'status' => $this->stockStatus((float) $row->quantity_liters),
                'class' => $this->rowClass($this->stockStatus((float) $row->quantity_liters)),
                'details' => [
                    'Movement Type' => $this->label($row->movement_type),
                    'Fuel' => $row->fuel_name,
                    'Location' => $row->location_name,
                    'Quantity' => $this->formatLiters($row->quantity_liters),
                    'Unit Cost' => $this->formatNumber($row->unit_cost),
                    'Reference' => $row->reference_type.' #'.$row->reference_id,
                    'Remarks' => $row->remarks ?: 'N/A',
                ],
            ]);

        $stockOutRows = $this->stockOutRows($search);

        return view('admin.inventory', [
            'search' => $search,
            'purchases' => $purchaseRows,
            'stockIn' => $stockInRows,
            'stockOut' => $stockOutRows,
        ]);
    }

    public function ledger(Request $request, InventoryLedgerService $ledgerService): View
    {
        $search = $this->validatedSearch($request);
        $rows = $ledgerService->rows($search);

        return view('admin.ledger', [
            'search' => $search,
            'ledger' => $rows['ledger'],
            'transactions' => $rows['transactions'],
            'latestBalances' => $rows['latestBalances'],
        ]);
    }

    public function fuelLifting(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $rows = $this->haulRows($search);

        return view('admin.fuel-lifting', [
            'search' => $search,
            'scheduled' => $rows->whereIn('raw_status', ['scheduled', 'in_transit'])->values(),
            'hauled' => $rows->whereIn('raw_status', ['lifted', 'completed'])->values(),
        ]);
    }

    public function sales(Request $request): View
    {
        $search = $this->validatedSearch($request);

        $sales = $this->salesRows($search);
        $customers = DB::table('customers')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
                'payment_status',
            ]))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => 'customer-detail-'.$row->id,
                'cells' => [
                    $row->customer_code,
                    $row->name,
                    $row->company_name,
                    $row->location ?: 'N/A',
                    $row->email ?: 'N/A',
                    $row->phone ?: 'N/A',
                ],
                'details' => [
                    'Customer Name' => $row->name,
                    'Company Name' => $row->company_name,
                    'Location' => $row->location ?: 'N/A',
                    'Email' => $row->email ?: 'N/A',
                    'Contact Number' => $row->phone ?: 'N/A',
                    'Payment Status' => $this->label($row->payment_status),
                    'Account Status' => $this->label($row->status),
                ],
            ]);

        return view('admin.sales', compact('search', 'sales', 'customers'));
    }

    public function alerts(Request $request): View
    {
        $search = $this->validatedSearch($request);

        $alerts = DB::table('alerts')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'alert_code',
                'type',
                'severity',
                'title',
                'message',
                'reference_type',
                'status',
            ]))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => [
                'class' => $row->severity === 'critical' ? 'alert-critical' : 'alert-warning',
                'title' => $row->alert_code.' - '.$row->title,
                'message' => $row->message,
                'time' => $this->formatDateTime($row->created_at),
                'meta' => trim($this->label($row->type).' / '.($row->reference_type ?: '').($row->reference_id ? ' #'.$row->reference_id : '')),
                'status' => $this->label($row->status),
            ]);

        return view('admin.alerts', compact('search', 'alerts'));
    }

    private function stockOutRows(?string $search)
    {
        $currentStock = DB::table('inventory_movements')
            ->selectRaw("fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as current_stock")
            ->groupBy('fuel_type_id');

        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        return DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->join('customers', 'customers.id', '=', 'stock_outs.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_outs.fuel_type_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->leftJoinSub($currentStock, 'current_stock', 'current_stock.fuel_type_id', '=', 'fuel_types.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'stock_outs.stock_out_code',
                'sales.sale_code',
                'customers.name',
                'customers.company_name',
                'fuel_types.name',
                'stock_outs.status',
            ]))
            ->orderByDesc('stock_outs.stock_out_at')
            ->orderByDesc('stock_outs.id')
            ->get([
                'stock_outs.id',
                'stock_outs.stock_out_code',
                'stock_outs.stock_out_at',
                'stock_outs.quantity_liters',
                'stock_outs.status',
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'sale_items.unit_price',
                'sale_items.line_total',
                'payments_total.total_paid',
                'current_stock.current_stock',
            ])
            ->map(fn (object $row): array => [
                'cells' => [
                    $row->sale_code ?: $row->stock_out_code,
                    $this->formatDateTime($row->stock_out_at),
                    $row->customer_name,
                    $row->company_name,
                    $row->fuel_name,
                    $this->formatNumber($row->quantity_liters),
                    $this->formatNumber($row->unit_price),
                    $this->formatNumber($row->line_total),
                    $this->formatNumber($row->total_paid),
                    $this->formatNumber($row->current_stock),
                    $this->label($row->status),
                ],
                'class' => $this->rowClass($row->status),
            ]);
    }

    private function salesRows(?string $search)
    {
        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $items = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->selectRaw('sale_items.sale_id, COUNT(*) as item_count, SUM(sale_items.quantity_liters) as total_quantity_liters, SUM(sale_items.line_total) as sale_total, MIN(fuel_types.name) as first_fuel_name')
            ->groupBy('sale_items.sale_id');

        return DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'sales.sale_code',
                'customers.name',
                'customers.company_name',
                'items_total.first_fuel_name',
                'sales.status',
                'receivables.status',
            ]))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get([
                'sales.id as sale_id',
                'sales.sale_code',
                'sales.sale_date',
                'sales.payment_method',
                'sales.payment_terms',
                'sales.status',
                'customers.name as customer_name',
                'customers.company_name',
                'items_total.item_count',
                'items_total.total_quantity_liters',
                'items_total.sale_total',
                'items_total.first_fuel_name',
                'receivables.due_date',
                'receivables.status as receivable_status',
                'payments_total.total_paid',
            ])
            ->map(function (object $row): array {
                $paid = (float) ($row->total_paid ?? 0);
                $saleTotal = (float) $row->sale_total;
                $balance = max(0, $saleTotal - $paid);
                $status = $this->label($this->salePaymentStatus($saleTotal, $paid));

                return [
                    'id' => 'sales-detail-'.$row->sale_id,
                    'payment_id' => 'payment-history-'.$row->sale_id,
                    'cells' => [
                        $row->sale_code,
                        $this->formatDate($row->sale_date),
                        $row->customer_name,
                        $row->company_name,
                        $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        $this->formatNumber($row->total_quantity_liters),
                        $this->itemPriceSummary((int) $row->sale_id),
                        $this->formatNumber($saleTotal),
                        $this->formatNumber($paid),
                        $this->formatNumber($balance),
                        $status,
                    ],
                    'status' => $status,
                    'class' => $this->rowClass($status),
                    'details' => [
                        'Transaction Date' => $this->formatDate($row->sale_date),
                        'Customer Name' => $row->customer_name,
                        'Company Name' => $row->company_name,
                        'Fuel Type' => $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        'Quantity Ordered' => $this->formatLiters($row->total_quantity_liters),
                        'Quantity Fulfilled' => $this->formatLiters($this->fulfilledQuantityForSale((int) $row->sale_id)),
                        'Price / Unit' => $this->itemPriceSummary((int) $row->sale_id),
                        'Total Price' => $this->formatNumber($saleTotal),
                        'Total Paid' => $this->formatNumber($paid),
                        'Balance' => $this->formatNumber($balance),
                        'Due Date' => $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        'Payment Terms' => $this->label($row->payment_terms),
                        'Payment Method' => $this->paymentMethodLabel($row->payment_method),
                    ],
                    'payments' => $this->paymentsForSale((int) $row->sale_id),
                    'sale_total' => $this->formatNumber($saleTotal),
                    'total_paid' => $this->formatNumber($paid),
                    'balance' => $this->formatNumber($balance),
                ];
            });
    }

    private function haulRows(?string $search)
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'hauls.haul_code',
                'purchases.purchase_code',
                'hauls.dr_number',
                'hauls.source_location',
                'depots.name',
                'fuel_types.name',
                'trucks.truck_code',
                'drivers.name',
                'drivers.phone',
                'hauls.status',
            ]))
            ->orderByDesc('hauls.scheduled_at')
            ->orderByDesc('hauls.id')
            ->get([
                'hauls.id',
                'hauls.haul_code',
                'hauls.dr_number',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.source_location',
                'hauls.quantity_liters',
                'hauls.status',
                'purchases.purchase_code',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'trucks.truck_code',
                'trucks.capacity_liters',
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
            ])
            ->map(fn (object $row): array => [
                'id' => 'lift-detail-'.$row->id,
                'raw_status' => $row->status,
                'cells' => [
                    $row->haul_code,
                    $row->purchase_code,
                    $row->dr_number ?: 'N/A',
                    $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                    $row->source_location ?: $row->depot_name,
                    $row->driver_name,
                    $row->driver_phone ?: 'N/A',
                    $row->truck_code,
                    $this->formatNumber($row->capacity_liters),
                    $this->formatLiters($row->quantity_liters),
                    $this->label($row->status),
                ],
                'status' => $this->label($row->status),
                'class' => $this->rowClass($row->status),
                'details' => [
                    'Purchase ID' => $row->purchase_code,
                    'Fuel Type' => $row->fuel_name,
                    'DR Number' => $row->dr_number ?: 'N/A',
                    'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                    'Hauled Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                    'Location' => $row->source_location ?: $row->depot_name,
                    'Driver' => $row->driver_name,
                    "Driver's Contact" => $row->driver_phone ?: 'N/A',
                    'Truck ID' => $row->truck_code,
                    'Capacity' => $this->formatNumber($row->capacity_liters),
                    'Quantity Lift' => $this->formatLiters($row->quantity_liters),
                ],
            ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function haulsForPurchaseItem(int $purchaseItemId): array
    {
        return DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $row->haul_code.' / DR '.($row->dr_number ?: 'N/A').' / '.$this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                'quantity' => $this->formatLiters($row->quantity_liters),
                'status' => $this->label($row->status),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function paymentsForSale(int $saleId): array
    {
        return DB::table('payments')
            ->leftJoin('users', 'users.id', '=', 'payments.received_by')
            ->where('sale_id', $saleId)
            ->orderBy('payment_date')
            ->orderBy('payments.id')
            ->get([
                'payments.payment_code',
                'payments.payment_date',
                'payments.amount',
                'payments.method',
                'payments.reference_number',
                'users.name as received_by_name',
            ])
            ->map(fn (object $row): array => [
                'code' => $row->payment_code,
                'date' => $this->formatDate($row->payment_date),
                'amount' => $this->formatNumber($row->amount),
                'method' => $this->paymentMethodLabel($row->method),
                'reference' => $row->reference_number ?: 'N/A',
                'recorded_by' => $row->received_by_name ?: 'N/A',
                'status' => 'Recorded',
            ])
            ->all();
    }

    private function validatedSearch(Request $request): ?string
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        return $search === '' ? null : $search;
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
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function paymentMethodLabel(?string $value): string
    {
        return match ($value) {
            'cash_on_delivery' => 'COD',
            'bank_transfer' => 'Banking',
            'advance_payment' => 'Advance Payment',
            'cheque' => 'Cheque',
            default => $value ? $this->label($value) : 'N/A',
        };
    }

    private function fuelSummary(string $firstFuel, int $itemCount): string
    {
        if ($itemCount <= 1) {
            return $firstFuel;
        }

        return trim($firstFuel).' + '.($itemCount - 1);
    }

    private function itemPriceSummary(int $saleId): string
    {
        $prices = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->distinct()
            ->orderBy('unit_price')
            ->pluck('unit_price')
            ->map(fn (mixed $price): string => $this->formatNumber($price));

        return $prices->count() === 1 ? $prices->first() : 'Mixed';
    }

    private function fulfilledQuantityForSale(int $saleId): float
    {
        return (float) DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->sum('fulfilled_quantity_liters');
    }

    private function salePaymentStatus(float $saleTotal, float $totalPaid): string
    {
        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        return round($totalPaid, 2) >= round($saleTotal, 2) ? 'paid' : 'partially_paid';
    }

    private function rowClass(?string $status): string
    {
        return match (strtolower(str_replace(' ', '_', (string) $status))) {
            'unpaid', 'overdue', 'cancelled', 'critical', 'depleted' => 'row-danger',
            'partial', 'partially_paid', 'partially_hauled', 'pending', 'scheduled', 'in_transit', 'low_stock', 'incomplete' => 'row-warning',
            'paid', 'clear', 'hauled', 'lifted', 'completed', 'delivered', 'available', 'released' => 'row-success',
            default => '',
        };
    }

    private function stockStatus(float $liters): string
    {
        return match (true) {
            $liters <= 0 => 'Depleted',
            $liters < 15000 => 'Low Stock',
            default => 'Available',
        };
    }

    private function isStoredReceipt(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'purchase-receipts/')
            && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path);
    }

    private function receiptStatus(?string $path): string
    {
        $reference = trim((string) $path);

        if ($reference === '') {
            return 'No Receipt';
        }

        return $this->isStoredReceipt($reference) ? 'Submitted' : 'Submitted ('.$reference.')';
    }

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }
}
