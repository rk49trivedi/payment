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

    // Payment Intents (replaces Charge::create)
    Route::post('/create-payment-intent', [StripeACHController::class, 'createPaymentIntent']);

    // Prices (replaces Plan::create)
    Route::post('/create-price', [StripeACHController::class, 'createPrice']);

    // Subscriptions (with Price support)
    Route::post('/create-subscription', [StripeACHController::class, 'createSubscription']);
    Route::get('/get-subscription/{subscriptionId}', [StripeACHController::class, 'getSubscription']);
    Route::post('/cancel-subscription', [StripeACHController::class, 'cancelSubscription']);

    // Data Migration (for ba_* to pm_* migration)
    Route::post('/migrate-bank-account', [StripeACHController::class, 'migrateBankAccountToPaymentMethod']);
    Route::post('/backfill-mandate', [StripeACHController::class, 'backfillMandateForPaymentMethod']);
});

// Stripe Webhook (configure in Stripe Dashboard)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

