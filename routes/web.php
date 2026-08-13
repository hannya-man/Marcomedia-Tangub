<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\UserController;

// Breeze's logout (and a few other flows) redirect to '/' when finished.
// Without this route, that lands on an undefined URL — this sends a guest
// straight to login, and sends anyone already logged in to their home page.
Route::get('/', function () {
    if (!auth()->check()) {
        return redirect()->route('login');
    }
    return match (auth()->user()->role) {
        'admin' => redirect()->route('dashboard'),
        'cashier' => redirect()->route('pos.index'),
        default => redirect()->route('login'),
    };
});

Route::middleware(['auth', 'no-cache'])->group(function () {

    // Profile — every logged-in role can manage their own account.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Staff chat — one shared channel, every logged-in role (admin, cashier,
    // cashier) can see and post to it.
    Route::get('/chat/history', [\App\Http\Controllers\ChatController::class, 'history'])->name('chat.history');
    Route::get('/chat/poll', [\App\Http\Controllers\ChatController::class, 'poll'])->name('chat.poll');
    Route::post('/chat', [\App\Http\Controllers\ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{chatMessage}/react', [\App\Http\Controllers\ChatController::class, 'react'])->name('chat.react');

    // Dashboard — admin only.
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    // Billing/POS + Stock — admin and cashier.
    Route::middleware('role:admin,cashier')->group(function () {
        Route::get('/pos', [POSController::class, 'index'])->name('pos.index');
        Route::post('/pos', [POSController::class, 'store'])->name('pos.store');
        Route::get('/pos/receipt/{sale}', [POSController::class, 'receipt'])->name('pos.receipt');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::post('/inventory/variants/{variant}/adjust', [InventoryController::class, 'adjustStock'])->name('inventory.adjust');
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock'])->name('inventory.low-stock');
        Route::get('/inventory/archive', [InventoryController::class, 'archive'])->name('inventory.archive');
        Route::delete('/inventory/{product}', [InventoryController::class, 'destroy'])->name('inventory.destroy');
        Route::post('/inventory/{product}/restore', [InventoryController::class, 'restore'])->name('inventory.restore');

        // Raw materials (fabric rolls, blanks, etc.) that get consumed to
        // produce finished stock — see MaterialController for how this
        // connects to product variants.
        Route::get('/materials', [\App\Http\Controllers\MaterialController::class, 'index'])->name('inventory.materials');
        Route::post('/materials', [\App\Http\Controllers\MaterialController::class, 'store'])->name('materials.store');
        Route::post('/materials/{material}/restock', [\App\Http\Controllers\MaterialController::class, 'adjustStock'])->name('materials.restock');
        Route::delete('/materials/{material}', [\App\Http\Controllers\MaterialController::class, 'destroy'])->name('materials.destroy');
        Route::post('/materials/{material}/restore', [\App\Http\Controllers\MaterialController::class, 'restore'])->name('materials.restore');
        Route::get('/materials/archive', [\App\Http\Controllers\MaterialController::class, 'archive'])->name('materials.archive');

        // Categories — same archive-instead-of-delete pattern as everything else.
        Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'index'])->name('inventory.categories');
        Route::post('/categories', [\App\Http\Controllers\CategoryController::class, 'store'])->name('categories.store');
        Route::delete('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('/categories/{category}/restore', [\App\Http\Controllers\CategoryController::class, 'restore'])->name('categories.restore');
        Route::get('/categories/archive', [\App\Http\Controllers\CategoryController::class, 'archive'])->name('categories.archive');

        // Sales & Orders — cashiers process and review these day-to-day too, not just admins.
        Route::get('/sales', [POSController::class, 'transactions'])->name('sales.index');
        Route::post('/sales/{sale}/void', [POSController::class, 'void'])->name('sales.void');
        Route::post('/sales/{sale}/complete', [POSController::class, 'complete'])->name('sales.complete');
        Route::patch('/sales/{sale}', [POSController::class, 'update'])->name('sales.update');
        Route::get('/sales-orders/archive', [POSController::class, 'archive'])->name('sales.archive');
        Route::post('/sales/{sale}/archive', [POSController::class, 'archiveOne'])->name('sales.archiveOne');
        Route::post('/sales/{sale}/restore', [POSController::class, 'restoreSale'])->name('sales.restore');
        // Orders as a standalone concept is retired (see POSController::transactions
        // for why) — only restore stays, so any pre-existing archived Order can
        // still be brought back from the Archive page.
        Route::post('/orders/{order}/restore', [POSController::class, 'restoreOrder'])->name('orders.restore');
    });

});

require __DIR__.'/auth.php'; // from Laravel Breeze/Jetstream, or your own auth scaffolding
