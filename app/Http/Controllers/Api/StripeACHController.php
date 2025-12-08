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

            // Create SetupIntent for ACH with Financial Connections
            $setupIntent = $this->stripeService->createACHSetupIntent($customerId, [
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

            return response()->json([
                'success' => true,
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
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
}

