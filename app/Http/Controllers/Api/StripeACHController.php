<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeACHService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

                // If user_id provided, update the customer record
                if ($userId) {
                    DB::table('customers')->where('customer_id', $userId)->update([
                        'customer_id_stripe' => $customerId,
                        'payment_method_id' => $paymentMethodId,
                        'setup_intent_id' => $setupIntentId,
                        'bank_account_status' => 'verified',
                        'ach_info' => $paymentMethodId,
                        'bank_name' => $bankAccount->bank_name ?? '',
                        'routing' => $bankAccount->routing_number ?? '',
                        'account_number' => '****' . ($bankAccount->last4 ?? ''),
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Bank account connected successfully',
                    'payment_method_id' => $paymentMethodId,
                    'bank_name' => $bankAccount->bank_name ?? '',
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
}

