# Stripe Deprecation & Upgrade Report

**Reference:** [Stripe API (PHP) – 2026-01-28.clover](https://docs.stripe.com/api?lang=php&api-version=2026-01-28.clover)  
**Deadline:** May 15, 2026 – Charges API for ACH Direct Debits will no longer be supported.

---

## Integration model (ACH + CC, all Stripe in payment workspace)

**Dual payment integration – not only ACH.** The codebase supports **both**:

| Path | When used | Stripe flow |
|------|-----------|-------------|
| **ACH (Business Checking)** | User signed up with ACH Business Checking, or has ACH bank details integrated on the portal | Stripe ACH-related code (SetupIntent + Financial Connections, PaymentIntent for ACH, mandates, etc.) |
| **CC (credit card)** | User signed up with “Sign up to bill credit card”, or adds CC details on the portal | Stripe CC-related code (card token → PaymentMethod, PaymentIntent for card, etc.) |

**User signup / phase determines which flow runs:**

- **Signup with ACH** → ACH Business Checking info collected → Stripe ACH integration (payment workspace).
- **Signup with CC** → Credit card billed → Stripe CC integration (payment workspace).

**`cc_create` routes (userdashboard):**

- **`customer/cc_create?type=ach`** – ACH bank detail integration; user adds/connects ACH Business Checking → calls **payment** workspace Stripe ACH endpoints (e.g. create-setup-intent, confirm-setup-intent, Financial Connections).
- **`customer/cc_create`** (no `type` or default) – User adds **credit card** details → Stripe payment is based on that → calls **payment** workspace Stripe CC endpoints (e.g. create-customer-with-card, create-payment-intent for card).

**All Stripe implementation lives in the payment workspace.**  
Userdashboard does **not** call Stripe directly; it uses **PaymentApiService** to call the **payment** API. Every Stripe-related operation (ACH and CC) is implemented in the **payment** workspace and invoked from userdashboard via HTTP to that API.

---

## Summary

| Workspace     | Files with Stripe code | Status: Need update | Status: OK (modern) |
|---------------|------------------------|---------------------|----------------------|
| **payment**   | 4                     | 6 items             | 30+ items            |
| **userdashboard** | 4                 | 0 (proxy only)      | All via PaymentApiService |

Stripe-related logic lives in the **payment** workspace. The **userdashboard** workspace calls it via `PaymentApiService` (HTTP to payment API). Deprecated or legacy patterns are only in **payment**; fixing them there is enough for the May 2026 ACH deadline.

---

## 1. NEED UPDATE (Deprecated / Legacy)

### 1.1 Payment workspace – Legacy Token API for ACH (deprecated May 2026)

| # | File | Function / Location | Current code / behavior | What to do |
|---|------|---------------------|-------------------------|------------|
| 1 | `payment/app/Http/Controllers/Api/StripeACHController.php` | `createBankToken()` (lines ~184–228) | Uses `\Stripe\Token::create(['bank_account' => [...]])`, then `\Stripe\Customer::create(['source' => $token->id])`, then `\Stripe\Customer::retrieveSource()`. Legacy ACH flow. | **Stop using for new ACH.** Prefer **Checkout Sessions API** or **SetupIntent + Financial Connections** for new bank collection. Use **Tokens API** only for backfill/migration of existing bank details per [Stripe migration steps](https://docs.stripe.com). Mark route as deprecated and document migration path. |
| 2 | `payment/app/Http/Controllers/Api/StripeACHController.php` | `verifyBankAccount()` (lines ~234–264) | Uses `\Stripe\Customer::retrieveSource($customerId, $bankId)` and micro-deposit verification. Legacy Sources API. | Same as above: new flows should use **Financial Connections** or other verification options. Keep only for backward compatibility during migration; add comment “Legacy – do not use for new ACH after May 2026”. |

**Routes (payment):**

- `POST /api/stripe/create-bank-token` → `createBankToken` — **NEED UPDATE** (legacy)
- `POST /api/stripe/verify-bank-account` → `verifyBankAccount` — **NEED UPDATE** (legacy)

---

### 1.2 Payment workspace – Legacy Sources / Token usage in service

| # | File | Function / Location | Current code / behavior | What to do |
|---|------|---------------------|-------------------------|------------|
| 3 | `payment/app/Services/StripeACHService.php` | `createCustomerWithCard()` (lines ~370–406) | Uses `\Stripe\Token::retrieve($data['stripe_token'])`. For card tokens, creates PaymentMethod and attaches; **fallback** uses `Customer::createSource($customer->id, ['source' => $data['stripe_token']])`. | Prefer **only** PaymentMethod + attach path. Remove or narrow the `createSource` fallback so it is not used for new integrations; optionally keep only for one-off migration. Rely on Stripe.js Elements / Payment Element for new card collection. |
| 4 | `payment/app/Services/StripeACHService.php` | `migrateBankAccountToPaymentMethod()` (line ~428) | Uses `$customer->sources->retrieve($bankAccountId)` (Sources API) to read legacy `ba_*` bank account. | For **backfill/migration** of existing bank accounts, Tokens API / migration flows are allowed. Prefer not adding new code paths that create or rely on Sources; ensure this is used only for migration. Document that new bank accounts must use SetupIntent + Financial Connections. |

---

### 1.3 Payment workspace – Charge API usage in webhook

| # | File | Function / Location | Current code / behavior | What to do |
|---|------|---------------------|-------------------------|------------|
| 5 | `payment/app/Http/Controllers/Api/StripeWebhookController.php` | `updatePaymentByPaymentIntent()` (lines ~196–208) | Gets `balance_transaction` via `$paymentIntent->charges->data[0]` or `\Stripe\Charge::retrieve($paymentIntent->latest_charge)`. | Charge object still exists for PaymentIntent-based payments in 2026, but for ACH Direct Debit Stripe is moving away from Charges API. Prefer reading from `$paymentIntent->latest_charge` expanded, or from `payment_intent.charges` without assuming Charges API for **new** ACH flows. Prefer **Checkout Sessions** for new ACH so webhooks use `checkout.session.*` and PaymentIntent only. No urgent change if you only use PaymentIntents for ACH; optional hardening. |

---

### 1.4 Payment workspace – Global API key (optional modernization)

| # | File | Location | Current code | What to do |
|---|------|----------|--------------|------------|
| 6 | `payment/app/Services/StripeACHService.php` | Constructor (line 20) | `Stripe::setApiKey(config('services.stripe.secret'))` | [Stripe PHP docs 2026](https://docs.stripe.com/api?lang=php&api-version=2026-01-28.clover) recommend `new \Stripe\StripeClient("sk_...")` and using that client for requests. | Optional: refactor to use `StripeClient` instance and pass it (or use per-request key) instead of global `setApiKey`. Low priority for deprecation deadline. |

---

## 2. NO UPDATE NEEDED (Already modern)

These use Payment Intents, Setup Intents, Payment Methods, or other non-deprecated APIs and are fine for post–May 2026. They cover **both ACH and CC** flows; the path used depends on the current registered user (signup type and payment method on the portal), as described in the Integration model above.

### Payment workspace

| File | Function / area | Notes |
|------|------------------|--------|
| `StripeACHService.php` | `createSetupIntent`, `getSetupIntent`, `createPaymentIntent`, `createACHPaymentIntent`, `confirmPaymentIntent`, `getPaymentIntent` | SetupIntent + PaymentIntent – recommended for 2026. |
| `StripeACHService.php` | `createCustomer`, `getCustomer`, `updateCustomer`, `getCustomerDefaultPaymentMethod` | Customers API – current. |
| `StripeACHService.php` | `getPaymentMethod`, `createPaymentMethod`, `createPrice`, `createSubscription`, `getSubscription`, `cancelSubscription`, `updateSubscription` | All current APIs. |
| `StripeACHService.php` | `listInvoices`, `createRefund`, `listRefunds`, `deleteCoupon`, `backfillMandateForPaymentMethod` | Invoices, Refunds, Coupons, Mandates – current. |
| `StripeACHController.php` | `createSetupIntent`, `confirmSetupIntent`, `getPaymentMethodDetails` | Financial Connections / SetupIntent flow – recommended. |
| `StripeACHController.php` | `createPaymentIntent`, `listPaymentIntents`, `listPaymentMethods`, `createCustomerWithCard` (PaymentMethod path) | PaymentIntent + PaymentMethod – current. |
| `StripeACHController.php` | `createCustomer`, `updateCustomer`, `retrieveCustomer`, subscription/schedule/coupon/refund/invoice endpoints | All proxy to service; no deprecated Stripe usage. |
| `StripeWebhookController.php` | `setup_intent.succeeded`, `setup_intent.setup_failed`, `payment_intent.*` handlers | Modern events. |
| `StripeWebhookController.php` | `charge.*` handlers | Kept for backward compatibility; can remain until legacy charges are fully migrated. |

### Userdashboard workspace

| File | Role | Notes |
|------|------|--------|
| `app/Services/PaymentApiService.php` | HTTP client to payment API | No direct Stripe SDK; all Stripe behavior is in payment. No change needed for deprecation. |
| `app/Http/Controllers/customer/Homecontroller.php` | Uses `PaymentApiService` for customer, setup intent, payment intent, invoices, refunds | No direct Stripe; OK. |
| `app/Http/Controllers/customer/Ajaxcontroller.php` | Uses `PaymentApiService` for payment intent, subscription, payment method | No direct Stripe; OK. |
| `resources/views/customer/cc_create.blade.php` | Stripe.js: `Stripe()`, `stripe.collectBankAccountForSetup`, `stripe.confirmUsBankAccountSetup`, `stripe.confirmCardSetup` | Uses current Stripe.js and Financial Connections; OK. |

---

## 3. Stripe’s migration steps (from email)

1. **Turn on ACH Direct Debits** in the Stripe Dashboard.  
2. **Build new integration** with **Checkout Sessions API** (and/or keep using **SetupIntent + Financial Connections** for saved bank accounts).  
3. **Backfill mandates** and migrate previously collected bank account details using the **Tokens API** where applicable.  
4. **Verify new bank accounts** and collect ACH mandates with **Financial Connections** or another supported verification option.  
5. **Test** the new flow before May 15, 2026.

---

## 4. Recommended order of work

1. **Ensure all new ACH** uses **SetupIntent + Financial Connections** (you already have this) and/or **Checkout Sessions**; do not add new flows using `create-bank-token` or `verify-bank-account`.  
2. **Document** that `createBankToken` and `verifyBankAccount` are legacy and for migration/backfill only; add a timeline to remove or restrict them after migration.  
3. **Refine** `StripeACHService::createCustomerWithCard` so the legacy `Customer::createSource` path is not used for new signups (card path only via PaymentMethod).  
4. **Keep** `migrateBankAccountToPaymentMethod` and `backfillMandateForPaymentMethod` for migration only; ensure no new ACH relies on Sources API.  
5. **(Optional)** Replace `Stripe::setApiKey()` with `StripeClient` and **(optional)** reduce reliance on `Charge::retrieve` in webhooks by using PaymentIntent/Checkout Session data.

---

## 5. Quick reference – “Need update” locations

| Workspace   | File | Function / line range |
|-------------|------|-----------------------|
| payment     | `app/Http/Controllers/Api/StripeACHController.php` | `createBankToken` (~184–228), `verifyBankAccount` (~234–264) |
| payment     | `app/Services/StripeACHService.php` | `createCustomerWithCard` (~370–406), `migrateBankAccountToPaymentMethod` (~428) |
| payment     | `app/Http/Controllers/Api/StripeWebhookController.php` | `updatePaymentByPaymentIntent` (~196–208) – optional |
| payment     | `app/Services/StripeACHService.php` | Constructor (~20) – optional (StripeClient) |

This report reflects the codebase as of the analysis date. Re-check against [Stripe API (PHP) – 2026-01-28.clover](https://docs.stripe.com/api?lang=php&api-version=2026-01-28.clover) before May 15, 2026.
