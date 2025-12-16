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
use Stripe\Invoice;
use Stripe\Token;
use Illuminate\Support\Facades\Log;

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
        return Customer::retrieve($customerId, ['expand' => ['invoice_settings.default_payment_method']]);
    }

    /**
     * Get customer's default payment method
     */
    public function getCustomerDefaultPaymentMethod(string $customerId): ?string
    {
        try {
            $customer = $this->getCustomer($customerId);
            
            // Check invoice_settings.default_payment_method first
            if ($customer->invoice_settings && $customer->invoice_settings->default_payment_method) {
                return is_string($customer->invoice_settings->default_payment_method)
                    ? $customer->invoice_settings->default_payment_method
                    : $customer->invoice_settings->default_payment_method->id;
            }
            
            // Fallback: list payment methods and get the first one
            $paymentMethods = PaymentMethod::all([
                'customer' => $customerId,
                'type' => 'card',
                'limit' => 1,
            ]);
            
            if (!empty($paymentMethods->data)) {
                return $paymentMethods->data[0]->id;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Could not retrieve customer default payment method', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
     * Create a PaymentIntent for card payment (replaces Charge::create for cards)
     * Supports both card and ACH payments based on payment_method_types
     */
    public function createPaymentIntent(
        string $customerId,
        int $amountCents,
        array $paymentMethodTypes = ['card'],
        ?string $paymentMethodId = null,
        array $metadata = [],
        bool $confirm = false
    ): PaymentIntent {
        $params = [
            'amount' => $amountCents,
            'currency' => 'usd',
            'customer' => $customerId,
            'payment_method_types' => $paymentMethodTypes,
            'metadata' => $metadata,
        ];

        if ($paymentMethodId) {
            $params['payment_method'] = $paymentMethodId;
        }
        
        // Allow confirm=true even when paymentMethodId is null
        // Stripe will use customer's default payment method in this case
        if ($confirm) {
            $params['confirm'] = true;
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
     * Supports coupon/promocode and both card and ACH payment methods
     * Automatically confirms the first payment if payment method is provided
     */
    public function createSubscription(
        string $customerId,
        string $priceId,
        ?string $paymentMethodId = null,
        ?string $coupon = null,
        array $paymentMethodTypes = ['card', 'us_bank_account']
    ): Subscription {
        // If no payment method provided, try to get customer's default payment method
        if (!$paymentMethodId) {
            $paymentMethodId = $this->getCustomerDefaultPaymentMethod($customerId);
        }

        $params = [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'payment_method_types' => $paymentMethodTypes,
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
        ];

        if ($paymentMethodId) {
            $params['default_payment_method'] = $paymentMethodId;
        }

        if ($coupon) {
            $params['coupon'] = $coupon;
        }

        $subscription = Subscription::create($params);

        // If we have a payment method, try to pay the invoice to activate the subscription
        if ($subscription->status === 'incomplete' && $paymentMethodId && $subscription->latest_invoice) {
            try {
                $invoice = $subscription->latest_invoice;
                if (is_string($invoice)) {
                    $invoice = Invoice::retrieve($invoice, ['expand' => ['payment_intent']]);
                }
                
                // If invoice has a payment intent, confirm it
                if ($invoice && $invoice->payment_intent) {
                    $paymentIntent = is_string($invoice->payment_intent) 
                        ? \Stripe\PaymentIntent::retrieve($invoice->payment_intent)
                        : $invoice->payment_intent;
                    
                    // Confirm the payment intent with the payment method
                    if (in_array($paymentIntent->status, ['requires_confirmation', 'requires_payment_method'])) {
                        try {
                            $paymentIntent->confirm([
                                'payment_method' => $paymentMethodId,
                                'off_session' => true, // Important for subscriptions
                            ]);
                            // Refresh subscription to get updated status
                            $subscription = Subscription::retrieve($subscription->id, ['expand' => ['latest_invoice.payment_intent']]);
                        } catch (\Exception $e) {
                            Log::warning('PaymentIntent confirmation failed for subscription', [
                                'subscription_id' => $subscription->id,
                                'payment_intent_id' => $paymentIntent->id,
                                'payment_intent_status' => $paymentIntent->status,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } elseif ($paymentIntent->status === 'succeeded') {
                        // Payment already succeeded, refresh subscription
                        $subscription = Subscription::retrieve($subscription->id, ['expand' => ['latest_invoice.payment_intent']]);
                    }
                } else {
                    // No payment intent yet - try to pay the invoice directly
                    // This will create and confirm a payment intent automatically
                    try {
                        $paidInvoice = $invoice->pay([
                            'payment_method' => $paymentMethodId,
                            'off_session' => true,
                        ]);
                        // Refresh subscription to get updated status
                        $subscription = Subscription::retrieve($subscription->id, ['expand' => ['latest_invoice.payment_intent']]);
                    } catch (\Exception $e) {
                        Log::warning('Invoice payment failed for subscription', [
                            'subscription_id' => $subscription->id,
                            'invoice_id' => $invoice->id,
                            'invoice_status' => $invoice->status ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Could not process invoice payment for subscription', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $subscription;
    }

    /**
     * Retrieve a Subscription
     */
    public function getSubscription(string $subscriptionId): Subscription
    {
        return Subscription::retrieve($subscriptionId);
    }

    /**
     * Cancel a Subscription
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = false): Subscription
    {
        $subscription = Subscription::retrieve($subscriptionId);
        
        if ($atPeriodEnd) {
            return $subscription->update(['cancel_at_period_end' => true]);
        }
        
        return $subscription->cancel();
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
            // For modern tokens, create a PaymentMethod and attach it
            // For legacy tokens, createSource will still work
            try {
                // Try to create PaymentMethod from token (modern approach)
                $token = \Stripe\Token::retrieve($data['stripe_token']);
                
                if (isset($token->card)) {
                    // Modern token with card - create PaymentMethod
                    $paymentMethod = PaymentMethod::create([
                        'type' => 'card',
                        'card' => ['token' => $data['stripe_token']],
                    ]);
                    
                    // Attach to customer
                    $paymentMethod->attach(['customer' => $customer->id]);
                    
                    // Set as default payment method
                    Customer::update($customer->id, [
                        'invoice_settings' => [
                            'default_payment_method' => $paymentMethod->id,
                        ],
                    ]);
                } else {
                    // Legacy token - use createSource
                    Customer::createSource($customer->id, [
                        'source' => $data['stripe_token'],
                    ]);
                }
            } catch (\Exception $e) {
                // Fallback to legacy createSource if PaymentMethod creation fails
                Customer::createSource($customer->id, [
                    'source' => $data['stripe_token'],
                ]);
            }
        }

        // Retrieve customer with expanded default payment method
        return $this->getCustomer($customer->id);
    }
}

