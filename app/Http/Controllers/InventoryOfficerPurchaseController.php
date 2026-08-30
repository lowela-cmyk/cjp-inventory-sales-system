<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryOfficerPurchaseController extends Controller
{
    private const PURCHASE_STATUSES = ['draft', 'ordered', 'partially_hauled', 'hauled', 'cancelled'];
    private const PAYMENT_STATUSES = ['paid', 'partial', 'unpaid'];

    public function index(Request $request, string $state = 'purchases'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $activeTab = in_array($state, ['purchases', 'stock-in', 'stock-out'], true) ? $state : 'purchases';

        return view('inventory-officer.inventory', [
            'activeTab' => $activeTab,
            'search' => $search === '' ? null : $search,
            'purchases' => $this->purchaseRows($search === '' ? null : $search),
            'stockIn' => $this->stockInRows($search === '' ? null : $search),
            'stockOut' => $this->stockOutRows($search === '' ? null : $search),
            'depots' => DB::table('depots')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'fuelTypes' => DB::table('fuel_types')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'purchaseStatuses' => self::PURCHASE_STATUSES,
            'paymentStatuses' => self::PAYMENT_STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPurchaseData($request);

        DB::transaction(function () use ($request, $data): void {
            $receiptPath = $this->storeReceipt($request);

            $purchaseId = DB::table('purchases')->insertGetId([
                'purchase_code' => $this->nextCode('purchases', 'purchase_code', 'PUR'),
                'depot_id' => $data['depot_id'],
                'purchase_date' => $data['purchase_date'],
                'receipt_reference' => $receiptPath ?: ($data['receipt_reference'] ?? null),
                'payment_status' => $data['payment_status'],
                'status' => $data['status'],
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('purchase_items')->insert([
                'purchase_id' => $purchaseId,
                'fuel_type_id' => $data['fuel_type_id'],
                'quantity_ordered_liters' => $data['quantity_ordered_liters'],
                'unit_cost' => $data['unit_cost'],
                'line_total' => $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']),
                'quantity_hauled_liters' => 0,
                'status' => 'unlifted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()
            ->route('inventory-officer.inventory')
            ->with('status', 'Purchase record created successfully.');
    }

    public function update(Request $request, int $purchaseItem): RedirectResponse
    {
        $data = $this->validatedPurchaseData($request);
        $row = $this->purchaseItemForUpdate($purchaseItem);

        abort_unless($row, 404);

        if ($this->hasDependentActivity($row) && $this->changesProtectedFields($row, $data)) {
            return back()
                ->withErrors(['purchase' => 'This purchase already has hauling activity, so quantity, fuel, depot, and date cannot be changed.'])
                ->withInput();
        }

        DB::transaction(function () use ($request, $row, $data): void {
            $receiptPath = $this->storeReceipt($request);
            $oldReceiptPath = $row->receipt_reference;

            DB::table('purchases')
                ->where('id', $row->purchase_id)
                ->update([
                    'depot_id' => $data['depot_id'],
                    'purchase_date' => $data['purchase_date'],
                    'receipt_reference' => $receiptPath ?: ($data['receipt_reference'] ?? $oldReceiptPath),
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'updated_at' => now(),
                ]);

            DB::table('purchase_items')
                ->where('id', $row->id)
                ->update([
                    'fuel_type_id' => $data['fuel_type_id'],
                    'quantity_ordered_liters' => $data['quantity_ordered_liters'],
                    'unit_cost' => $data['unit_cost'],
                    'line_total' => $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']),
                    'status' => $this->itemStatus((float) $row->quantity_hauled_liters, (float) $data['quantity_ordered_liters']),
                    'updated_at' => now(),
                ]);

            if ($receiptPath && $oldReceiptPath && $oldReceiptPath !== $receiptPath && Storage::disk('local')->exists($oldReceiptPath)) {
                Storage::disk('local')->delete($oldReceiptPath);
            }
        });

        return redirect()
            ->route('inventory-officer.inventory')
            ->with('status', 'Purchase record updated successfully.');
    }

    public function receipt(int $purchase): StreamedResponse
    {
        $row = DB::table('purchases')
            ->where('id', $purchase)
            ->whereNull('deleted_at')
            ->first(['purchase_code', 'receipt_reference']);

        abort_unless($row && $this->isStoredReceipt($row->receipt_reference), 404);

        $extension = pathinfo((string) $row->receipt_reference, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $row->receipt_reference,
            Str::slug($row->purchase_code).'-receipt.'.$extension
        );
    }

    public function cancel(Request $request, int $purchaseItem): RedirectResponse
    {
        $row = $this->purchaseItemForUpdate($purchaseItem);

        abort_unless($row, 404);

        if ($this->hasDependentActivity($row)) {
            return back()
                ->withErrors(['purchase' => 'This purchase already has dependent activity and cannot be cancelled from Purchases.'])
                ->withInput();
        }

        DB::table('purchases')
            ->where('id', $row->purchase_id)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('inventory-officer.inventory')
            ->with('status', 'Purchase record cancelled successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPurchaseData(Request $request): array
    {
        return $request->validate([
            'purchase_date' => ['required', 'date'],
            'depot_id' => ['required', 'integer', Rule::exists('depots', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'fuel_type_id' => ['required', 'integer', Rule::exists('fuel_types', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'quantity_ordered_liters' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'unit_cost' => ['required', 'numeric', 'gte:0', 'max:9999999999.99'],
            'receipt_reference' => ['nullable', 'string', 'max:255'],
            'receipt_file' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'payment_status' => ['required', Rule::in(self::PAYMENT_STATUSES)],
            'status' => ['required', Rule::in(self::PURCHASE_STATUSES)],
        ]);
    }

    private function purchaseRows(?string $search)
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.created_by')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'purchases.purchase_code',
                'depots.name',
                'fuel_types.name',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status',
                'users.name',
            ]))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchase_items.line_total',
                'purchase_items.status as item_status',
                'purchases.purchase_code',
                'purchases.depot_id',
                'purchases.purchase_date',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status as purchase_status',
                'purchases.created_at',
                'purchases.updated_at',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'users.name as created_by_name',
            ])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'modal_id' => 'io-purchase-edit-'.$row->id,
                'purchase_code' => $row->purchase_code,
                'purchase_date' => $row->purchase_date,
                'depot_id' => $row->depot_id,
                'fuel_type_id' => $row->fuel_type_id,
                'quantity_ordered_liters' => $row->quantity_ordered_liters,
                'unit_cost' => $row->unit_cost,
                'receipt_reference' => $row->receipt_reference,
                'receipt_url' => $this->isStoredReceipt($row->receipt_reference) ? route('purchase-receipts.show', $row->purchase_id) : null,
                'payment_status' => $row->payment_status,
                'purchase_status' => $row->purchase_status,
                'has_dependencies' => $this->hasDependentActivity($row),
                'class' => $this->rowClass($row->payment_status),
                'cells' => [
                    $row->purchase_code,
                    $this->formatDate($row->purchase_date),
                    $row->fuel_name,
                    $row->depot_name,
                    $this->formatNumber($row->quantity_ordered_liters),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber($row->line_total),
                    $this->receiptDisplay($row->receipt_reference),
                    $this->label($row->payment_status),
                ],
                'details' => [
                    'Date' => $this->formatDate($row->purchase_date),
                    'Fuel' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'Quantity' => $this->formatLiters($row->quantity_ordered_liters),
                    'Quantity Hauled' => $this->formatLiters($row->quantity_hauled_liters),
                    'Cost/Liter' => $this->formatNumber($row->unit_cost),
                    'Total Cost' => $this->formatNumber($row->line_total),
                    'Delivery Receipt' => $this->receiptDisplay($row->receipt_reference),
                    'Purchase Status' => $this->label($row->purchase_status),
                    'Item Status' => $this->label($row->item_status),
                    'Created By' => $row->created_by_name ?: 'N/A',
                    'Created At' => $this->formatDateTime($row->created_at),
                    'Updated At' => $this->formatDateTime($row->updated_at),
                ],
            ]);
    }

    private function stockInRows(?string $search)
    {
        return DB::table('inventory_movements')
            ->join('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->where('inventory_movements.direction', 'in')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'inventory_movements.movement_code',
                'storage_locations.name',
                'fuel_types.name',
                'inventory_movements.movement_type',
            ]))
            ->orderByDesc('inventory_movements.movement_date')
            ->get([
                'inventory_movements.movement_code',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'storage_locations.name as location_name',
                'fuel_types.name as fuel_name',
            ])
            ->map(fn (object $row): array => [
                $row->movement_code,
                $this->formatDateTime($row->movement_date),
                $row->fuel_name,
                $row->location_name,
                $this->formatNumber($row->quantity_liters),
                $this->formatNumber($row->unit_cost),
                $this->formatNumber(((float) $row->quantity_liters) * ((float) ($row->unit_cost ?? 0))),
                $this->formatNumber($row->quantity_liters),
                '0.00',
                $this->label($row->movement_type),
                '',
            ]);
    }

    private function stockOutRows(?string $search)
    {
        return DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->join('customers', 'customers.id', '=', 'stock_outs.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_outs.fuel_type_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'stock_outs.stock_out_code',
                'sales.sale_code',
                'customers.name',
                'customers.company_name',
                'fuel_types.name',
            ]))
            ->orderByDesc('stock_outs.stock_out_at')
            ->get([
                'stock_outs.stock_out_code',
                'stock_outs.stock_out_at',
                'stock_outs.quantity_liters',
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'sale_items.unit_price',
                'sale_items.line_total',
            ])
            ->map(fn (object $row): array => [
                $row->sale_code ?: $row->stock_out_code,
                $this->formatDateTime($row->stock_out_at),
                $row->customer_name,
                $row->company_name,
                $row->fuel_name,
                $this->formatNumber($row->quantity_liters),
                $this->formatNumber($row->unit_price),
                $this->formatNumber($row->line_total),
                '0.00',
                '0.00',
                '0.00',
                $this->formatNumber($row->line_total),
                '',
            ]);
    }

    private function purchaseItemForUpdate(int $purchaseItem): ?object
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.id', $purchaseItem)
            ->whereNull('purchases.deleted_at')
            ->first([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchases.depot_id',
                'purchases.purchase_date',
                'purchases.receipt_reference',
            ]);
    }

    private function hasDependentActivity(object $row): bool
    {
        return (float) ($row->quantity_hauled_liters ?? 0) > 0
            || DB::table('hauls')->where('purchase_item_id', $row->id)->exists()
            || DB::table('inventory_movements')
                ->where('reference_type', 'purchase_item')
                ->where('reference_id', $row->id)
                ->exists();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function changesProtectedFields(object $row, array $data): bool
    {
        return (int) $row->depot_id !== (int) $data['depot_id']
            || (int) $row->fuel_type_id !== (int) $data['fuel_type_id']
            || (string) $row->purchase_date !== (string) $data['purchase_date']
            || (float) $row->quantity_ordered_liters !== (float) $data['quantity_ordered_liters']
            || (float) $row->unit_cost !== (float) $data['unit_cost'];
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

    private function nextCode(string $table, string $column, string $prefix): string
    {
        $nextId = ((int) DB::table($table)->max('id')) + 1;

        do {
            $code = $prefix.'-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
            $nextId++;
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    private function lineTotal(mixed $quantity, mixed $unitCost): float
    {
        return round(((float) $quantity) * ((float) $unitCost), 2);
    }

    private function storeReceipt(Request $request): ?string
    {
        if (! $request->hasFile('receipt_file')) {
            return null;
        }

        $file = $request->file('receipt_file');
        $extension = $file->guessExtension() ?: $file->extension();
        $filename = (string) Str::uuid().'.'.$extension;

        return $file->storeAs('purchase-receipts', $filename, 'local');
    }

    private function isStoredReceipt(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'purchase-receipts/')
            && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path);
    }

    private function receiptDisplay(?string $path): string
    {
        if ($this->isStoredReceipt($path)) {
            return 'View Receipt';
        }

        return $path ?: 'No receipt uploaded';
    }

    private function itemStatus(float $hauled, float $ordered): string
    {
        return match (true) {
            $hauled <= 0 => 'unlifted',
            $hauled >= $ordered => 'lifted',
            default => 'partial',
        };
    }

    private function rowClass(?string $status): string
    {
        return match ($status) {
            'unpaid', 'cancelled' => 'row-danger',
            'partial' => 'row-warning',
            'paid' => 'row-success',
            default => '',
        };
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
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
