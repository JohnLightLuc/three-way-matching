<?php

use App\Http\Controllers\Web\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Routes web — UI opérateur (Inertia + Vue)
|--------------------------------------------------------------------------
| Les pages ne portent que l'identifiant en prop ; les données sont
| récupérées côté Vue via l'API /api/* (cookie de session Sanctum).
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');

    Route::get('purchase-orders', fn () => Inertia::render('PurchaseOrders/Index'))->name('po.index');
    Route::get('purchase-orders/create', fn () => Inertia::render('PurchaseOrders/Create'))->name('po.create');
    Route::get('purchase-orders/{purchaseOrder}', fn (int $purchaseOrder) => Inertia::render('PurchaseOrders/Show', [
        'id' => $purchaseOrder,
    ]))->whereNumber('purchaseOrder')->name('po.show');

    Route::get('invoices', fn () => Inertia::render('Invoices/Index'))->name('inv.index');
    Route::get('invoices/{invoice}', fn (int $invoice) => Inertia::render('Invoices/Show', [
        'id' => $invoice,
    ]))->whereNumber('invoice')->name('inv.show');

    Route::get('review', fn () => Inertia::render('Review/Index'))->name('review.index');
});
