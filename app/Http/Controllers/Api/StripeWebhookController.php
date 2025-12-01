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
                    $this->updateInvoiceByPaymentIntent($event->data->object, 1);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event->data->object);
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
        ]);
        $this->updateInvoiceByPaymentIntent($paymentIntent, 2);
    }

    protected function handlePaymentIntentFailed($paymentIntent)
    {
        Log::error('PaymentIntent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error ?? null,
        ]);
        $this->updateInvoiceByPaymentIntent($paymentIntent, 3);
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

    protected function updateInvoiceByPaymentIntent($paymentIntent, int $status)
    {
        $invoiceId = $paymentIntent->metadata->order_id ?? null;
        if ($invoiceId) {
            $parts = explode('|', $invoiceId);
            $invoiceId = $parts[0] ?? null;
        }

        $query = DB::connection('userdashboard')->table('invoice_payment');
        $invoice = $invoiceId
            ? $query->where('invoice_id', $invoiceId)->first()
            : $query->where('payment_intent_id', $paymentIntent->id)->first();

        if ($invoice) {
            DB::connection('userdashboard')->table('invoice_payment')
                ->where('id', $invoice->id)
                ->update([
                    'payment_status' => $status,
                    'payment_intent_id' => $paymentIntent->id,
                    'charge_json' => json_encode($paymentIntent),
                    'updated_at' => now(),
                ]);
        }
    }
}

