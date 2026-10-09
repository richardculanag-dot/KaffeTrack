<?php

use App\Http\Controllers\LogoutController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportExportController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KaffeTrack routes
|--------------------------------------------------------------------------
| Staff:    POS, order queue, cash drawer, stock on hand (stock out / mark depleted), movements, profile
| Manager:  everything
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', Livewire\Auth\Login::class)->name('login');
});

Route::middleware(['auth', 'active'])->group(function () {
    // Role-aware landing page
    Route::get('/', fn () => redirect()->route(auth()->user()->isManager() ? 'dashboard' : 'pos'))->name('home');

    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/profile', Livewire\Profile::class)->name('profile');

    // Sales
    Route::get('/pos', Livewire\Sales\Pos::class)->name('pos');
    Route::get('/orders', Livewire\Sales\OrderQueue::class)->name('orders.index');
    Route::get('/orders/{order}/receipt', ReceiptController::class)->name('orders.receipt');
    Route::get('/cash-drawer', Livewire\Sales\CashDrawer::class)->name('shifts.index');

    // Inventory (staff can view stock, stock out and mark depleted)
    Route::get('/inventory', Livewire\Inventory\StockOnHand::class)->name('inventory.items');
    Route::get('/inventory/movements', Livewire\Inventory\Movements::class)->name('inventory.movements');

    Route::middleware('role:manager')->group(function () {
        Route::get('/dashboard', Livewire\Dashboard::class)->name('dashboard');

        Route::get('/inventory/stock-ins', Livewire\Inventory\StockIns::class)->name('inventory.stock-ins');
        Route::get('/inventory/stock-ins/new', Livewire\Inventory\StockInForm::class)->name('inventory.stock-ins.create');
        Route::get('/inventory/suppliers', Livewire\Inventory\Suppliers::class)->name('inventory.suppliers');

        Route::get('/products', Livewire\Products\ProductList::class)->name('products.index');
        Route::get('/products/options', Livewire\Products\Options::class)->name('products.options');
        Route::get('/products/discounts', Livewire\Products\Discounts::class)->name('products.discounts');

        Route::get('/finance', Livewire\Finance\Overview::class)->name('finance.index');

        Route::get('/reports', Livewire\Reports\Index::class)->name('reports.index');
        Route::get('/reports/export', ReportExportController::class)->name('reports.export');

        Route::get('/users', Livewire\Admin\Users::class)->name('users.index');
        Route::get('/audit-logs', Livewire\Admin\AuditLogs::class)->name('audit.index');
        Route::get('/archive', Livewire\Admin\Archive::class)->name('archive.index');
        Route::get('/settings', Livewire\Admin\Settings::class)->name('settings');
    });
});
