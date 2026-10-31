<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminDashboardExportController;
use App\Http\Controllers\AdminDashboardInsightController;
use App\Http\Controllers\AdminMonitoringController;
use App\Http\Controllers\AdminUserManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DispatchDeliveryController;
use App\Http\Controllers\DispatchLiftingStatusController;
use App\Http\Controllers\DriverDeliveryController;
use App\Http\Controllers\DriverLiftingStatusController;
use App\Http\Controllers\HaulTruckAssignmentController;
use App\Http\Controllers\LiftLeftoverController;
use App\Http\Controllers\InventoryOfficerLedgerController;
use App\Http\Controllers\InventoryOfficerPurchaseController;
use App\Http\Controllers\SalesOfficerCustomerController;
use App\Http\Controllers\TruckController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'showLogin'])->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register.store');
Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetCode'])->middleware('throttle:password-reset')->name('password.email');
Route::get('/reset-password', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.update');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [AuthController::class, 'redirectToDashboard'])->name('dashboard');
    Route::get('/home', [AuthController::class, 'redirectToDashboard'])->name('home.dashboard');
    Route::get('/withdrawal-receipts/{haul}', [InventoryOfficerPurchaseController::class, 'withdrawalReceipt'])
        // Part 11: the driver of the lift may view the receipt they uploaded (checked in the controller).
        ->middleware('role:admin,inventory_officer,driver')
        ->name('withdrawal-receipts.show');

    Route::redirect('/admin', '/admin/dashboard')->middleware('role:admin')->name('admin.shortcut');
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        // Part 10: "Insights and A.I Reporting" (the inventory variance explanation is part of it).
        Route::post('/dashboard/insight', AdminDashboardInsightController::class)->name('dashboard.insight');
        // Part 11: the dashboard's charts, KPI cards and AI insight as one PDF report.
        Route::post('/dashboard/export', AdminDashboardExportController::class)->name('dashboard.export');
        Route::get('/inventory', [InventoryOfficerPurchaseController::class, 'index'])->name('inventory');
        Route::post('/inventory/purchases', [InventoryOfficerPurchaseController::class, 'store'])->name('inventory.purchases.store');
        Route::patch('/inventory/purchases/{purchaseItem}', [InventoryOfficerPurchaseController::class, 'update'])->name('inventory.purchases.update');
        Route::patch('/inventory/purchases/{purchaseItem}/cancel', [InventoryOfficerPurchaseController::class, 'cancel'])->name('inventory.purchases.cancel');
        // Part 2 revisions: Stock-In (tank receipts) is retired; the old URL opens Purchases.
        Route::get('/inventory/stock-in', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-in')->name('inventory.stock-in');
        Route::patch('/inventory/stock-in/{purchaseItem}', [InventoryOfficerPurchaseController::class, 'updateStockIn'])->name('inventory.stock-in.update');
        Route::get('/inventory/stock-out', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-out')->name('inventory.stock-out');
        Route::patch('/inventory/stock-out/{stockOut}', [InventoryOfficerPurchaseController::class, 'updateStockOut'])->name('inventory.stock-out.update');
        Route::post('/inventory/depots', [InventoryOfficerPurchaseController::class, 'storeDepot'])->name('inventory.depots.store');
        Route::get('/inventory/depots', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'depots')->name('inventory.depots');
        Route::get('/inventory/garage', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'garage')->name('inventory.garage');
        Route::get('/ledger', InventoryOfficerLedgerController::class)->name('ledger');
        Route::get('/ledger/transactions', InventoryOfficerLedgerController::class)->defaults('state', 'transactions')->name('ledger.transactions');
        // Admin works the full Dispatch page (schedule, edit truck, status, on-behalf driver receipts).
        Route::get('/fuel-lifting', [DispatchDeliveryController::class, 'index'])->name('fuel-lifting');
        Route::get('/fuel-lifting/hauled', [DispatchDeliveryController::class, 'index'])->defaults('state', 'hauled')->name('fuel-lifting.hauled');
        Route::post('/fuel-lifting/hauls', [DispatchDeliveryController::class, 'store'])->name('fuel-lifting.hauls.store');
        Route::post('/fuel-lifting/hauls/{haul}/withdrawal-receipt', [DriverDeliveryController::class, 'storeWithdrawalReceipt'])->name('fuel-lifting.hauls.withdrawal-receipt.store');
        // Trucks is a tab of Fuel Lifting; the old page URL keeps working.
        Route::get('/fuel-lifting/trucks', [DispatchDeliveryController::class, 'index'])->defaults('state', 'trucks')->name('fuel-lifting.trucks');
        Route::get('/fuel-lifting/garage', [DispatchDeliveryController::class, 'index'])->defaults('state', 'garage')->name('fuel-lifting.garage');
        Route::get('/trucks', fn (Request $request) => redirect()->route('admin.fuel-lifting.trucks', $request->query()))->name('trucks');
        Route::post('/trucks', [TruckController::class, 'store'])->name('trucks.store');
        Route::patch('/trucks/{truck}', [TruckController::class, 'update'])->name('trucks.update');
        Route::patch('/trucks/{truck}/status', [TruckController::class, 'toggleStatus'])->name('trucks.status');
        Route::patch('/fuel-lifting/hauls/{haul}/truck', [HaulTruckAssignmentController::class, 'update'])->name('fuel-lifting.hauls.truck');
        // Part 16 #4: edit a lift while it is still Scheduled.
        Route::patch('/fuel-lifting/hauls/{haul}/lift', [DispatchDeliveryController::class, 'updateLift'])->name('fuel-lifting.hauls.lift');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DispatchLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::delete('/fuel-lifting/hauls/{haul}', [DispatchLiftingStatusController::class, 'destroy'])->name('fuel-lifting.hauls.destroy');
        Route::post('/fuel-lifting/hauls/{haul}/truck-deliveries', [LiftLeftoverController::class, 'deliver'])->name('fuel-lifting.hauls.truck-deliveries.store');
        Route::post('/fuel-lifting/hauls/{haul}/unload-to-garage', [LiftLeftoverController::class, 'unloadToGarage'])->name('fuel-lifting.hauls.unload-to-garage');
        // Admin works the full Sales Officer page (sales, payments, customers).
        Route::get('/sales', [SalesOfficerCustomerController::class, 'index'])->name('sales');
        Route::get('/sales/customers', [SalesOfficerCustomerController::class, 'index'])->defaults('state', 'customers')->name('sales.customers');
        Route::post('/sales/customers', [SalesOfficerCustomerController::class, 'store'])->name('sales.customers.store');
        Route::patch('/sales/customers/{customer}', [SalesOfficerCustomerController::class, 'update'])->name('sales.customers.update');
        Route::patch('/sales/customers/{customer}/deactivate', [SalesOfficerCustomerController::class, 'deactivate'])->name('sales.customers.deactivate');
        Route::post('/sales', [SalesOfficerCustomerController::class, 'storeSale'])->name('sales.store');
        Route::post('/sales/{sale}/payments', [SalesOfficerCustomerController::class, 'storePayment'])->name('sales.payments.store');
        Route::patch('/sales/{sale}', [SalesOfficerCustomerController::class, 'updateSale'])->name('sales.update');
        Route::patch('/sales/{sale}/delivery-status', [SalesOfficerCustomerController::class, 'updateDeliveryStatus'])->name('sales.delivery-status.update');
        Route::patch('/sales/{sale}/cancel', [SalesOfficerCustomerController::class, 'cancelSale'])->name('sales.cancel');
        Route::delete('/sales/{sale}', [SalesOfficerCustomerController::class, 'destroySale'])->name('sales.destroy');
        // Part 12: Reports was removed (the Dashboard has Insights and A.I Reporting); old links open the Dashboard.
        Route::any('/reports/{rest?}', fn () => redirect()->route('admin.dashboard'))->where('rest', '.*')->name('reports.retired');
        Route::get('/alerts', [AdminMonitoringController::class, 'alerts'])->name('alerts');
        Route::patch('/alerts/{alert}/read', [AdminMonitoringController::class, 'markAlertRead'])->name('alerts.read');
        Route::get('/alerts/{alert}/view', [AdminMonitoringController::class, 'viewAlert'])->name('alerts.view');
        Route::get('/account-requests', [AdminUserManagementController::class, 'accountRequests'])->name('account-requests');
        Route::patch('/account-requests/{user}', [AdminUserManagementController::class, 'updateApproval'])->name('account-requests.update');
        Route::get('/user-management', [AdminUserManagementController::class, 'index'])->name('user-management');
        Route::get('/user-management/export', [AdminUserManagementController::class, 'export'])->name('user-management.export');
        Route::post('/user-management/staff', [AdminUserManagementController::class, 'storeStaff'])->name('user-management.staff.store');
        Route::patch('/user-management/staff/{user}', [AdminUserManagementController::class, 'updateStaff'])->name('user-management.staff.update');
        Route::post('/user-management/drivers', [AdminUserManagementController::class, 'storeDriver'])->name('user-management.drivers.store');
        Route::patch('/user-management/drivers/{user}', [AdminUserManagementController::class, 'updateDriver'])->name('user-management.drivers.update');
        Route::patch('/user-management/users/{user}/status', [AdminUserManagementController::class, 'updateStatus'])->name('user-management.users.status');
    });

    Route::redirect('/dispatch', '/dispatch/fuel-lifting')->middleware('role:dispatch_officer')->name('dispatch.shortcut');
    Route::prefix('dispatch')->name('dispatch.')->middleware('role:dispatch_officer')->group(function () {
        Route::get('/fuel-lifting', [DispatchDeliveryController::class, 'index'])->name('fuel-lifting');
        // Trucks is a tab of Fuel Lifting; the old page URL keeps working.
        Route::get('/fuel-lifting/trucks', [DispatchDeliveryController::class, 'index'])->defaults('state', 'trucks')->name('fuel-lifting.trucks');
        Route::get('/fuel-lifting/garage', [DispatchDeliveryController::class, 'index'])->defaults('state', 'garage')->name('fuel-lifting.garage');
        Route::get('/trucks', fn (Request $request) => redirect()->route('dispatch.fuel-lifting.trucks', $request->query()))->name('trucks');
        Route::post('/trucks', [TruckController::class, 'store'])->name('trucks.store');
        Route::patch('/trucks/{truck}', [TruckController::class, 'update'])->name('trucks.update');
        Route::patch('/trucks/{truck}/status', [TruckController::class, 'toggleStatus'])->name('trucks.status');
        Route::post('/fuel-lifting/hauls', [DispatchDeliveryController::class, 'store'])->name('fuel-lifting.hauls.store');
        Route::patch('/fuel-lifting/hauls/{haul}/truck', [HaulTruckAssignmentController::class, 'update'])->name('fuel-lifting.hauls.truck');
        // Part 16 #4: edit a lift while it is still Scheduled.
        Route::patch('/fuel-lifting/hauls/{haul}/lift', [DispatchDeliveryController::class, 'updateLift'])->name('fuel-lifting.hauls.lift');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DispatchLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::delete('/fuel-lifting/hauls/{haul}', [DispatchLiftingStatusController::class, 'destroy'])->name('fuel-lifting.hauls.destroy');
        Route::post('/fuel-lifting/hauls/{haul}/truck-deliveries', [LiftLeftoverController::class, 'deliver'])->name('fuel-lifting.hauls.truck-deliveries.store');
        Route::post('/fuel-lifting/hauls/{haul}/unload-to-garage', [LiftLeftoverController::class, 'unloadToGarage'])->name('fuel-lifting.hauls.unload-to-garage');
        Route::get('/fuel-lifting/hauled', [DispatchDeliveryController::class, 'index'])->defaults('state', 'hauled')->name('fuel-lifting.hauled');
        Route::get('/ledger', InventoryOfficerLedgerController::class)->name('ledger');
        Route::get('/ledger/transactions', InventoryOfficerLedgerController::class)->defaults('state', 'transactions')->name('ledger.transactions');
        Route::get('/alerts', [AdminMonitoringController::class, 'dispatchAlerts'])->name('alerts');
        Route::patch('/alerts/{alert}/read', [AdminMonitoringController::class, 'markAlertRead'])->name('alerts.read');
        Route::get('/alerts/{alert}/view', [AdminMonitoringController::class, 'viewAlert'])->name('alerts.view');
    });

    Route::redirect('/inventory-officer', '/inventory-officer/inventory')->middleware('role:inventory_officer')->name('inventory-officer.shortcut');
    Route::prefix('inventory-officer')->name('inventory-officer.')->middleware('role:inventory_officer')->group(function () {
        Route::get('/inventory', [InventoryOfficerPurchaseController::class, 'index'])->name('inventory');
        Route::post('/inventory/purchases', [InventoryOfficerPurchaseController::class, 'store'])->name('inventory.purchases.store');
        Route::patch('/inventory/purchases/{purchaseItem}', [InventoryOfficerPurchaseController::class, 'update'])->name('inventory.purchases.update');
        Route::patch('/inventory/purchases/{purchaseItem}/cancel', [InventoryOfficerPurchaseController::class, 'cancel'])->name('inventory.purchases.cancel');
        // Part 2 revisions: Stock-In (tank receipts) is retired; the old URL opens Purchases.
        Route::get('/inventory/stock-in', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-in')->name('inventory.stock-in');
        Route::patch('/inventory/stock-in/{purchaseItem}', [InventoryOfficerPurchaseController::class, 'updateStockIn'])->name('inventory.stock-in.update');
        Route::get('/inventory/stock-out', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-out')->name('inventory.stock-out');
        Route::patch('/inventory/stock-out/{stockOut}', [InventoryOfficerPurchaseController::class, 'updateStockOut'])->name('inventory.stock-out.update');
        Route::post('/inventory/depots', [InventoryOfficerPurchaseController::class, 'storeDepot'])->name('inventory.depots.store');
        Route::get('/inventory/depots', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'depots')->name('inventory.depots');
        Route::get('/inventory/garage', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'garage')->name('inventory.garage');
        Route::get('/ledger', InventoryOfficerLedgerController::class)->name('ledger');
        Route::get('/ledger/transactions', InventoryOfficerLedgerController::class)->defaults('state', 'transactions')->name('ledger.transactions');
        Route::get('/alerts', [AdminMonitoringController::class, 'inventoryOfficerAlerts'])->name('alerts');
        Route::patch('/alerts/{alert}/read', [AdminMonitoringController::class, 'markAlertRead'])->name('alerts.read');
        Route::get('/alerts/{alert}/view', [AdminMonitoringController::class, 'viewAlert'])->name('alerts.view');
    });

    Route::redirect('/sales-officer', '/sales-officer/sales')->middleware('role:sales_officer')->name('sales-officer.shortcut');
    Route::prefix('sales-officer')->name('sales-officer.')->middleware('role:sales_officer')->group(function () {
        Route::get('/sales', [SalesOfficerCustomerController::class, 'index'])->name('sales');
        Route::post('/sales', [SalesOfficerCustomerController::class, 'storeSale'])->name('sales.store');
        Route::post('/sales/{sale}/payments', [SalesOfficerCustomerController::class, 'storePayment'])->name('sales.payments.store');
        Route::patch('/sales/{sale}', [SalesOfficerCustomerController::class, 'updateSale'])->name('sales.update');
        Route::patch('/sales/{sale}/delivery-status', [SalesOfficerCustomerController::class, 'updateDeliveryStatus'])->name('sales.delivery-status.update');
        Route::patch('/sales/{sale}/cancel', [SalesOfficerCustomerController::class, 'cancelSale'])->name('sales.cancel');
        Route::delete('/sales/{sale}', [SalesOfficerCustomerController::class, 'destroySale'])->name('sales.destroy');
        Route::get('/sales/customers', [SalesOfficerCustomerController::class, 'index'])->defaults('state', 'customers')->name('sales.customers');
        Route::post('/sales/customers', [SalesOfficerCustomerController::class, 'store'])->name('sales.customers.store');
        Route::patch('/sales/customers/{customer}', [SalesOfficerCustomerController::class, 'update'])->name('sales.customers.update');
        Route::patch('/sales/customers/{customer}/deactivate', [SalesOfficerCustomerController::class, 'deactivate'])->name('sales.customers.deactivate');
        Route::get('/alerts', [AdminMonitoringController::class, 'salesOfficerAlerts'])->name('alerts');
        Route::patch('/alerts/{alert}/read', [AdminMonitoringController::class, 'markAlertRead'])->name('alerts.read');
        Route::get('/alerts/{alert}/view', [AdminMonitoringController::class, 'viewAlert'])->name('alerts.view');
    });

    Route::redirect('/driver', '/driver/fuel-lifting')->middleware('role:driver')->name('driver.shortcut');
    Route::prefix('driver')->name('driver.')->middleware('role:driver')->group(function () {
        Route::get('/fuel-lifting', [DriverDeliveryController::class, 'index'])->name('fuel-lifting');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DriverLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::post('/fuel-lifting/hauls/{haul}/withdrawal-receipt', [DriverDeliveryController::class, 'storeWithdrawalReceipt'])->name('fuel-lifting.hauls.withdrawal-receipt.store');
        Route::get('/fuel-lifting/hauled', [DriverDeliveryController::class, 'index'])->defaults('state', 'hauled')->name('fuel-lifting.hauled');
        Route::get('/fuel-lifting/no-schedule', [DriverDeliveryController::class, 'index'])->defaults('state', 'no-schedule')->name('fuel-lifting.no-schedule');
        Route::get('/fuel-lifting/no-hauled', [DriverDeliveryController::class, 'index'])->defaults('state', 'no-hauled')->name('fuel-lifting.no-hauled');
    });
});
