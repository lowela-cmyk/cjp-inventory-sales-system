<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])->group(function () {
    Route::view('/', 'auth.login')->name('home');
    Route::view('/login', 'auth.login')->name('login');

    Route::redirect('/admin', '/admin/dashboard')->name('admin.shortcut');
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::view('/dashboard', 'admin.dashboard')->name('dashboard');
        Route::view('/inventory', 'admin.inventory')->name('inventory');
        Route::view('/ledger', 'admin.ledger')->name('ledger');
        Route::view('/fuel-lifting', 'admin.fuel-lifting')->name('fuel-lifting');
        Route::view('/sales', 'admin.sales')->name('sales');
        Route::view('/reports', 'admin.reports')->name('reports');
        Route::view('/alerts', 'admin.alerts')->name('alerts');
        Route::view('/user-management', 'admin.user-management')->name('user-management');
    });

    Route::redirect('/dispatch', '/dispatch/fuel-lifting')->name('dispatch.shortcut');
    Route::prefix('dispatch')->name('dispatch.')->group(function () {
        Route::view('/fuel-lifting', 'dispatch.fuel-lifting')->name('fuel-lifting');
        Route::view('/ledger', 'dispatch.ledger')->name('ledger');
        Route::view('/alerts', 'dispatch.alerts')->name('alerts');
    });

    Route::redirect('/inventory-officer', '/inventory-officer/inventory')->name('inventory-officer.shortcut');
    Route::prefix('inventory-officer')->name('inventory-officer.')->group(function () {
        Route::view('/inventory', 'inventory-officer.inventory')->name('inventory');
        Route::view('/ledger', 'inventory-officer.ledger')->name('ledger');
        Route::view('/alerts', 'inventory-officer.alerts')->name('alerts');
    });

    Route::redirect('/sales-officer', '/sales-officer/sales')->name('sales-officer.shortcut');
    Route::prefix('sales-officer')->name('sales-officer.')->group(function () {
        Route::view('/sales', 'sales-officer.sales')->name('sales');
        Route::view('/alerts', 'sales-officer.alerts')->name('alerts');
    });

    Route::redirect('/driver', '/driver/fuel-lifting')->name('driver.shortcut');
    Route::prefix('driver')->name('driver.')->group(function () {
        Route::view('/fuel-lifting', 'driver.fuel-lifting')->name('fuel-lifting');
        Route::view('/fuel-lifting/hauled', 'driver.fuel-lifting', ['state' => 'hauled'])->name('fuel-lifting.hauled');
        Route::view('/fuel-lifting/no-schedule', 'driver.fuel-lifting', ['state' => 'no-schedule'])->name('fuel-lifting.no-schedule');
        Route::view('/fuel-lifting/no-hauled', 'driver.fuel-lifting', ['state' => 'no-hauled'])->name('fuel-lifting.no-hauled');
    });
});
