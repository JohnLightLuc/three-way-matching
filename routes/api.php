<?php

use App\Http\Controllers\Api\AuthController;
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

    // Endpoints métier — ajoutés à l'étape 3c-1.
});
