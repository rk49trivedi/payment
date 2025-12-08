<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\SetupIntent;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Subscription;
use Stripe\Price;
use Stripe\Source;

class StripeACHService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe Customer
     */
    public function createCustomer(array $data): Customer
    {
        return Customer::create([
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? 'Customer',
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

    /**
     * Retrieve a Stripe Customer
     */
    public function getCustomer(string $customerId): Customer
    {
        return Customer::retrieve($customerId);
    }

    /**
     * Create a SetupIntent for ACH bank account collection via Financial Connections
     */
    public function createACHSetupIntent(string $customerId, array $options = []): SetupIntent
    {
        return SetupIntent::create([
            'customer' => $customerId,
            'payment_method_types' => ['us_bank_account'],
            'payment_method_options' => [
                'us_bank_account' => [
                    'financial_connections' => [
                        'permissions' => $options['permissions'] ?? ['payment_method', 'balances'],
                    ],
                    'verification_method' => $options['verification_method'] ?? 'automatic',
                ],
            ],
        ]);
    }

    /**
     * Retrieve a SetupIntent
     */
    public function getSetupIntent(string $setupIntentId): SetupIntent
    {
        return SetupIntent::retrieve($setupIntentId);
    }

    /**
     * Retrieve a PaymentMethod
     */
    public function getPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        return PaymentMethod::retrieve($paymentMethodId);
    }

    /**
     * Create a PaymentIntent for ACH payment (replaces Charge::create)
     */
    public function createACHPaymentIntent(
        string $customerId,
        int $amountCents,
        ?string $paymentMethodId = null,
        array $metadata = [],
        bool $confirm = false
    ): PaymentIntent {
        $params = [
            'amount' => $amountCents,
            'currency' => 'usd',
            'customer' => $customerId,
            'payment_method_types' => ['us_bank_account'],
            'metadata' => $metadata,
        ];

        if ($paymentMethodId) {
            $params['payment_method'] = $paymentMethodId;
            if ($confirm) {
                $params['confirm'] = true;
            }
        }

        return PaymentIntent::create($params);
    }

    /**
     * Confirm a PaymentIntent
     */
    public function confirmPaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): PaymentIntent
    {
        $params = [];
        if ($paymentMethodId) {
            $params['payment_method'] = $paymentMethodId;
        }
        return PaymentIntent::retrieve($paymentIntentId)->confirm($params);
    }

    /**
     * Retrieve a PaymentIntent
     */
    public function getPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId);
    }

    /**
     * Create a Price (replaces Plan::create)
     */
    public function createPrice(int $amountCents, string $interval = 'month', int $intervalCount = 1, ?string $productName = null): Price
    {
        return Price::create([
            'unit_amount' => $amountCents,
            'currency' => 'usd',
            'recurring' => [
                'interval' => $interval,
                'interval_count' => $intervalCount,
            ],
            'product_data' => [
                'name' => $productName ?? 'Subscription Payment',
            ],
        ]);
    }

    /**
     * Create a Subscription with default payment method
     */
    public function createSubscription(string $customerId, string $priceId, ?string $paymentMethodId = null): Subscription
    {
        $params = [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'payment_method_types' => ['us_bank_account'],
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
        ];

        if ($paymentMethodId) {
            $params['default_payment_method'] = $paymentMethodId;
        }

        return Subscription::create($params);
    }

    /**
     * Create a Customer with a card token (for credit card signups)
     * This is not ACH-related but centralized here for consistency
     */
    public function createCustomerWithCard(array $data): Customer
    {
        $customer = $this->createCustomer([
            'email' => $data['email'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);

        // Attach card token to customer
        if (!empty($data['stripe_token'])) {
            Customer::createSource($customer->id, [
                'source' => $data['stripe_token'],
            ]);
        }

        return $customer;
    }
}

