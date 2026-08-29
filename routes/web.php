<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'showLogin'])->name('home');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::view('/register', 'auth.register')->name('register');

Route::middleware(['auth'])->group(function () {
    Route::redirect('/admin', '/admin/dashboard')->middleware('role:admin')->name('admin.shortcut');
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::view('/dashboard', 'admin.dashboard')->name('dashboard');
        Route::view('/inventory', 'admin.inventory')->name('inventory');
        Route::view('/ledger', 'admin.ledger')->name('ledger');
        Route::view('/fuel-lifting', 'admin.fuel-lifting')->name('fuel-lifting');
        Route::view('/sales', 'admin.sales')->name('sales');
        Route::view('/reports', 'admin.reports')->name('reports');
        Route::view('/alerts', 'admin.alerts')->name('alerts');
        Route::view('/user-management', 'admin.user-management')->name('user-management');
    });

    Route::redirect('/dispatch', '/dispatch/fuel-lifting')->middleware('role:dispatch_officer')->name('dispatch.shortcut');
    Route::prefix('dispatch')->name('dispatch.')->middleware('role:dispatch_officer')->group(function () {
        Route::view('/fuel-lifting', 'dispatch.fuel-lifting')->name('fuel-lifting');
        Route::view('/fuel-lifting/hauled', 'dispatch.fuel-lifting', ['state' => 'hauled'])->name('fuel-lifting.hauled');
        Route::view('/ledger', 'dispatch.ledger')->name('ledger');
        Route::view('/alerts', 'dispatch.alerts')->name('alerts');
    });

    Route::redirect('/inventory-officer', '/inventory-officer/inventory')->middleware('role:inventory_officer')->name('inventory-officer.shortcut');
    Route::prefix('inventory-officer')->name('inventory-officer.')->middleware('role:inventory_officer')->group(function () {
        Route::view('/inventory', 'inventory-officer.inventory')->name('inventory');
        Route::view('/inventory/stock-in', 'inventory-officer.inventory', ['state' => 'stock-in'])->name('inventory.stock-in');
        Route::view('/inventory/stock-out', 'inventory-officer.inventory', ['state' => 'stock-out'])->name('inventory.stock-out');
        Route::view('/ledger', 'inventory-officer.ledger')->name('ledger');
        Route::view('/ledger/transactions', 'inventory-officer.ledger', ['state' => 'transactions'])->name('ledger.transactions');
        Route::view('/alerts', 'inventory-officer.alerts')->name('alerts');
    });

    Route::redirect('/sales-officer', '/sales-officer/sales')->middleware('role:sales_officer')->name('sales-officer.shortcut');
    Route::prefix('sales-officer')->name('sales-officer.')->middleware('role:sales_officer')->group(function () {
        Route::view('/sales', 'sales-officer.sales')->name('sales');
        Route::view('/sales/customers', 'sales-officer.sales', ['state' => 'customers'])->name('sales.customers');
        Route::view('/alerts', 'sales-officer.alerts')->name('alerts');
    });

    Route::redirect('/driver', '/driver/fuel-lifting')->middleware('role:driver')->name('driver.shortcut');
    Route::prefix('driver')->name('driver.')->middleware('role:driver')->group(function () {
        Route::view('/fuel-lifting', 'driver.fuel-lifting')->name('fuel-lifting');
        Route::view('/fuel-lifting/hauled', 'driver.fuel-lifting', ['state' => 'hauled'])->name('fuel-lifting.hauled');
        Route::view('/fuel-lifting/no-schedule', 'driver.fuel-lifting', ['state' => 'no-schedule'])->name('fuel-lifting.no-schedule');
        Route::view('/fuel-lifting/no-hauled', 'driver.fuel-lifting', ['state' => 'no-hauled'])->name('fuel-lifting.no-hauled');
    });
});
