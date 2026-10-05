<?php
// Batch inventory routes. Paste these inside the auth-protected part of routes/web.php,
// then DELETE the old line:  Route::post('/inventory-batches', ...)  in the admin,cashier group
// (opening stock is now admin only).

use App\Http\Controllers\InventoryBatchController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\StockAlertController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// Owner / inventory manager.
Route::middleware('role:admin')->group(function () {
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    Route::post('/suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->name('suppliers.restore');

    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    Route::patch('/materials/{material}', [MaterialController::class, 'update'])->name('materials.update');

    Route::post('/inventory-batches', [InventoryBatchController::class, 'store'])->name('inventory-batches.store');
    Route::post('/inventory-batches/{batch}/open', [InventoryBatchController::class, 'open'])->name('inventory-batches.open');
    Route::post('/inventory-batches/{batch}/close', [InventoryBatchController::class, 'close'])->name('inventory-batches.close');
    Route::post('/inventory-batches/{batch}/transfer', [InventoryBatchController::class, 'transfer'])->name('inventory-batches.transfer');
    Route::post('/inventory-batches/{batch}/count', [InventoryBatchController::class, 'recordCount'])->name('inventory-batches.count');
});

// Owner and staff.
Route::middleware('role:admin,cashier')->group(function () {
    Route::post('/purchase-order-items/{item}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::post('/inventory-batches/{batch}/loss', [InventoryBatchController::class, 'recordLoss'])->name('inventory-batches.loss');
    Route::post('/offcuts/{offcut}/discard', [InventoryBatchController::class, 'discardOffcut'])->name('offcuts.discard');
    Route::post('/stock-alerts/{alert}/seen', [StockAlertController::class, 'acknowledge'])->name('stock-alerts.acknowledge');
});
