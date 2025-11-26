<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StripeACHController;

Route::get('/', function () {
    return view('welcome');
});

// Stripe ACH Financial Connections API routes
Route::prefix('api/stripe')->group(function () {
    Route::post('/create-setup-intent', [StripeACHController::class, 'createSetupIntent']);
    Route::post('/confirm-setup-intent', [StripeACHController::class, 'confirmSetupIntent']);
});
