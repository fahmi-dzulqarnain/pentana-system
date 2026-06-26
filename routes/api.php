<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LunchController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PaymentProofController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [MeController::class, 'show']);

        Route::get('dashboard', [DashboardController::class, 'show']);

        Route::get('bills', [BillController::class, 'index']);
        Route::get('bills/summary', [BillController::class, 'summary']);

        Route::get('payment-proofs', [PaymentProofController::class, 'index']);
        Route::post('payment-proofs', [PaymentProofController::class, 'store']);

        Route::get('lunches', [LunchController::class, 'index']);
        Route::post('lunches/{lunch}/respond', [LunchController::class, 'respond']);

        Route::get('activities', [ActivityController::class, 'index']);
        Route::post('activities/{activity}/register', [ActivityController::class, 'register']);
        Route::post('activities/{activity}/cancel', [ActivityController::class, 'cancel']);
    });
});
