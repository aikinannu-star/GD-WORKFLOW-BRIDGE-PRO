# Payment Gateway System Documentation

## Overview

The GDWB SaaS platform supports multiple payment gateways with dynamic provider selection, automatic fallback, and unified API. This enables:

- **Global coverage** — Support customers in different regions
- **Redundancy** — Fallback providers if primary gateway fails
- **Flexibility** — Switch providers without code changes
- **Simplicity** — Unified interface for all providers

## Supported Payment Gateways

### 1. Stripe
**Region:** Global (US, EU, Asia, etc.)  
**Currencies:** 11+ currencies  
**Features:** Subscriptions, one-time charges, refunds, webhooks  
**Best for:** Global SaaS, high volume

- Primary gateway for most regions
- Most comprehensive webhook support
- Strong regional compliance

**Setup:**
```bash
STRIPE_API_KEY=sk_live_XXXXX
STRIPE_WEBHOOK_SECRET=whsec_XXXXX
```

### 2. Paystack ⭐ NEW
**Region:** Africa (Nigeria, Ghana, South Africa, Kenya, Uganda, etc.)  
**Currencies:** NGN, USD, GHS, ZAR, UGX, KES  
**Features:** Subscriptions, one-time charges, refunds  
**Best for:** African markets

- Primary for Nigeria and West Africa
- Local payment methods (bank transfers, USSD, card)
- Competitive rates

**Setup:**
```bash
PAYSTACK_PUBLIC_KEY=pk_live_XXXXX
PAYSTACK_SECRET_KEY=sk_live_XXXXX
```

### 3. Razorpay
**Region:** South Asia (India, Bangladesh, Pakistan, Sri Lanka)  
**Currencies:** INR, USD, AED, GBP, EUR, AUD  
**Features:** Subscriptions, one-time charges, refunds  
**Best for:** Indian market

- Primary for India and South Asia
- Indian payment methods (UPI, NetBanking, Wallets)
- Excellent for recurring billing

**Setup:**
```bash
RAZORPAY_KEY_ID=rzp_live_XXXXX
RAZORPAY_KEY_SECRET=XXXXX
```

### 4. PayPal
**Region:** Global (150+ countries)  
**Currencies:** 20+ currencies  
**Features:** Subscriptions, one-time charges, refunds  
**Best for:** Global fallback

- Fallback for all regions
- Highest geographic coverage
- Consumer familiar brand

**Setup:**
```bash
PAYPAL_CLIENT_ID=XXXXX
PAYPAL_CLIENT_SECRET=XXXXX
PAYPAL_MODE=sandbox  # or 'live'
```

## Architecture

```
PaymentGatewayManager (Orchestrator)
    ├── StripePaymentGateway
    ├── PaystackPaymentGateway
    ├── RazorpayPaymentGateway
    └── PayPalPaymentGateway

All implement PaymentGatewayInterface
```

## API Implementation

### Initialize Payment Manager

```php
require_once 'integrations/payments/PaymentGatewayManager.php';

$config = [
    'stripe' => ['api_key' => 'sk_live_...', 'webhook_secret' => '...'],
    'paystack' => ['public_key' => 'pk_live_...', 'secret_key' => 'sk_live_...'],
    'razorpay' => ['key_id' => '...', 'key_secret' => '...'],
    'paypal' => ['client_id' => '...', 'client_secret' => '...'],
];

$paymentManager = new PaymentGatewayManager($config);
```

### Select Gateway by Location

```php
// Automatically select based on country
$paymentManager->selectByLocation('NG'); // Nigeria → Paystack
$paymentManager->selectByLocation('IN'); // India → Razorpay
$paymentManager->selectByLocation('US'); // USA → Stripe

// Get the selected gateway
$gateway = $paymentManager->getActive();
```

### Select Gateway by Currency

```php
// Automatically find provider supporting currency
$paymentManager->selectByCurrency('NGN');  // → Paystack
$paymentManager->selectByCurrency('INR');  // → Razorpay
$paymentManager->selectByCurrency('USD');  // → Stripe
```

### Create Customer

```php
$result = $paymentManager->createCustomer([
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'phone' => '+1234567890',
    'metadata' => ['account_id' => 123],
], 'stripe'); // Optional: specific provider
```

Response:
```json
{
    "customer_id": "cus_XXXXX",
    "provider_id": "cus_XXXXX",
    "provider": "stripe",
    "created_at": "2026-06-03T10:00:00Z"
}
```

### Create Subscription

```php
$result = $paymentManager->createSubscription(
    customerId: 'cus_XXXXX',
    planId: 'price_XXXXX',
    metadata: ['tier' => 'pro', 'account_id' => 123],
    provider: 'stripe' // Optional
);
```

Response:
```json
{
    "subscription_id": "sub_XXXXX",
    "provider_id": "sub_XXXXX",
    "status": "active",
    "current_period_end": 1234567890,
    "provider": "stripe",
    "gateway_used": "stripe"
}
```

### Create One-Time Charge

```php
$result = $paymentManager->charge(
    customerId: 'cus_XXXXX',
    amount: 9999, // Amount in cents
    currency: 'USD',
    metadata: ['product_id' => 'prod_123'],
    provider: 'stripe' // Optional
);
```

Response:
```json
{
    "charge_id": "ch_XXXXX",
    "provider_id": "ch_XXXXX",
    "status": "succeeded",
    "amount": 9999,
    "currency": "USD",
    "gateway_used": "stripe"
}
```

### Execute with Fallback

Automatically try multiple providers:

```php
$result = $paymentManager->executeWithFallback(
    operation: function($gateway) {
        return $gateway->charge(
            customerId: 'cus_XXXXX',
            amount: 9999,
            currency: 'USD'
        );
    },
    primaryProvider: 'stripe'
);

// If Stripe fails, automatically tries PayPal, then Razorpay, then Paystack
```

## Billing Service Endpoints

### 1. Get Available Gateways

```
GET /api/v1/gateways
```

Response:
```json
{
    "providers": ["stripe", "paystack", "razorpay", "paypal"],
    "capabilities": {
        "stripe": {
            "provider": "stripe",
            "supported_currencies": ["USD", "EUR", "GBP", ...],
            "features": {
                "subscriptions": true,
                "one_time_charges": true,
                "refunds": true,
                "webhooks": true
            }
        },
        ...
    }
}
```

### 2. Create Subscription

```
POST /api/v1/subscriptions
```

Request:
```json
{
    "customer_id": "cus_XXXXX",
    "plan_id": "price_XXXXX",
    "gateway": "stripe",
    "metadata": {
        "tier": "pro",
        "account_id": 123
    }
}
```

### 3. Get Subscription

```
GET /api/v1/subscriptions/:id?gateway=stripe
```

### 4. Update Subscription

```
PUT /api/v1/subscriptions/:id
```

Request:
```json
{
    "gateway": "stripe",
    "updates": {
        "plan": "price_NEW_XXXXX",
        "metadata": {"tier": "enterprise"}
    }
}
```

### 5. Cancel Subscription

```
POST /api/v1/subscriptions/:id/cancel
```

Request:
```json
{
    "gateway": "stripe",
    "immediate": false
}
```

### 6. Create Charge

```
POST /api/v1/charges
```

Request:
```json
{
    "customer_id": "cus_XXXXX",
    "amount": 9999,
    "currency": "USD",
    "gateway": "stripe",
    "metadata": {"invoice_id": "inv_123"}
}
```

### 7. List Invoices

```
GET /api/v1/invoices?customer_id=cus_XXXXX&gateway=stripe&limit=10
```

### 8. Webhook Handler (Multi-Gateway)

```
POST /api/v1/webhooks/stripe
POST /api/v1/webhooks/paystack
POST /api/v1/webhooks/razorpay
POST /api/v1/webhooks/paypal
```

Each endpoint verifies webhook signatures and processes events.

## Regional Gateway Selection Strategy

| Region | Primary | Fallback | Currency |
|--------|---------|----------|----------|
| Nigeria | Paystack | PayPal | NGN/USD |
| Ghana | Paystack | PayPal | GHS |
| South Africa | Paystack | PayPal | ZAR |
| India | Razorpay | PayPal | INR |
| USA | Stripe | PayPal | USD |
| Europe | Stripe | PayPal | EUR/GBP |
| Australia | Stripe | PayPal | AUD |
| Canada | Stripe | PayPal | CAD |
| Global | Stripe | PayPal | USD |

## Error Handling & Fallback

```php
try {
    // Try Stripe first
    $paymentManager->setActiveGateway('stripe');
    $result = $paymentManager->getActive()->createSubscription(...);
} catch (Exception $e) {
    // Automatically try fallback providers
    $paymentManager->setFallbackChain(['paypal', 'razorpay', 'paystack']);
    $result = $paymentManager->executeWithFallback(function($gateway) {
        return $gateway->createSubscription(...);
    });
}
```

## Adding New Payment Providers

### 1. Create Gateway Class

```php
class SquarePaymentGateway implements PaymentGatewayInterface
{
    public function authenticate(array $config): bool { ... }
    public function createCustomer(array $data): array { ... }
    public function createSubscription(...): array { ... }
    // ... implement all methods
}
```

### 2. Register Gateway

```php
PaymentGatewayManager::registerGateway('square', 'SquarePaymentGateway');
```

### 3. Add Configuration

```env
SQUARE_APPLICATION_ID=XXXXX
SQUARE_ACCESS_TOKEN=XXXXX
```

### 4. Use in Code

```php
$paymentManager->selectByLocation('US');
// or
$paymentManager->setActiveGateway('square');
```

## Currency Support

### Stripe
USD, EUR, GBP, JPY, AUD, CAD, CHF, CNY, INR, MXN, ZAR

### Paystack
NGN, USD, GHS, ZAR, UGX, KES

### Razorpay
INR, USD, AED, GBP, EUR, AUD

### PayPal
USD, EUR, GBP, JPY, AUD, CAD, CHF, CNY, SEK, NZD, MXN, SGD, HKD, HUF, CZK, INR, MYR, PHP, TWD, THB, BRL, ZAR

## Webhook Signatures

Each provider has different webhook verification:

```php
// Stripe
$gateway->verifyWebhookSignature(
    payload: $rawBody,
    signature: $_SERVER['HTTP_STRIPE_SIGNATURE']
);

// Paystack
$gateway->verifyWebhookSignature(
    payload: $rawBody,
    signature: $_SERVER['HTTP_X_PAYSTACK_SIGNATURE']
);

// Razorpay
$gateway->verifyWebhookSignature(
    payload: $rawBody,
    signature: $_SERVER['HTTP_X_RAZORPAY_SIGNATURE']
);
```

## Testing

### Use Sandbox Mode

```env
# Stripe
STRIPE_API_KEY=sk_test_XXXXX

# Paystack
PAYSTACK_SECRET_KEY=sk_test_XXXXX

# Razorpay
RAZORPAY_KEY_ID=rzp_test_XXXXX

# PayPal
PAYPAL_MODE=sandbox
```

### Test Cards

**Stripe:**
- 4242 4242 4242 4242 (Visa)
- 5555 5555 5555 4444 (Mastercard)

**Paystack:**
- 4084084084084081

**Razorpay:**
- 4111111111111111

**PayPal:**
- Use sandbox accounts at developer.paypal.com

## Best Practices

1. **Always set fallback chain** — Ensure service availability
2. **Verify webhook signatures** — Prevent fraud
3. **Store provider reference IDs** — For reconciliation
4. **Log all transactions** — For audit trail
5. **Handle rate limits** — Implement exponential backoff
6. **Update payment methods** — Refresh expired cards
7. **Monitor gateway status** — Use health check endpoints
8. **Test regularly** — Use sandbox environments

## Performance Optimization

- Cache gateway capabilities
- Connection pooling for API calls
- Async webhook processing
- Circuit breaker pattern for failed providers
- Request deduplication with idempotency keys

## Compliance

- **PCI-DSS** — Never store raw card data (all gateways handle this)
- **GDPR** — Customer data retention policies
- **Local regulations** — Currency conversion, tax handling
- **Fraud detection** — Webhook monitoring and logging
