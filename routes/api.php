<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StripeACHController;
use App\Http\Controllers\Api\StripeWebhookController;

// Stripe ACH Financial Connections API routes
Route::prefix('stripe')->group(function () {
    // Financial Connections (new flow)
    Route::post('/create-setup-intent', [StripeACHController::class, 'createSetupIntent']);
    Route::post('/confirm-setup-intent', [StripeACHController::class, 'confirmSetupIntent']);
    Route::post('/get-payment-method', [StripeACHController::class, 'getPaymentMethodDetails']);

    // Legacy Token API (backward compatibility - deprecated by Stripe May 2026)
    Route::post('/create-bank-token', [StripeACHController::class, 'createBankToken']);
    Route::post('/verify-bank-account', [StripeACHController::class, 'verifyBankAccount']);

    // Customer creation (for card signups)
    Route::post('/create-customer-with-card', [StripeACHController::class, 'createCustomerWithCard']);
});

// Stripe Webhook (configure in Stripe Dashboard)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

