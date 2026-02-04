<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeACHService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class StripeACHController extends Controller
{
    protected StripeACHService $stripeService;

    public function __construct(StripeACHService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Create a SetupIntent for bank account collection via Financial Connections
     * Called via AJAX when user clicks "Connect Bank Account"
     */
    public function createSetupIntent(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id_stripe');
            $paymentMethodTypes = $request->input('payment_method_types', ['us_bank_account']);

            // If no customer exists yet, create one
            if (empty($customerId)) {
                $customer = $this->stripeService->createCustomer([
                    'email' => $request->input('email'),
                    'name' => $request->input('name'),
                    'description' => 'Simple Statement Customer',
                    'metadata' => [
                        'source' => 'signup_ach',
                    ],
                ]);
                $customerId = $customer->id;
            }

            // Create SetupIntent with dynamic payment method types
            $setupIntent = $this->stripeService->createSetupIntent($customerId, $paymentMethodTypes, [
                'permissions' => ['payment_method', 'balances'],
                'verification_method' => 'automatic',
            ]);

            return response()->json([
                'success' => true,
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
                'customer_id' => $customerId,
            ]);

        } catch (\Exception $e) {
            Log::error('SetupIntent creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Confirm SetupIntent and retrieve bank account details
     * Called after user completes bank account connection
     */
    public function confirmSetupIntent(Request $request): JsonResponse
    {
        try {
            $setupIntentId = $request->input('setup_intent_id');
            $customerId = $request->input('customer_id');
            $userId = $request->input('user_id');

            // Retrieve the SetupIntent to check status
            $setupIntent = $this->stripeService->getSetupIntent($setupIntentId);

            if ($setupIntent->status === 'succeeded') {
                $paymentMethodId = $setupIntent->payment_method;

                // Get bank account details from payment method
                $paymentMethod = $this->stripeService->getPaymentMethod($paymentMethodId);
                $bankAccount = $paymentMethod->us_bank_account;

                // Note: Database operations are handled by the calling service (userdashboard)
                // This microservice only handles Stripe API operations

                return response()->json([
                    'success' => true,
                    'message' => 'Bank account connected successfully',
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $bankAccount->bank_name ?? '',
                    'routing_number' => $bankAccount->routing_number ?? '',
                    'last4' => $bankAccount->last4 ?? '',
                    'account_type' => $bankAccount->account_type ?? '',
                    'account_holder_type' => $bankAccount->account_holder_type ?? '',
                ]);
            }

            // Handle other statuses
            if ($setupIntent->status === 'requires_action') {
                return response()->json([
                    'success' => false,
                    'status' => 'requires_action',
                    'message' => 'Additional verification required',
                    'next_action' => $setupIntent->next_action,
                ], 202);
            }

            return response()->json([
                'success' => false,
                'error' => 'SetupIntent not confirmed',
                'status' => $setupIntent->status,
            ], 400);

        } catch (\Exception $e) {
            Log::error('SetupIntent confirmation failed', [
                'error' => $e->getMessage(),
                'setup_intent_id' => $request->input('setup_intent_id'),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get payment method details by ID
     * Called by userdashboard backend to retrieve bank account info
     */
    public function getPaymentMethodDetails(Request $request): JsonResponse
    {
        try {
            $paymentMethodId = $request->input('payment_method_id');

            if (empty($paymentMethodId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Payment method ID is required',
                ], 400);
            }

            $paymentMethod = $this->stripeService->getPaymentMethod($paymentMethodId);
            $bankAccount = $paymentMethod->us_bank_account;

            return response()->json([
                'success' => true,
                'payment_method_id' => $paymentMethodId,
                'bank_name' => $bankAccount->bank_name ?? '',
                'routing_number' => $bankAccount->routing_number ?? '',
                'last4' => $bankAccount->last4 ?? '',
                'account_type' => $bankAccount->account_type ?? '',
                'account_holder_type' => $bankAccount->account_holder_type ?? '',
                'status' => $bankAccount->status ?? '',
            ]);

        } catch (\Exception $e) {
            Log::error('Get payment method details failed', [
                'error' => $e->getMessage(),
                'payment_method_id' => $request->input('payment_method_id'),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create ACH bank token (Legacy flow - for backward compatibility)
     * This wraps the legacy Token API for older code paths
     */
    public function createBankToken(Request $request): JsonResponse
    {
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $token = \Stripe\Token::create([
                'bank_account' => [
                    'country' => 'US',
                    'currency' => 'usd',
                    'account_holder_name' => $request->input('account_holder_name'),
                    'account_holder_type' => $request->input('account_holder_type', 'company'),
                    'routing_number' => $request->input('routing_number'),
                    'account_number' => $request->input('account_number'),
                ],
            ]);

            // Create customer with source
            $customer = \Stripe\Customer::create([
                'description' => 'Simple Statement',
                'source' => $token->id,
            ]);

            // Attempt to verify (will fail in production without actual micro-deposits)
            $bankAccount = \Stripe\Customer::retrieveSource($customer->id, $token->bank_account->id);

            return response()->json([
                'success' => true,
                'customer_id' => $customer->id,
                'bank_id' => $token->bank_account->id,
                'bank_name' => $token->bank_account->bank_name,
                'routing_number' => $token->bank_account->routing_number,
                'account_number' => $request->input('account_number'),
                'status' => $bankAccount->status,
            ]);

        } catch (\Exception $e) {
            Log::error('Create bank token failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Verify bank account with micro-deposit amounts (Legacy flow)
     */
    public function verifyBankAccount(Request $request): JsonResponse
    {
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $customerId = $request->input('customer_id');
            $bankId = $request->input('bank_id');
            $amounts = $request->input('amounts', [32, 45]); // Default test amounts

            $bankAccount = \Stripe\Customer::retrieveSource($customerId, $bankId);
            $bankAccount->verify(['amounts' => $amounts]);

            return response()->json([
                'success' => true,
                'status' => $bankAccount->status,
                'verified' => $bankAccount->status === 'verified',
            ]);

        } catch (\Exception $e) {
            Log::error('Verify bank account failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
                'bank_id' => $request->input('bank_id'),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a Stripe Customer with a card token
     * Used for credit card signups (not ACH)
     * Centralized Stripe operation - no database operations
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createCustomerWithCard(Request $request): JsonResponse
    {
        try {
            $email = $request->input('email');
            $cardToken = $request->input('stripe_token');
            $metadata = $request->input('metadata', []);

            if (empty($cardToken)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Card token is required',
                ], 400);
            }

            // Use service method for centralized Stripe operations
            $customer = $this->stripeService->createCustomerWithCard([
                'email' => $email,
                'stripe_token' => $cardToken,
                'metadata' => $metadata,
            ]);

            // Get default payment method ID if available
            $paymentMethodId = null;
            if ($customer->invoice_settings && $customer->invoice_settings->default_payment_method) {
                $paymentMethodId = is_string($customer->invoice_settings->default_payment_method)
                    ? $customer->invoice_settings->default_payment_method
                    : $customer->invoice_settings->default_payment_method->id;
            } else {
                // Fallback: list payment methods and get the first one
                $paymentMethods = \Stripe\PaymentMethod::all([
                    'customer' => $customer->id,
                    'type' => 'card',
                    'limit' => 1,
                ]);
                
                if (!empty($paymentMethods->data)) {
                    $paymentMethodId = $paymentMethods->data[0]->id;
                }
            }

            return response()->json([
                'success' => true,
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
                'payment_method_id' => $paymentMethodId,
                'payment_method_type' => 'card',
            ]);

        } catch (\Exception $e) {
            Log::error('Create customer with card failed', [
                'error' => $e->getMessage(),
                'email' => $request->input('email'),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a PaymentIntent (for card or ACH payments)
     * Replaces Charge::create for both card and ACH payments
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $amountCents = (int) $request->input('amount');
            $paymentMethodTypes = $request->input('payment_method_types', ['card']);
            $paymentMethodId = $request->input('payment_method_id');
            $metadata = $request->input('metadata', []);
            $confirm = $request->input('confirm', false);

            if (empty($customerId) || empty($amountCents)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Customer ID and amount are required',
                ], 400);
            }

            $paymentIntent = $this->stripeService->createPaymentIntent(
                $customerId,
                $amountCents,
                $paymentMethodTypes,
                $paymentMethodId,
                $metadata,
                $confirm
            );

            return response()->json([
                'success' => true,
                'payment_intent_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'payment_intent' => $paymentIntent,
            ]);

        } catch (\Exception $e) {
            Log::error('Create PaymentIntent failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a Price (replaces Plan::create)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPrice(Request $request): JsonResponse
    {
        try {
            $amountCents = (int) $request->input('amount');
            $interval = $request->input('interval', 'month');
            $intervalCount = (int) $request->input('interval_count', 1);
            $productName = $request->input('product_name');

            if (empty($amountCents)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Amount is required',
                ], 400);
            }

            $price = $this->stripeService->createPrice(
                $amountCents,
                $interval,
                $intervalCount,
                $productName
            );

            return response()->json([
                'success' => true,
                'price_id' => $price->id,
                'unit_amount' => $price->unit_amount,
                'currency' => $price->currency,
                'recurring' => $price->recurring,
                'product' => $price->product,
                'price' => $price,
            ]);

        } catch (\Exception $e) {
            Log::error('Create Price failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a Subscription with Price (replaces Plan-based subscriptions)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createSubscription(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $priceId = $request->input('price_id');
            $paymentMethodId = $request->input('payment_method_id');
            $coupon = $request->input('coupon');
            $paymentMethodTypes = $request->input('payment_method_types', ['card', 'us_bank_account']);

            if (empty($customerId) || empty($priceId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Customer ID and Price ID are required',
                ], 400);
            }

            $subscription = $this->stripeService->createSubscription(
                $customerId,
                $priceId,
                $paymentMethodId,
                $coupon,
                $paymentMethodTypes
            );

            return response()->json([
                'success' => true,
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'items' => $subscription->items->data,
                'subscription' => $subscription,
            ]);

        } catch (\Exception $e) {
            Log::error('Create Subscription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get Subscription details
     *
     * @param string $subscriptionId
     * @return JsonResponse
     */
    public function getSubscription(string $subscriptionId): JsonResponse
    {
        try {
            $subscription = $this->stripeService->getSubscription($subscriptionId);

            return response()->json([
                'success' => true,
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'items' => $subscription->items->data,
                'subscription' => $subscription,
            ]);

        } catch (\Exception $e) {
            Log::error('Get Subscription failed', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel Subscription
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        try {
            $subscriptionId = $request->input('subscription_id');
            $atPeriodEnd = $request->input('at_period_end', false);

            if (empty($subscriptionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Subscription ID is required',
                ], 400);
            }

            $subscription = $this->stripeService->cancelSubscription($subscriptionId, $atPeriodEnd);

            return response()->json([
                'success' => true,
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'canceled_at' => $subscription->canceled_at,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'subscription' => $subscription,
            ]);

        } catch (\Exception $e) {
            Log::error('Cancel Subscription failed', [
                'error' => $e->getMessage(),
                'subscription_id' => $request->input('subscription_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Migrate bank account token (ba_*) to PaymentMethod (pm_*)
     * Used for data migration from deprecated Token API to PaymentMethod API
     */
    public function migrateBankAccountToPaymentMethod(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $bankAccountId = $request->input('bank_account_id');

            if (empty($customerId) || empty($bankAccountId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id and bank_account_id are required',
                ], 400);
            }

            $result = $this->stripeService->migrateBankAccountToPaymentMethod($customerId, $bankAccountId);

            if ($result) {
                return response()->json($result);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to migrate bank account',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Migrate bank account failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Backfill mandate for existing PaymentMethod
     * Mandates are required for ACH Direct Debits
     */
    public function backfillMandateForPaymentMethod(Request $request): JsonResponse
    {
        try {
            $paymentMethodId = $request->input('payment_method_id');

            if (empty($paymentMethodId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'payment_method_id is required',
                ], 400);
            }

            $result = $this->stripeService->backfillMandateForPaymentMethod($paymentMethodId);

            if ($result) {
                return response()->json($result);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to backfill mandate',
            ], 400);

        } catch (\Exception $e) {
            Log::error('Backfill mandate failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List PaymentIntents for a customer (replaces Charge::all)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listPaymentIntents(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $limit = $request->input('limit', 100);
            $startingAfter = $request->input('starting_after', null);

            if (empty($customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id is required',
                ], 400);
            }

            $params = [
                'customer' => $customerId,
                'limit' => $limit,
            ];

            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            $paymentIntents = \Stripe\PaymentIntent::all($params);

            return response()->json([
                'success' => true,
                'has_more' => $paymentIntents->has_more,
                'data' => array_map(function($pi) {
                    return [
                        'id' => $pi->id,
                        'amount' => $pi->amount,
                        'currency' => $pi->currency,
                        'status' => $pi->status,
                        'customer' => $pi->customer,
                        'payment_method' => $pi->payment_method,
                        'created' => $pi->created,
                    ];
                }, $paymentIntents->data),
            ]);

        } catch (\Exception $e) {
            Log::error('List PaymentIntents failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List PaymentMethods for a customer (replaces Customer::allSources)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listPaymentMethods(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $type = $request->input('type', 'us_bank_account');
            $limit = $request->input('limit', 10);

            if (empty($customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id is required',
                ], 400);
            }

            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $customerId,
                'type' => $type,
                'limit' => $limit,
            ]);

            return response()->json([
                'success' => true,
                'data' => array_map(function($pm) {
                    $data = [
                        'id' => $pm->id,
                        'type' => $pm->type,
                        'created' => $pm->created,
                    ];

                    if ($pm->type === 'us_bank_account') {
                        $data['us_bank_account'] = [
                            'bank_name' => $pm->us_bank_account->bank_name ?? '',
                            'last4' => $pm->us_bank_account->last4 ?? '',
                            'routing_number' => $pm->us_bank_account->routing_number ?? '',
                            'account_type' => $pm->us_bank_account->account_type ?? '',
                            'account_holder_type' => $pm->us_bank_account->account_holder_type ?? '',
                        ];
                    }

                    return $data;
                }, $paymentMethods->data),
            ]);

        } catch (\Exception $e) {
            Log::error('List PaymentMethods failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Detach a PaymentMethod from a customer (replaces Customer::retrieveSource operations)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function detachPaymentMethod(Request $request): JsonResponse
    {
        try {
            $paymentMethodId = $request->input('payment_method_id');

            if (empty($paymentMethodId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'payment_method_id is required',
                ], 400);
            }

            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->detach();

            return response()->json([
                'success' => true,
                'message' => 'PaymentMethod detached successfully',
                'payment_method_id' => $paymentMethodId,
            ]);

        } catch (\Exception $e) {
            Log::error('Detach PaymentMethod failed', [
                'error' => $e->getMessage(),
                'payment_method_id' => $request->input('payment_method_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Attach a PaymentMethod to a customer (replaces Customer::createSource)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function attachPaymentMethod(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $paymentMethodId = $request->input('payment_method_id');

            if (empty($customerId) || empty($paymentMethodId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id and payment_method_id are required',
                ], 400);
            }

            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach([
                'customer' => $customerId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PaymentMethod attached successfully',
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'customer' => $paymentMethod->customer,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Attach PaymentMethod failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
                'payment_method_id' => $request->input('payment_method_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a PaymentMethod from a token and attach to customer (replaces Customer::createSource)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPaymentMethodFromToken(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $token = $request->input('token');

            if (empty($customerId) || empty($token)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id and token are required',
                ], 400);
            }

            // Create PaymentMethod from token
            $paymentMethod = \Stripe\PaymentMethod::create([
                'type' => 'card',
                'card' => ['token' => $token],
            ]);

            // Attach to customer
            $paymentMethod->attach([
                'customer' => $customerId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PaymentMethod created and attached successfully',
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'customer' => $paymentMethod->customer,
                    'card' => [
                        'brand' => $paymentMethod->card->brand ?? '',
                        'last4' => $paymentMethod->card->last4 ?? '',
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Create PaymentMethod from token failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
                'token' => $request->input('token'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a SubscriptionSchedule
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createSubscriptionSchedule(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $startDate = $request->input('start_date');
            $endBehavior = $request->input('end_behavior', 'cancel');
            $phases = $request->input('phases', []);

            if (empty($customerId) || empty($phases)) {
                return response()->json([
                    'success' => false,
                    'error' => 'customer_id and phases are required',
                ], 400);
            }

            $params = [
                'customer' => $customerId,
                'end_behavior' => $endBehavior,
                'phases' => $phases,
            ];

            if ($startDate) {
                $params['start_date'] = $startDate;
            }

            $schedule = \Stripe\SubscriptionSchedule::create($params);

            return response()->json([
                'success' => true,
                'schedule' => [
                    'id' => $schedule->id,
                    'status' => $schedule->status,
                    'customer' => $schedule->customer,
                    'phases' => $schedule->phases,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Create SubscriptionSchedule failed', [
                'error' => $e->getMessage(),
                'customer_id' => $request->input('customer_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get SubscriptionSchedule details
     *
     * @param string $scheduleId
     * @return JsonResponse
     */
    public function getSubscriptionSchedule(string $scheduleId): JsonResponse
    {
        try {
            $schedule = \Stripe\SubscriptionSchedule::retrieve($scheduleId);

            return response()->json([
                'success' => true,
                'schedule' => $schedule,
            ]);

        } catch (\Exception $e) {
            Log::error('Get SubscriptionSchedule failed', [
                'error' => $e->getMessage(),
                'schedule_id' => $scheduleId,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update SubscriptionSchedule
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateSubscriptionSchedule(Request $request): JsonResponse
    {
        try {
            $scheduleId = $request->input('schedule_id');
            $params = $request->all();

            if (empty($scheduleId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'schedule_id is required',
                ], 400);
            }

            unset($params['schedule_id']);
            $schedule = \Stripe\SubscriptionSchedule::update($scheduleId, $params);

            return response()->json([
                'success' => true,
                'schedule' => $schedule,
            ]);

        } catch (\Exception $e) {
            Log::error('Update SubscriptionSchedule failed', [
                'error' => $e->getMessage(),
                'schedule_id' => $request->input('schedule_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete a Coupon (replaces Coupon::retrieve->delete)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteCoupon(Request $request): JsonResponse
    {
        try {
            $couponId = $request->input('coupon_id');

            if (empty($couponId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'coupon_id is required',
                ], 400);
            }

            $result = $this->stripeService->deleteCoupon($couponId);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Delete Coupon failed', [
                'error' => $e->getMessage(),
                'coupon_id' => $request->input('coupon_id'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List invoices for a subscription
     * Replaces Stripe\Invoice::all()
     */
    public function listInvoices(Request $request): JsonResponse
    {
        try {
            $subscriptionId = $request->input('subscription');
            $limit = $request->input('limit', 100);
            $startingAfter = $request->input('starting_after');

            if (empty($subscriptionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'subscription is required',
                ], 400);
            }

            $result = $this->stripeService->listInvoices($subscriptionId, $limit, $startingAfter);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('List Invoices failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a Customer (replaces \Stripe\Customer::create)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createCustomer(Request $request): JsonResponse
    {
        try {
            $params = $request->all();

            if (empty($params['email'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'email is required',
                ], 400);
            }

            $customer = $this->stripeService->createCustomer($params);

            return response()->json([
                'success' => true,
                'customer' => [
                    'id' => $customer->id,
                    'email' => $customer->email,
                    'name' => $customer->name,
                    'metadata' => $customer->metadata,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Create Customer failed', [
                'error' => $e->getMessage(),
                'email' => $request->input('email'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update a Customer (replaces \Stripe\Customer::update)
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function updateCustomer(Request $request, string $id): JsonResponse
    {
        try {
            $params = $request->all();
            $customer = $this->stripeService->updateCustomer($id, $params);

            return response()->json([
                'success' => true,
                'customer' => [
                    'id' => $customer->id,
                    'email' => $customer->email,
                    'name' => $customer->name,
                    'metadata' => $customer->metadata,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Update Customer failed', [
                'error' => $e->getMessage(),
                'customer_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retrieve a Customer (replaces \Stripe\Customer::retrieve)
     *
     * @param string $id
     * @return JsonResponse
     */
    public function retrieveCustomer(string $id): JsonResponse
    {
        try {
            $customer = $this->stripeService->getCustomer($id);

            return response()->json([
                'success' => true,
                'customer' => [
                    'id' => $customer->id,
                    'email' => $customer->email,
                    'name' => $customer->name,
                    'metadata' => $customer->metadata,
                    'invoice_settings' => $customer->invoice_settings,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Retrieve Customer failed', [
                'error' => $e->getMessage(),
                'customer_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retrieve a Subscription (replaces \Stripe\Subscription::retrieve)
     *
     * @param string $id
     * @return JsonResponse
     */
    public function retrieveSubscription(string $id): JsonResponse
    {
        try {
            $subscription = $this->stripeService->getSubscription($id);

            return response()->json([
                'success' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'customer' => $subscription->customer,
                    'status' => $subscription->status,
                    'current_period_start' => $subscription->current_period_start,
                    'current_period_end' => $subscription->current_period_end,
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                    'items' => $subscription->items->data,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Retrieve Subscription failed', [
                'error' => $e->getMessage(),
                'subscription_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update a Subscription (replaces \Stripe\Subscription::update)
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function updateSubscription(Request $request, string $id): JsonResponse
    {
        try {
            $params = $request->all();
            $subscription = $this->stripeService->updateSubscription($id, $params);

            return response()->json([
                'success' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'customer' => $subscription->customer,
                    'status' => $subscription->status,
                    'current_period_start' => $subscription->current_period_start,
                    'current_period_end' => $subscription->current_period_end,
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                    'items' => $subscription->items->data,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Update Subscription failed', [
                'error' => $e->getMessage(),
                'subscription_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a PaymentMethod (replaces Customer::createSource for modern API)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPaymentMethod(Request $request): JsonResponse
    {
        try {
            $params = $request->all();

            if (empty($params['type'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'type is required (e.g., card, us_bank_account)',
                ], 400);
            }

            $paymentMethod = $this->stripeService->createPaymentMethod($params);

            return response()->json([
                'success' => true,
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'card' => $paymentMethod->card,
                    'us_bank_account' => $paymentMethod->us_bank_account,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Create PaymentMethod failed', [
                'error' => $e->getMessage(),
                'type' => $request->input('type'),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Retrieve a PaymentMethod (replaces Customer::retrieveSource)
     *
     * @param string $id
     * @return JsonResponse
     */
    public function retrievePaymentMethod(string $id): JsonResponse
    {
        try {
            $paymentMethod = $this->stripeService->getPaymentMethod($id);

            return response()->json([
                'success' => true,
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'customer' => $paymentMethod->customer ?? null,
                    'card' => $paymentMethod->card,
                    'us_bank_account' => $paymentMethod->us_bank_account,
                    'billing_details' => $paymentMethod->billing_details,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Retrieve PaymentMethod failed', [
                'error' => $e->getMessage(),
                'payment_method_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a refund for a payment intent or charge
     * Replaces Stripe\Refund::create()
     */
    public function createRefund(Request $request): JsonResponse
    {
        try {
            $params = $request->all();

            if (empty($params['payment_intent']) && empty($params['charge'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'payment_intent or charge is required',
                ], 400);
            }

            $result = $this->stripeService->createRefund($params);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Create Refund failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List refunds for a payment intent
     * Replaces Stripe\Refund::all()
     */
    public function listRefunds(Request $request): JsonResponse
    {
        try {
            $params = $request->all();
            $result = $this->stripeService->listRefunds($params);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('List Refunds failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}

