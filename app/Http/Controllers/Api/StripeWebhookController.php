<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Exception;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $endpoint_secret = config('services.stripe.webhook_secret');
        $payload = $request->getContent();
        $sig_header = $request->server('HTTP_STRIPE_SIGNATURE');
        $event = null;

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook error: Invalid payload', ['error' => $e->getMessage()]);
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook error: Invalid signature', ['error' => $e->getMessage()]);
            return response('Invalid signature', 400);
        }

        try {
            switch ($event->type) {
                // SetupIntent events for Financial Connections
                case 'setup_intent.succeeded':
                    $this->handleSetupIntentSucceeded($event->data->object);
                    break;

                case 'setup_intent.setup_failed':
                    $this->handleSetupIntentFailed($event->data->object);
                    break;

                // PaymentIntent events (new ACH flow)
                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.processing':
                    $this->handlePaymentIntentProcessing($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event->data->object);
                    break;

                case 'payment_intent.requires_action':
                    $this->handlePaymentIntentRequiresAction($event->data->object);
                    break;

                // Legacy Charge events (backward compatibility)
                case 'charge.pending':
                case 'charge.succeeded':
                case 'charge.failed':
                    $this->handleChargeEvent($event->type, $event->data->object);
                    break;

                default:
                    Log::info('Unhandled Stripe event type: ' . $event->type);
            }

            return response('Webhook handled', 200);
        } catch (Exception $e) {
            Log::error('Error processing Stripe webhook', ['error' => $e->getMessage()]);
            return response('Webhook processing error', 500);
        }
    }

    protected function handleSetupIntentSucceeded($setupIntent)
    {
        Log::info('SetupIntent succeeded', [
            'setup_intent_id' => $setupIntent->id,
            'customer' => $setupIntent->customer,
        ]);
        
        // Update userdashboard database (use 'userdashboard' connection)
        DB::connection('userdashboard')->table('customers')
            ->where('setup_intent_id', $setupIntent->id)
            ->update(['bank_account_status' => 'verified']);
    }

    protected function handleSetupIntentFailed($setupIntent)
    {
        Log::error('SetupIntent failed', [
            'setup_intent_id' => $setupIntent->id,
            'error' => $setupIntent->last_setup_error ?? null,
        ]);
        
        DB::connection('userdashboard')->table('customers')
            ->where('setup_intent_id', $setupIntent->id)
            ->update(['bank_account_status' => 'failed']);
    }

    protected function handlePaymentIntentSucceeded($paymentIntent)
    {
        Log::info('PaymentIntent succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'metadata' => $paymentIntent->metadata ?? [],
        ]);
        
        // Update all payment tables based on metadata
        $this->updatePaymentByPaymentIntent($paymentIntent, 2); // 2 = succeeded
    }

    protected function handlePaymentIntentProcessing($paymentIntent)
    {
        Log::info('PaymentIntent processing', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'metadata' => $paymentIntent->metadata ?? [],
        ]);
        
        // Update all payment tables based on metadata
        $this->updatePaymentByPaymentIntent($paymentIntent, 1); // 1 = processing/pending
    }

    protected function handlePaymentIntentFailed($paymentIntent)
    {
        Log::error('PaymentIntent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error ?? null,
            'metadata' => $paymentIntent->metadata ?? [],
        ]);
        
        // Update all payment tables based on metadata
        $this->updatePaymentByPaymentIntent($paymentIntent, 3); // 3 = failed
    }

    protected function handlePaymentIntentRequiresAction($paymentIntent)
    {
        Log::info('PaymentIntent requires action', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'next_action' => $paymentIntent->next_action ?? null,
            'metadata' => $paymentIntent->metadata ?? [],
        ]);
        
        // Update all payment tables based on metadata
        // For ACH, requires_action means payment is pending customer action
        // Map to status 1 (pending/processing) for compatibility
        $this->updatePaymentByPaymentIntent($paymentIntent, 1); // 1 = pending/requires_action
    }

    protected function handleChargeEvent(string $eventType, $charge)
    {
        $invoice = DB::connection('userdashboard')->table('invoice_payment')
            ->where('charge_id', $charge->id)
            ->orWhere('subscription_id', $charge->subscription ?? null)
            ->first();

        if ($invoice) {
            $status = match($eventType) {
                'charge.pending' => 1,
                'charge.succeeded' => 2,
                default => 3,
            };

            DB::connection('userdashboard')->table('invoice_payment')
                ->where('id', $invoice->id)
                ->update([
                    'payment_status' => $status,
                    'charge_json' => json_encode($charge),
                    'updated_at' => now(),
                ]);

            Log::info("Invoice updated via Charge", ['invoice_id' => $invoice->id, 'status' => $status]);
        }
    }

    /**
     * Update payment records across all tables based on PaymentIntent metadata
     * Supports: invoice_payment, rule_payment, request_payment, additional_price, payment_cronside
     */
    protected function updatePaymentByPaymentIntent($paymentIntent, int $status)
    {
        $paymentIntentId = $paymentIntent->id;
        $paymentIntentJson = json_encode($paymentIntent);
        $metadata = $paymentIntent->metadata ?? [];
        $orderType = $metadata['order_type'] ?? null;
        $orderId = $metadata['order_id'] ?? null;
        $userId = $metadata['user_id'] ?? null;

        // Extract balance_transaction from PaymentIntent charges for payment_cronside
        $balanceTransaction = null;
        if (isset($paymentIntent->charges->data[0]->balance_transaction)) {
            $balanceTransaction = $paymentIntent->charges->data[0]->balance_transaction;
        } elseif (isset($paymentIntent->latest_charge)) {
            $latestCharge = is_string($paymentIntent->latest_charge) 
                ? \Stripe\Charge::retrieve($paymentIntent->latest_charge) 
                : $paymentIntent->latest_charge;
            if ($latestCharge && isset($latestCharge->balance_transaction)) {
                $balanceTransaction = $latestCharge->balance_transaction;
            }
        }

        // Update based on order_type in metadata
        switch ($orderType) {
            case 'request_payment':
                $this->updateRequestPayment($paymentIntentId, $paymentIntentJson, $userId, $status);
                break;

            case 'additional_charge':
                $cartId = $metadata['cart_id'] ?? null;
                $this->updateAdditionalPrice($paymentIntentId, $paymentIntentJson, $userId, $cartId, $status);
                break;

            case 'commission_payment':
                $adminId = $metadata['admin_id'] ?? null;
                $month = $metadata['month'] ?? null;
                $year = $metadata['year'] ?? null;
                $this->updatePaymentCronside($paymentIntentId, $paymentIntentJson, $balanceTransaction, $adminId, $month, $year, $status);
                break;

            default:
                // Default: Try to update invoice_payment or rule_payment based on order_id
                if ($orderId) {
                    $this->updateInvoiceOrRulePayment($paymentIntentId, $paymentIntentJson, $orderId, $status);
                } else {
                    // Fallback: Try to find by PaymentIntent ID in charge_id field (field reuse strategy)
                    $this->updatePaymentByChargeId($paymentIntentId, $paymentIntentJson, $status);
                }
                break;
        }
    }

    /**
     * Update invoice_payment or rule_payment based on order_id metadata
     * order_id format: "invoice_id|user_id" or "rule_payment_id|user_id" or "rule_payment_id1,rule_payment_id2|user_id"
     */
    protected function updateInvoiceOrRulePayment(string $paymentIntentId, string $paymentIntentJson, string $orderId, int $status)
    {
        $parts = explode('|', $orderId);
        $orderIds = $parts[0] ?? null;
        $userId = $parts[1] ?? null;

        if (!$orderIds) {
            return;
        }

        // Check if it's multiple IDs (comma-separated) - indicates rule_payment
        $orderIdArray = explode(',', $orderIds);
        
        if (count($orderIdArray) > 1) {
            // Multiple rule_payment_ids - update rule_payment table
            DB::connection('userdashboard')->table('rule_payment')
                ->whereIn('rule_payment_id', $orderIdArray)
                ->update([
                    'charge_id' => $paymentIntentId, // Store PaymentIntent ID in charge_id field (field reuse)
                    'charge_json' => $paymentIntentJson,
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);
            
            Log::info('Rule payments updated via PaymentIntent', [
                'payment_intent_id' => $paymentIntentId,
                'rule_payment_ids' => $orderIdArray,
                'status' => $status,
            ]);
        } else {
            // Single ID - could be invoice_payment or rule_payment
            $singleOrderId = $orderIdArray[0];
            
            // Try invoice_payment first
            $invoice = DB::connection('userdashboard')->table('invoice_payment')
                ->where('invoice_id', $singleOrderId)
                ->first();
            
            if ($invoice) {
                DB::connection('userdashboard')->table('invoice_payment')
                    ->where('invoice_id', $singleOrderId)
                    ->update([
                        'charge_id' => $paymentIntentId, // Store PaymentIntent ID in charge_id field (field reuse)
                        'charge_json' => $paymentIntentJson,
                        'payment_status' => $status,
                        'updated_at' => now(),
                    ]);
                
                Log::info('Invoice payment updated via PaymentIntent', [
                    'payment_intent_id' => $paymentIntentId,
                    'invoice_id' => $singleOrderId,
                    'status' => $status,
                ]);
            } else {
                // Try rule_payment
                $rulePayment = DB::connection('userdashboard')->table('rule_payment')
                    ->where('rule_payment_id', $singleOrderId)
                    ->first();
                
                if ($rulePayment) {
                    DB::connection('userdashboard')->table('rule_payment')
                        ->where('rule_payment_id', $singleOrderId)
                        ->update([
                            'charge_id' => $paymentIntentId, // Store PaymentIntent ID in charge_id field (field reuse)
                            'charge_json' => $paymentIntentJson,
                            'payment_status' => $status,
                            'updated_at' => now(),
                        ]);
                    
                    Log::info('Rule payment updated via PaymentIntent', [
                        'payment_intent_id' => $paymentIntentId,
                        'rule_payment_id' => $singleOrderId,
                        'status' => $status,
                    ]);
                }
            }
        }
    }

    /**
     * Update request_payment table
     */
    protected function updateRequestPayment(string $paymentIntentId, string $paymentIntentJson, ?string $userId, int $status)
    {
        if (!$userId) {
            return;
        }

        DB::connection('userdashboard')->table('request_payment')
            ->where('customer_id', $userId)
            ->where(function($query) use ($paymentIntentId) {
                $query->where('txt_id', $paymentIntentId) // Match by PaymentIntent ID
                      ->orWhereNull('txt_id'); // Or find latest pending payment
            })
            ->orderBy('created_at', 'desc')
            ->limit(1)
            ->update([
                'txt_id' => $paymentIntentId, // Store PaymentIntent ID in txt_id field (field reuse)
                'all_responce' => $paymentIntentJson,
                'payment_status' => $status,
                'updated_at' => now(),
            ]);

        Log::info('Request payment updated via PaymentIntent', [
            'payment_intent_id' => $paymentIntentId,
            'user_id' => $userId,
            'status' => $status,
        ]);
    }

    /**
     * Update additional_price table
     */
    protected function updateAdditionalPrice(string $paymentIntentId, string $paymentIntentJson, ?string $userId, ?string $cartId, int $status)
    {
        $query = DB::connection('userdashboard')->table('additional_price');
        
        if ($cartId) {
            $query->where('cart_id', $cartId);
        } elseif ($userId) {
            $query->where('customer_id', $userId);
        } else {
            return;
        }

        $query->where(function($q) use ($paymentIntentId) {
            $q->where('txt_id', $paymentIntentId) // Match by PaymentIntent ID
              ->orWhereNull('txt_id'); // Or find latest pending payment
        })
        ->orderBy('created_at', 'desc')
        ->limit(1)
        ->update([
            'txt_id' => $paymentIntentId, // Store PaymentIntent ID in txt_id field (field reuse)
            'all_responce' => $paymentIntentJson,
            'payment_status' => $status,
            'updated_at' => now(),
        ]);

        Log::info('Additional price updated via PaymentIntent', [
            'payment_intent_id' => $paymentIntentId,
            'user_id' => $userId,
            'cart_id' => $cartId,
            'status' => $status,
        ]);
    }

    /**
     * Update payment_cronside table (commission payments)
     */
    protected function updatePaymentCronside(string $paymentIntentId, string $paymentIntentJson, ?string $balanceTransaction, ?string $adminId, ?string $month, ?string $year, int $status)
    {
        $query = DB::connection('userdashboard')->table('payment_cronside');
        
        if ($adminId && $month && $year) {
            $query->where('admin_user_id', $adminId)
                  ->where('st_month', (int)$month) // Use st_month column
                  ->where('st_year', (int)$year); // Use st_year column
        } else {
            // Fallback: find by PaymentIntent ID
            $query->where('stripe_pay_id', $paymentIntentId);
        }

        // Map status to string values used in payment_cronside table
        $statusString = match($status) {
            1 => 'processing', // processing/pending
            2 => 'succeeded',  // succeeded
            3 => 'failed',     // failed
            default => 'processing',
        };

        $updateData = [
            'stripe_pay_id' => $paymentIntentId, // Store PaymentIntent ID in stripe_pay_id field
            'json_data' => $paymentIntentJson,
            'payment_status' => $statusString, // payment_cronside.payment_status is varchar
            'updated_at' => now(),
        ];

        if ($balanceTransaction) {
            $updateData['trans_id'] = $balanceTransaction; // Store balance_transaction
        }

        $query->orderBy('created_at', 'desc')
              ->limit(1)
              ->update($updateData);

        Log::info('Payment cronside updated via PaymentIntent', [
            'payment_intent_id' => $paymentIntentId,
            'admin_id' => $adminId,
            'month' => $month,
            'year' => $year,
            'status' => $statusString,
        ]);
    }

    /**
     * Fallback: Update payment records by searching charge_id field for PaymentIntent ID
     * This handles cases where metadata might not be present
     */
    protected function updatePaymentByChargeId(string $paymentIntentId, string $paymentIntentJson, int $status)
    {
        // Try invoice_payment
        $invoice = DB::connection('userdashboard')->table('invoice_payment')
            ->where('charge_id', $paymentIntentId)
            ->first();
        
        if ($invoice) {
            DB::connection('userdashboard')->table('invoice_payment')
                ->where('charge_id', $paymentIntentId)
                ->update([
                    'charge_json' => $paymentIntentJson,
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);
            return;
        }

        // Try rule_payment
        $rulePayment = DB::connection('userdashboard')->table('rule_payment')
            ->where('charge_id', $paymentIntentId)
            ->first();
        
        if ($rulePayment) {
            DB::connection('userdashboard')->table('rule_payment')
                ->where('charge_id', $paymentIntentId)
                ->update([
                    'charge_json' => $paymentIntentJson,
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);
            return;
        }

        // Try request_payment
        $requestPayment = DB::connection('userdashboard')->table('request_payment')
            ->where('txt_id', $paymentIntentId)
            ->first();
        
        if ($requestPayment) {
            DB::connection('userdashboard')->table('request_payment')
                ->where('txt_id', $paymentIntentId)
                ->update([
                    'all_responce' => $paymentIntentJson,
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);
            return;
        }

        // Try additional_price
        $additionalPrice = DB::connection('userdashboard')->table('additional_price')
            ->where('txt_id', $paymentIntentId)
            ->first();
        
        if ($additionalPrice) {
            DB::connection('userdashboard')->table('additional_price')
                ->where('txt_id', $paymentIntentId)
                ->update([
                    'all_responce' => $paymentIntentJson,
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);
            return;
        }

        // Try payment_cronside
        $paymentCronside = DB::connection('userdashboard')->table('payment_cronside')
            ->where('stripe_pay_id', $paymentIntentId)
            ->first();
        
        if ($paymentCronside) {
            DB::connection('userdashboard')->table('payment_cronside')
                ->where('stripe_pay_id', $paymentIntentId)
                ->update([
                    'json_data' => $paymentIntentJson,
                    'payment_status' => (string)$status,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Legacy method - kept for backward compatibility
     * @deprecated Use updatePaymentByPaymentIntent instead
     */
    protected function updateInvoiceByPaymentIntent($paymentIntent, int $status)
    {
        $this->updatePaymentByPaymentIntent($paymentIntent, $status);
    }
}

