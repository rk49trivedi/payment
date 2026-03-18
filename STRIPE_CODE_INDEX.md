# Stripe Code Index – File & Function Reference

Quick index of all Stripe-related code across **payment** and **userdashboard** workspaces for implementation and review.  
**Reference:** [Stripe API (PHP) – 2026-01-28.clover](https://docs.stripe.com/api?lang=php&api-version=2026-01-28.clover)

---

## Integration model (ACH + CC; all Stripe in payment workspace)

- **Dual payment:** The app supports **both ACH (Business Checking)** and **CC (credit card)** – not only ACH. Which path runs depends on how the user registered (signup with ACH vs signup to bill credit card) and what payment method they use on the portal.
- **ACH path:** User signed up with ACH Business Checking or has ACH bank details → Stripe ACH code (SetupIntent, Financial Connections, ACH PaymentIntent, mandates). Used e.g. at **`customer/cc_create?type=ach`**.
- **CC path:** User signed up to bill credit card or adds CC on the portal → Stripe CC code (create-customer-with-card, card PaymentIntent, PaymentMethod). Used e.g. at **`customer/cc_create`** (no `type=ach`).
- **Single place for Stripe:** **All** Stripe-related code (ACH and CC) is implemented in the **payment** workspace. Userdashboard only calls the payment API via **PaymentApiService**; it does not use the Stripe SDK directly.

---

## Workspace: payment

### 1. `app/Services/StripeACHService.php`

| Function | Line (approx) | Stripe API / usage | Note |
|----------|----------------|--------------------|------|
| `__construct` | 18–21 | `Stripe::setApiKey()` | Optional: migrate to `StripeClient` |
| `createCustomer` | 25–33 | `Customer::create` | OK |
| `getCustomer` | 38–41 | `Customer::retrieve` | OK |
| `getCustomerDefaultPaymentMethod` | 46–77 | `Customer` + `PaymentMethod::all` | OK |
| `createSetupIntent` | 82–96 | `SetupIntent::create` (Financial Connections) | OK |
| `createACHSetupIntent` | 102–105 | Delegates to `createSetupIntent` | OK, deprecated alias |
| `getSetupIntent` | 110–113 | `SetupIntent::retrieve` | OK |
| `getPaymentMethod` | 118–121 | `PaymentMethod::retrieve` | OK |
| `createACHPaymentIntent` | 127–155 | `PaymentIntent::create` (automatic_payment_methods) | OK |
| `createPaymentIntent` | 162–193 | `PaymentIntent::create` (automatic_payment_methods) | OK |
| `confirmPaymentIntent` | 198–205 | `PaymentIntent::retrieve()->confirm` | OK |
| `getPaymentIntent` | 210–213 | `PaymentIntent::retrieve` | OK |
| `createPrice` | 218–230 | `Price::create` | OK |
| `createSubscription` | 237–331 | `Subscription::create`, `Invoice`, `PaymentIntent::retrieve` | OK |
| `getSubscription` | 336–339 | `Subscription::retrieve` | OK |
| `cancelSubscription` | 344–353 | `Subscription::retrieve`, `update` / `cancel` | OK |
| `createCustomerWithCard` | 359–404 | `Token::retrieve`, `PaymentMethod::create` + attach, **fallback** `Customer::createSource` | **Update:** remove/narrow createSource fallback |
| `migrateBankAccountToPaymentMethod` | 414–430 | `Customer::retrieve`, `$customer->sources->retrieve` | **Migration only** – Sources API |
| `backfillMandateForPaymentMethod` | 371–382 | `PaymentMethod::retrieve`, `Mandate::retrieve` | OK |
| `deleteCoupon` | 384–416 | `Coupon::retrieve`, `delete` | OK |
| `listInvoices` | 598–634 | `\Stripe\Invoice::all` | OK |
| `updateCustomer` | 438–441 | `Customer::update` | OK |
| `updateSubscription` | 446–449 | `Subscription::update` | OK |
| `createPaymentMethod` | 454–457 | `PaymentMethod::create` | OK |
| `createRefund` | 681–708 | `\Stripe\Refund::create` | OK |
| `listRefunds` | 720–738 | `\Stripe\Refund::all` | OK |

---

### 2. `app/Http/Controllers/Api/StripeACHController.php`

| Function | Line (approx) | Calls / Stripe usage | Note |
|----------|----------------|------------------------|------|
| `__construct` | 15–18 | Injects `StripeACHService` | — |
| `createSetupIntent` | 24–67 | `stripeService->createCustomer`, `createSetupIntent` | OK |
| `confirmSetupIntent` | 73–133 | `stripeService->getSetupIntent`, `getPaymentMethod` | OK |
| `getPaymentMethodDetails` | 139–178 | `stripeService->getPaymentMethod` | OK |
| `createBankToken` | 183–228 | `\Stripe\Stripe::setApiKey`, `Token::create`, `Customer::create`, `Customer::retrieveSource` | **Legacy – ACH deprecated May 2026** |
| `verifyBankAccount` | 233–264 | `\Stripe\Stripe::setApiKey`, `Customer::retrieveSource` | **Legacy – ACH deprecated May 2026** |
| `createCustomerWithCard` | 273–330 | `stripeService->createCustomerWithCard`, `PaymentMethod::all` | OK (service has legacy fallback) |
| `createPaymentIntent` | 338–378 | `stripeService->createPaymentIntent` | OK |
| `createPrice` | 386–422 | `stripeService->createPrice` | OK |
| `createSubscription` | 430–476 | `stripeService->createSubscription` | OK |
| `getSubscription` | 483–513 | `stripeService->getSubscription` | OK |
| `cancelSubscription` | 519–549 | `stripeService->cancelSubscription` | OK |
| `migrateBankAccountToPaymentMethod` | 556–594 | `stripeService->migrateBankAccountToPaymentMethod` | Migration only |
| `backfillMandateForPaymentMethod` | 600–634 | `stripeService->backfillMandateForPaymentMethod` | OK |
| `listPaymentIntents` | 641–486 | `\Stripe\PaymentIntent::all` | OK |
| `listPaymentMethods` | 492–538 | `\Stripe\PaymentMethod::all` | OK |
| `detachPaymentMethod` | 545–578 | `\Stripe\PaymentMethod::retrieve`, `detach` | OK |
| `attachPaymentMethod` | 585–628 | `\Stripe\PaymentMethod::retrieve`, `attach` | OK |
| `createPaymentMethodFromToken` | 635–688 | `\Stripe\PaymentMethod::create`, `attach` | OK |
| `createSubscriptionSchedule` | 695–744 | `\Stripe\SubscriptionSchedule::create` | OK |
| `getSubscriptionSchedule` | 750–771 | `\Stripe\SubscriptionSchedule::retrieve` | OK |
| `updateSubscriptionSchedule` | 777–811 | `\Stripe\SubscriptionSchedule::update` | OK |
| `deleteCoupon` | 818–848 | `stripeService->deleteCoupon` | OK |
| `listInvoices` | 854–886 | `stripeService->listInvoices` | OK |
| `createCustomer` | 893–925 | `stripeService->createCustomer` | OK |
| `updateCustomer` | 931–963 | `stripeService->updateCustomer` | OK |
| `retrieveCustomer` | 969–1001 | `stripeService->getCustomer` | OK |
| `retrieveSubscription` | 1007–1039 | `stripeService->getSubscription` | OK |
| `updateSubscription` | 1045–1077 | `stripeService->updateSubscription` | OK |
| `createPaymentMethod` | 1083–1115 | `stripeService->createPaymentMethod` | OK |
| `retrievePaymentMethod` | 1121–1153 | `stripeService->getPaymentMethod` | OK |
| `createRefund` | 1159–1187 | `stripeService->createRefund` | OK |
| `listRefunds` | 1193–1206 | `stripeService->listRefunds` | OK |

---

### 3. `app/Http/Controllers/Api/StripeWebhookController.php`

| Function | Line (approx) | Stripe / usage | Note |
|----------|----------------|----------------|------|
| `handleWebhook` | 13–74 | `Webhook::constructEvent`, switch on `event->type` | OK |
| `handleSetupIntentSucceeded` | 76–88 | Log + DB update | OK |
| `handleSetupIntentFailed` | 90–100 | Log + DB update | OK |
| `handlePaymentIntentSucceeded` | 102–112 | `updatePaymentByPaymentIntent` | OK |
| `handlePaymentIntentProcessing` | 114–124 | `updatePaymentByPaymentIntent` | OK |
| `handlePaymentIntentFailed` | 126–136 | `updatePaymentByPaymentIntent` | OK |
| `handlePaymentIntentRequiresAction` | 138–151 | `updatePaymentByPaymentIntent` | OK |
| `handleChargeEvent` | 153–179 | Legacy charge events, DB update | Backward compat |
| `updatePaymentByPaymentIntent` | 184–234 | `$paymentIntent->charges->data`, `\Stripe\Charge::retrieve` | Optional: reduce Charge usage |
| `updateInvoiceOrRulePayment` | 240–317 | DB updates | OK |
| `updateRequestPayment` | 322–349 | DB update | OK |
| `updateAdditionalPrice` | 354–384 | DB update | OK |
| `updatePaymentCronside` | 389–431 | DB update | OK |
| `updatePaymentByChargeId` | 437–406 | DB updates | OK |
| `updateInvoiceByPaymentIntent` | 412–415 | Delegates to `updatePaymentByPaymentIntent` | Deprecated wrapper |

---

### 4. `routes/api.php`

| Route | Controller method | Note |
|-------|--------------------|------|
| `POST /api/stripe/create-setup-intent` | `StripeACHController@createSetupIntent` | OK |
| `POST /api/stripe/confirm-setup-intent` | `StripeACHController@confirmSetupIntent` | OK |
| `POST /api/stripe/get-payment-method` | `StripeACHController@getPaymentMethodDetails` | OK |
| `POST /api/stripe/create-bank-token` | `StripeACHController@createBankToken` | **Legacy – ACH deprecated May 2026** |
| `POST /api/stripe/verify-bank-account` | `StripeACHController@verifyBankAccount` | **Legacy – ACH deprecated May 2026** |
| `POST /api/stripe/create-customer-with-card` | `StripeACHController@createCustomerWithCard` | OK |
| `POST /api/stripe/create-payment-intent` | `StripeACHController@createPaymentIntent` | OK |
| `POST /api/stripe/create-price` | `StripeACHController@createPrice` | OK |
| `POST /api/stripe/create-subscription` | `StripeACHController@createSubscription` | OK |
| `GET /api/stripe/get-subscription/{id}` | `StripeACHController@getSubscription` | OK |
| `POST /api/stripe/cancel-subscription` | `StripeACHController@cancelSubscription` | OK |
| `POST /api/stripe/migrate-bank-account` | `StripeACHController@migrateBankAccountToPaymentMethod` | Migration only |
| `POST /api/stripe/backfill-mandate` | `StripeACHController@backfillMandateForPaymentMethod` | OK |
| `POST /api/stripe/list-payment-intents` | `StripeACHController@listPaymentIntents` | OK |
| `POST /api/stripe/list-payment-methods` | `StripeACHController@listPaymentMethods` | OK |
| `POST /api/stripe/detach-payment-method` | `StripeACHController@detachPaymentMethod` | OK |
| `POST /api/stripe/attach-payment-method` | `StripeACHController@attachPaymentMethod` | OK |
| `POST /api/stripe/create-payment-method-from-token` | `StripeACHController@createPaymentMethodFromToken` | OK |
| `GET /api/stripe/get-subscription-schedule/{id}` | `StripeACHController@getSubscriptionSchedule` | OK |
| `POST /api/stripe/update-subscription-schedule` | `StripeACHController@updateSubscriptionSchedule` | OK |
| `POST /api/stripe/create-subscription-schedule` | `StripeACHController@createSubscriptionSchedule` | OK |
| `POST /api/stripe/cancel-subscription-schedule` | `StripeACHController@cancelSubscriptionSchedule` | OK |
| `POST /api/stripe/create-coupon` | `StripeACHController@createCoupon` | OK |
| `POST /api/stripe/delete-coupon` | `StripeACHController@deleteCoupon` | OK |
| `POST /api/stripe/list-invoices` | `StripeACHController@listInvoices` | OK |
| `POST /api/stripe/create-customer` | `StripeACHController@createCustomer` | OK |
| `POST /api/stripe/update-customer/{id}` | `StripeACHController@updateCustomer` | OK |
| `GET /api/stripe/retrieve-customer/{id}` | `StripeACHController@retrieveCustomer` | OK |
| `GET /api/stripe/retrieve-subscription/{id}` | `StripeACHController@retrieveSubscription` | OK |
| `POST /api/stripe/update-subscription/{id}` | `StripeACHController@updateSubscription` | OK |
| `POST /api/stripe/create-payment-method` | `StripeACHController@createPaymentMethod` | OK |
| `GET /api/stripe/retrieve-payment-method/{id}` | `StripeACHController@retrievePaymentMethod` | OK |
| `POST /api/stripe/create-refund` | `StripeACHController@createRefund` | OK |
| `POST /api/stripe/list-refunds` | `StripeACHController@listRefunds` | OK |
| `POST /api/stripe/webhook` | `StripeWebhookController@handleWebhook` | OK |

---

## Workspace: userdashboard

### 5. `app/Services/PaymentApiService.php`

All methods call the **payment** API over HTTP; no direct Stripe SDK. Listed for review and mapping.

| Function | Payment API endpoint | Note |
|----------|------------------------|------|
| `getPaymentMethodDetails` | `POST /api/stripe/get-payment-method` | OK |
| `getPaymentMethod` | `POST /api/stripe/get-payment-method` | OK |
| `createBankToken` | `POST /api/stripe/create-bank-token` | Proxies legacy endpoint |
| `verifyBankAccount` | `POST /api/stripe/verify-bank-account` | Proxies legacy endpoint |
| `createSetupIntent` | `POST /api/stripe/create-setup-intent` | OK |
| `confirmSetupIntent` | `POST /api/stripe/confirm-setup-intent` | OK |
| `createCustomerWithCard` | `POST /api/stripe/create-customer-with-card` | OK |
| `createPaymentIntent` | `POST /api/stripe/create-payment-intent` | OK |
| `createPrice` | `POST /api/stripe/create-price` | OK |
| `createSubscription` | `POST /api/stripe/create-subscription` | OK |
| `getSubscription` | `GET /api/stripe/get-subscription/{id}` | OK |
| `cancelSubscription` | `POST /api/stripe/cancel-subscription` | OK |
| `migrateBankAccountToPaymentMethod` | `POST /api/stripe/migrate-bank-account` | Migration only |
| `backfillMandateForPaymentMethod` | `POST /api/stripe/backfill-mandate` | OK |
| `listPaymentIntents` | `POST /api/stripe/list-payment-intents` | OK |
| `listPaymentMethods` | `POST /api/stripe/list-payment-methods` | OK |
| `getSubscriptionSchedule` | `GET /api/stripe/get-subscription-schedule/{id}` | OK |
| `updateSubscriptionSchedule` | `POST /api/stripe/update-subscription-schedule` | OK |
| `detachPaymentMethod` | `POST /api/stripe/detach-payment-method` | OK |
| `attachPaymentMethod` | `POST /api/stripe/attach-payment-method` | OK |
| `createPaymentMethodFromToken` | `POST /api/stripe/create-payment-method-from-token` | OK |
| `createSubscriptionSchedule` | `POST /api/stripe/create-subscription-schedule` | OK |
| `deleteCoupon` | `POST /api/stripe/delete-coupon` | OK |
| `createCoupon` | `POST /api/stripe/create-coupon` | OK |
| `cancelSubscriptionSchedule` | `POST /api/stripe/cancel-subscription-schedule` | OK |
| `listInvoices` | `POST /api/stripe/list-invoices` | OK |
| `createCustomer` | `POST /api/stripe/create-customer` | OK |
| `updateCustomer` | `POST /api/stripe/update-customer/{id}` | OK |
| `retrieveCustomer` | `GET /api/stripe/retrieve-customer/{id}` | OK |
| `createPaymentMethod` | `POST /api/stripe/create-payment-method` | OK |
| `retrievePaymentMethod` | `GET /api/stripe/retrieve-payment-method/{id}` | OK |
| `createRefund` | `POST /api/stripe/create-refund` | OK |
| `listRefunds` | `POST /api/stripe/list-refunds` | OK |
| `retrieveSubscription` | `GET /api/stripe/retrieve-subscription/{id}` | OK |
| `updateSubscription` | `POST /api/stripe/update-subscription/{id}` | OK |
| `createToken` | `POST /api/stripe/create-token` | Token API (if implemented in payment) |

---

### 6. Controllers & views (userdashboard)

| File | Stripe-related usage | Note |
|------|----------------------|------|
| `app/Http/Controllers/customer/Homecontroller.php` | `PaymentApiService`: createCustomer, createSetupIntent, createPaymentIntent, listPaymentIntents, listInvoices, createRefund, getSubscriptionSchedule | No direct Stripe; OK. Used for both ACH and CC depending on user/signup. |
| `app/Http/Controllers/customer/Ajaxcontroller.php` | `PaymentApiService`: createPaymentIntent, updateSubscription, getPaymentMethod | No direct Stripe; OK. Used for both ACH and CC. |
| `resources/views/customer/cc_create.blade.php` | Stripe.js: `Stripe()`, `stripe.elements()`, `stripe.confirmCardSetup`, `stripe.collectBankAccountForSetup`, `stripe.confirmUsBankAccountSetup`; fetches `/api/stripe/create-setup-intent`, `/api/stripe/confirm-setup-intent`. **`?type=ach`** → ACH flow; no type → CC flow. | Current Stripe.js + Financial Connections; OK. Single view serves both ACH and CC; type param selects flow. |

---

## Legend

- **OK** – Uses current Stripe APIs; no change required for May 2026 ACH deprecation.
- **Legacy – ACH deprecated May 2026** – Uses Charges / Token / Sources API for ACH; must not be used for new ACH after deadline.
- **Migration only** – Acceptable for backfill/migration of existing data; do not use for new ACH signups.
- **Update** – Prefer removing or narrowing legacy fallbacks (e.g. `Customer::createSource`, Charge usage in webhook).

Use this index together with **STRIPE_DEPRECATION_REPORT.md** for implementation and review.
