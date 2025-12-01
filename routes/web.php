<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StripeACHController;
use App\Http\Controllers\Api\StripeWebhookController;

Route::get('/', function () {
    return view('welcome');
});

// Stripe ACH Financial Connections API routes
Route::prefix('api/stripe')->group(function () {
    // Financial Connections (new flow)
    Route::post('/create-setup-intent', [StripeACHController::class, 'createSetupIntent']);
    Route::post('/confirm-setup-intent', [StripeACHController::class, 'confirmSetupIntent']);
    Route::post('/get-payment-method', [StripeACHController::class, 'getPaymentMethodDetails']);

    // Legacy Token API (backward compatibility - deprecated by Stripe May 2026)
    Route::post('/create-bank-token', [StripeACHController::class, 'createBankToken']);
    Route::post('/verify-bank-account', [StripeACHController::class, 'verifyBankAccount']);
});

// Stripe Webhook (configure in Stripe Dashboard: https://payment.test/api/stripe/webhook)
Route::post('/api/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
