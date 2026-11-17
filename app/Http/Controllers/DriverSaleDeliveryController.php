<?php

namespace App\Http\Controllers;

use App\Services\DriverSaleDeliveryService;
use App\Services\IdempotencyService;
use App\Services\SaleConfirmationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Part 21 #1: the delivery status of an order is updated by its assigned Driver only. Admin and Sales Officers see the
 * status but have no action and no route for it. The `role:driver` route middleware lets Admin through (Admin may work
 * every role's pages), so this controller checks the role and the assignment itself.
 *
 * The change runs through SaleConfirmationService, exactly as before: Delivered deducts the order from stock.
 * Automatic delivery when a lift is marked Lifted (LiftAllocationService::settle) is untouched.
 */
class DriverSaleDeliveryController extends Controller
{
    public function __construct(
        private readonly DriverSaleDeliveryService $deliveries,
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly IdempotencyService $idempotency
    ) {}

    public function updateStatus(Request $request, int $sale): RedirectResponse
    {
        $driver = $request->user();
        abort_unless($driver->role === 'driver', 403, 'Only the assigned driver can update the delivery status.');
        abort_unless(DB::table('sales')->where('id', $sale)->whereNull('deleted_at')->exists(), 404);
        abort_unless($this->deliveries->isAssigned($sale, (int) $driver->id), 403, 'This delivery is not assigned to your driver account.');

        $data = $request->validate([
            'delivery_status' => ['required', Rule::in(DriverSaleDeliveryService::STATUSES)],
            // One key per opened form, so a double tap or a resent request saves once.
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $write = function () use ($sale, $data, $driver): array {
            $this->saleConfirmation->updateDeliveryStatus($sale, (string) $data['delivery_status'], (int) $driver->id);
            // The driver is the last one to change the order (Receivables: "Last updated by").
            DB::table('sales')->where('id', $sale)->update(['updated_at' => now()] + (Schema::hasColumn('sales', 'updated_by') ? ['updated_by' => $driver->id] : []));

            return ['reference_id' => $sale];
        };

        try {
            $result = ($data['idempotency_key'] ?? null)
                ? $this->idempotency->run((string) $data['idempotency_key'], 'driver.deliveries.status', (int) $driver->id, $write)
                : $write();
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->only('delivery_update_sale_id'));
        } catch (\RuntimeException $exception) {
            // The Update Delivery modal of this order reopens with the reason.
            return back()->withErrors(['delivery_status' => $exception->getMessage()])->withInput($request->only('delivery_update_sale_id'));
        }

        return redirect()
            ->route('driver.fuel-lifting.deliveries')
            ->with('status', ($result['duplicate'] ?? false) ? 'This delivery update was already saved.' : 'Delivery status updated successfully.');
    }
}
