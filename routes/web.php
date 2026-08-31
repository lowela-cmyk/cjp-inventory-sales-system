<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminMonitoringController;
use App\Http\Controllers\AdminSalesReportController;
use App\Http\Controllers\AdminUserManagementController;
use App\Http\Controllers\DispatchDeliveryController;
use App\Http\Controllers\DispatchLiftingStatusController;
use App\Http\Controllers\DriverDeliveryController;
use App\Http\Controllers\DriverLiftingStatusController;
use App\Http\Controllers\InventoryOfficerLedgerController;
use App\Http\Controllers\InventoryOfficerPurchaseController;
use App\Http\Controllers\SalesOfficerCustomerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'showLogin'])->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->name('register.store');
Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetCode'])->name('password.email');
Route::get('/reset-password', [AuthController::class, 'showResetPassword'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [AuthController::class, 'redirectToDashboard'])->name('dashboard');
    Route::get('/home', [AuthController::class, 'redirectToDashboard'])->name('home.dashboard');
    Route::get('/purchase-receipts/{purchase}', [InventoryOfficerPurchaseController::class, 'receipt'])
        ->middleware('role:admin,inventory_officer')
        ->name('purchase-receipts.show');

    Route::redirect('/admin', '/admin/dashboard')->middleware('role:admin')->name('admin.shortcut');
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        Route::get('/inventory', [AdminMonitoringController::class, 'inventory'])->name('inventory');
        Route::get('/ledger', [AdminMonitoringController::class, 'ledger'])->name('ledger');
        Route::get('/fuel-lifting', [AdminMonitoringController::class, 'fuelLifting'])->name('fuel-lifting');
        Route::post('/fuel-lifting/deliveries', [DispatchDeliveryController::class, 'store'])->name('fuel-lifting.deliveries.store');
        Route::patch('/fuel-lifting/deliveries/{delivery}/assignment', [DispatchDeliveryController::class, 'updateAssignment'])->name('fuel-lifting.deliveries.assignment');
        Route::patch('/fuel-lifting/deliveries/{delivery}/status', [DispatchDeliveryController::class, 'updateStatus'])->name('fuel-lifting.deliveries.status');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DispatchLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::get('/sales', [AdminMonitoringController::class, 'sales'])->name('sales');
        Route::get('/reports', AdminSalesReportController::class)->name('reports');
        Route::get('/reports/export', [AdminSalesReportController::class, 'export'])->name('reports.export');
        Route::get('/alerts', [AdminMonitoringController::class, 'alerts'])->name('alerts');
        Route::get('/user-management', [AdminUserManagementController::class, 'index'])->name('user-management');
        Route::post('/user-management/staff', [AdminUserManagementController::class, 'storeStaff'])->name('user-management.staff.store');
        Route::patch('/user-management/staff/{user}', [AdminUserManagementController::class, 'updateStaff'])->name('user-management.staff.update');
        Route::post('/user-management/drivers', [AdminUserManagementController::class, 'storeDriver'])->name('user-management.drivers.store');
        Route::patch('/user-management/drivers/{user}', [AdminUserManagementController::class, 'updateDriver'])->name('user-management.drivers.update');
        Route::patch('/user-management/users/{user}/status', [AdminUserManagementController::class, 'updateStatus'])->name('user-management.users.status');
    });

    Route::redirect('/dispatch', '/dispatch/fuel-lifting')->middleware('role:dispatch_officer')->name('dispatch.shortcut');
    Route::prefix('dispatch')->name('dispatch.')->middleware('role:dispatch_officer')->group(function () {
        Route::get('/fuel-lifting', [DispatchDeliveryController::class, 'index'])->name('fuel-lifting');
        Route::post('/fuel-lifting/deliveries', [DispatchDeliveryController::class, 'store'])->name('fuel-lifting.deliveries.store');
        Route::patch('/fuel-lifting/deliveries/{delivery}/assignment', [DispatchDeliveryController::class, 'updateAssignment'])->name('fuel-lifting.deliveries.assignment');
        Route::patch('/fuel-lifting/deliveries/{delivery}/status', [DispatchDeliveryController::class, 'updateStatus'])->name('fuel-lifting.deliveries.status');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DispatchLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::get('/fuel-lifting/hauled', [DispatchDeliveryController::class, 'index'])->defaults('state', 'hauled')->name('fuel-lifting.hauled');
        Route::view('/ledger', 'dispatch.ledger')->name('ledger');
        Route::view('/alerts', 'dispatch.alerts')->name('alerts');
    });

    Route::redirect('/inventory-officer', '/inventory-officer/inventory')->middleware('role:inventory_officer')->name('inventory-officer.shortcut');
    Route::prefix('inventory-officer')->name('inventory-officer.')->middleware('role:inventory_officer')->group(function () {
        Route::get('/inventory', [InventoryOfficerPurchaseController::class, 'index'])->name('inventory');
        Route::post('/inventory/purchases', [InventoryOfficerPurchaseController::class, 'store'])->name('inventory.purchases.store');
        Route::patch('/inventory/purchases/{purchaseItem}', [InventoryOfficerPurchaseController::class, 'update'])->name('inventory.purchases.update');
        Route::patch('/inventory/purchases/{purchaseItem}/cancel', [InventoryOfficerPurchaseController::class, 'cancel'])->name('inventory.purchases.cancel');
        Route::post('/inventory/stock-in', [InventoryOfficerPurchaseController::class, 'storeStockIn'])->name('inventory.stock-in.store');
        Route::get('/inventory/stock-in', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-in')->name('inventory.stock-in');
        Route::post('/inventory/stock-out', [InventoryOfficerPurchaseController::class, 'storeStockOut'])->name('inventory.stock-out.store');
        Route::get('/inventory/stock-out', [InventoryOfficerPurchaseController::class, 'index'])->defaults('state', 'stock-out')->name('inventory.stock-out');
        Route::get('/ledger', InventoryOfficerLedgerController::class)->name('ledger');
        Route::get('/ledger/transactions', InventoryOfficerLedgerController::class)->defaults('state', 'transactions')->name('ledger.transactions');
        Route::view('/alerts', 'inventory-officer.alerts')->name('alerts');
    });

    Route::redirect('/sales-officer', '/sales-officer/sales')->middleware('role:sales_officer')->name('sales-officer.shortcut');
    Route::prefix('sales-officer')->name('sales-officer.')->middleware('role:sales_officer')->group(function () {
        Route::get('/sales', [SalesOfficerCustomerController::class, 'index'])->name('sales');
        Route::post('/sales', [SalesOfficerCustomerController::class, 'storeSale'])->name('sales.store');
        Route::post('/sales/{sale}/payments', [SalesOfficerCustomerController::class, 'storePayment'])->name('sales.payments.store');
        Route::patch('/sales/{sale}', [SalesOfficerCustomerController::class, 'updateSale'])->name('sales.update');
        Route::patch('/sales/{sale}/cancel', [SalesOfficerCustomerController::class, 'cancelSale'])->name('sales.cancel');
        Route::get('/sales/customers', [SalesOfficerCustomerController::class, 'index'])->defaults('state', 'customers')->name('sales.customers');
        Route::post('/sales/customers', [SalesOfficerCustomerController::class, 'store'])->name('sales.customers.store');
        Route::patch('/sales/customers/{customer}', [SalesOfficerCustomerController::class, 'update'])->name('sales.customers.update');
        Route::patch('/sales/customers/{customer}/deactivate', [SalesOfficerCustomerController::class, 'deactivate'])->name('sales.customers.deactivate');
        Route::view('/alerts', 'sales-officer.alerts')->name('alerts');
    });

    Route::redirect('/driver', '/driver/fuel-lifting')->middleware('role:driver')->name('driver.shortcut');
    Route::prefix('driver')->name('driver.')->middleware('role:driver')->group(function () {
        Route::get('/fuel-lifting', [DriverDeliveryController::class, 'index'])->name('fuel-lifting');
        Route::patch('/fuel-lifting/hauls/{haul}/status', [DriverLiftingStatusController::class, 'updateStatus'])->name('fuel-lifting.hauls.status');
        Route::get('/fuel-lifting/hauled', [DriverDeliveryController::class, 'index'])->defaults('state', 'hauled')->name('fuel-lifting.hauled');
        Route::get('/fuel-lifting/no-schedule', [DriverDeliveryController::class, 'index'])->defaults('state', 'no-schedule')->name('fuel-lifting.no-schedule');
        Route::get('/fuel-lifting/no-hauled', [DriverDeliveryController::class, 'index'])->defaults('state', 'no-hauled')->name('fuel-lifting.no-hauled');
    });
});
