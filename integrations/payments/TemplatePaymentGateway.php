<?php
/**
 * Payment Gateway Template
 * Use this as a template to implement new payment providers
 * 
 * Implementation Steps:
 * 1. Copy this file and rename to {ProviderName}PaymentGateway.php
 * 2. Implement all methods from PaymentGatewayInterface
 * 3. Register gateway in PaymentGatewayManager::registerGateway()
 * 4. Add API credentials to .env.example
 * 5. Test all operations in sandbox environment
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class TemplatePaymentGateway implements PaymentGatewayInterface
{
    private $apiKey;
    private $baseUrl = 'https://api.provider.com/v1';

    /**
     * 1. Authenticate - Initialize connection with provider credentials
     */
    public function authenticate(array $config): bool
    {
        if (!isset($config['api_key'])) {
            throw new Exception('API key required');
        }
        $this->apiKey = $config['api_key'];
        return true;
    }

    /**
     * Helper: Make API requests to payment provider
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception($result['error']['message'] ?? 'API error');
        }
        
        return $result;
    }

    /**
     * 2. Create Customer
     * Create a customer profile in the payment provider
     * 
     * Input: ['email', 'name', 'phone', 'metadata']
     * Output: ['customer_id', 'provider_id', 'provider', 'created_at']
     */
    public function createCustomer(array $data): array
    {
        $result = $this->request('POST', '/customers', [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);
        
        return [
            'customer_id' => $result['id'],
            'provider_id' => $result['id'],
            'provider' => 'template', // Change to your provider name
            'created_at' => date('c'),
        ];
    }

    /**
     * 3. Create Subscription
     * Set up a recurring billing subscription
     * 
     * Input: customerId, planId, metadata
     * Output: ['subscription_id', 'provider_id', 'status', 'current_period_end', 'provider']
     */
    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $result = $this->request('POST', '/subscriptions', [
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'metadata' => $metadata,
        ]);
        
        return [
            'subscription_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'current_period_end' => $result['current_period_end'],
            'provider' => 'template',
        ];
    }

    /**
     * 4. Get Subscription
     * Retrieve subscription details
     */
    public function getSubscription(string $subscriptionId): array
    {
        $result = $this->request('GET', "/subscriptions/$subscriptionId");
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'current_period_start' => $result['current_period_start'],
            'current_period_end' => $result['current_period_end'],
            'items' => $result['items'] ?? [],
        ];
    }

    /**
     * 5. Update Subscription
     * Modify subscription (plan, metadata, etc)
     */
    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        $data = [];
        
        if (isset($updates['plan'])) {
            $data['plan_id'] = $updates['plan'];
        }
        
        if (isset($updates['metadata'])) {
            $data['metadata'] = $updates['metadata'];
        }
        
        $result = $this->request('POST', "/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'updated_at' => date('c'),
        ];
    }

    /**
     * 6. Cancel Subscription
     * Cancel a subscription immediately or at period end
     */
    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        $data = $immediate ? [] : ['cancel_at_period_end' => true];
        
        $result = $this->request('DELETE', "/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'canceled_at' => $result['canceled_at'] ?? date('c'),
        ];
    }

    /**
     * 7. Charge Customer
     * Create a one-time charge/payment
     * 
     * Input: customerId, amount (in cents), currency, metadata
     * Output: ['charge_id', 'provider_id', 'status', 'amount', 'currency']
     */
    public function charge(string $customerId, int $amount, string $currency = 'USD', array $metadata = []): array
    {
        $result = $this->request('POST', '/charges', [
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => strtolower($currency),
            'metadata' => $metadata,
        ]);
        
        return [
            'charge_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
        ];
    }

    /**
     * 8. Refund
     * Create a refund for a charge
     */
    public function refund(string $chargeId, ?int $amount = null): array
    {
        $data = ['charge_id' => $chargeId];
        if ($amount !== null) {
            $data['amount'] = $amount;
        }
        
        $result = $this->request('POST', '/refunds', $data);
        
        return [
            'refund_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
        ];
    }

    /**
     * 9. Get Invoice
     * Retrieve a specific invoice
     */
    public function getInvoice(string $invoiceId): array
    {
        $result = $this->request('GET', "/invoices/$invoiceId");
        
        return [
            'invoice_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
        ];
    }

    /**
     * 10. List Invoices
     * Get invoices for a customer
     */
    public function listInvoices(string $customerId, int $limit = 10): array
    {
        $result = $this->request('GET', "/invoices?customer_id=$customerId&limit=$limit");
        
        return [
            'invoices' => array_map(function ($inv) {
                return [
                    'invoice_id' => $inv['id'],
                    'status' => $inv['status'],
                    'amount' => $inv['amount'],
                ];
            }, $result['data'] ?? []),
        ];
    }

    /**
     * 11. Verify Webhook Signature
     * Verify that webhook came from the payment provider
     * 
     * Usually involves HMAC verification
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // Implement provider-specific signature verification
        $hash = hash_hmac('sha256', $payload, $this->apiKey);
        return hash_equals($hash, $signature);
    }

    /**
     * 12. Process Webhook Event
     * Handle webhook events from payment provider
     * 
     * Common events:
     * - customer.created
     * - subscription.created/updated/canceled
     * - charge.succeeded/failed
     * - invoice.payment_succeeded/failed
     */
    public function processWebhookEvent(array $event): array
    {
        $type = $event['type'] ?? null;
        $data = $event['data'] ?? [];
        
        switch ($type) {
            case 'customer.created':
                return ['event_type' => 'customer_created', 'customer_id' => $data['id']];
            case 'subscription.created':
                return ['event_type' => 'subscription_created', 'subscription_id' => $data['id']];
            case 'charge.succeeded':
                return ['event_type' => 'payment_succeeded', 'charge_id' => $data['id']];
            case 'charge.failed':
                return ['event_type' => 'payment_failed', 'charge_id' => $data['id']];
            default:
                return ['event_type' => 'unknown'];
        }
    }

    /**
     * 13. Get Provider Name
     * Return the provider identifier
     */
    public function getProvider(): string
    {
        return 'template';  // Change to your provider name
    }

    /**
     * 14. Get Supported Currencies
     * List currencies this provider supports
     */
    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];  // Add all supported currencies
    }
}

/**
 * REGISTRATION STEPS:
 * 
 * 1. Add to PaymentGatewayManager.php:
 *    PaymentGatewayManager::registerGateway('template', 'TemplatePaymentGateway');
 * 
 * 2. Add credentials to .env.example:
 *    TEMPLATE_API_KEY=your_key_here
 * 
 * 3. Update PaymentGatewayManager config in services/billing/server.php:
 *    'template' => [
 *        'api_key' => $_ENV['TEMPLATE_API_KEY'] ?? null,
 *    ],
 * 
 * 4. Test the implementation:
 *    $manager = new PaymentGatewayManager($config);
 *    $manager->setActiveGateway('template');
 *    $result = $manager->getActive()->createCustomer([...]);
 * 
 * 5. Add to PAYMENT_PROVIDERS_REFERENCE.md documenting:
 *    - Regions supported
 *    - Currencies supported
 *    - Transaction fees
 *    - Payment methods
 *    - Best use cases
 */
