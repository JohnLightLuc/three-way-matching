<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeliveryNoteController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchDecisionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes API — moteur de rapprochement à 3 voies
|--------------------------------------------------------------------------
| Préfixe /api (bootstrap/app.php). Toutes les routes métier sont derrière
| auth:sanctum ; la révision d'un écart exige en plus la capacité
| review-decisions (is_reviewer). Le middleware RecordActivity journalise
| toute requête mutante + les logins.
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    // Bons de commande
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');

    // Bons de livraison (rattachés à un PO)
    Route::post('purchase-orders/{purchaseOrder}/delivery-notes', [DeliveryNoteController::class, 'store'])
        ->name('delivery-notes.store');

    // Factures (rattachées à un PO) + rapprochement
    Route::post('purchase-orders/{purchaseOrder}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/match', [MatchController::class, 'store'])->name('invoices.match');

    // Décisions de rapprochement : file de revue + révision humaine (F10)
    Route::get('match-decisions', [MatchDecisionController::class, 'index'])->name('match-decisions.index');
    Route::post('match-decisions/{matchDecision}/review', [MatchDecisionController::class, 'review'])
        ->middleware('can:review-decisions')
        ->name('match-decisions.review');
});
