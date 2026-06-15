<?php
/**
 * Stripe Payment Gateway Implementation
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class StripePaymentGateway implements PaymentGatewayInterface
{
    private $apiKey;
    private $webhookSecret;

    public function authenticate(array $config): bool
    {
        if (!isset($config['api_key'])) {
            throw new Exception('Stripe API key required');
        }
        $this->apiKey = $config['api_key'];
        $this->webhookSecret = $config['webhook_secret'] ?? null;
        return true;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = "https://api.stripe.com/v1$endpoint";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey . ":");
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception($result['error']['message'] ?? 'Stripe API error');
        }
        
        return $result;
    }

    public function createCustomer(array $data): array
    {
        $result = $this->request('POST', '/customers', [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'metadata' => json_encode($data['metadata'] ?? []),
        ]);
        
        return [
            'customer_id' => $result['id'],
            'provider_id' => $result['id'],
            'provider' => 'stripe',
            'created_at' => date('c'),
        ];
    }

    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $data = [
            'customer' => $customerId,
            'items' => json_encode([['price' => $planId]]),
            'metadata' => json_encode($metadata),
        ];
        
        $result = $this->request('POST', '/subscriptions', $data);
        
        return [
            'subscription_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'current_period_end' => $result['current_period_end'],
            'provider' => 'stripe',
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $result = $this->request('GET', "/subscriptions/$subscriptionId");
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'current_period_start' => $result['current_period_start'],
            'current_period_end' => $result['current_period_end'],
            'items' => $result['items']['data'] ?? [],
            'cancel_at_period_end' => $result['cancel_at_period_end'],
        ];
    }

    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        $data = [];
        
        if (isset($updates['plan'])) {
            $data['items'] = json_encode([['id' => $updates['item_id'], 'price' => $updates['plan']]]);
        }
        
        if (isset($updates['metadata'])) {
            $data['metadata'] = json_encode($updates['metadata']);
        }
        
        $result = $this->request('POST', "/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'updated_at' => date('c'),
        ];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        $data = $immediate ? [] : ['cancel_at_period_end' => true];
        
        $result = $this->request('DELETE', "/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'canceled_at' => $result['canceled_at'],
        ];
    }

    public function charge(string $customerId, int $amount, string $currency = 'USD', array $metadata = []): array
    {
        // If a redirect flow is requested (success/cancel URLs), create a Checkout Session
        if (!empty($metadata['success_url']) && !empty($metadata['cancel_url'])) {
            $data = [
                'payment_method_types[]' => 'card',
                'mode' => 'payment',
                'line_items[0][price_data][currency]' => strtolower($currency),
                'line_items[0][price_data][product_data][name]' => $metadata['description'] ?? ($metadata['plan'] ?? 'Purchase'),
                'line_items[0][price_data][unit_amount]' => $amount,
                'success_url' => $metadata['success_url'],
                'cancel_url' => $metadata['cancel_url'],
            ];
            if (!empty($metadata['email'])) $data['customer_email'] = $metadata['email'];
            if (!empty($metadata) && is_array($metadata)) $data['metadata'] = $metadata;

            $result = $this->request('POST', '/checkout/sessions', $data);

            return [
                'charge_id' => $result['id'] ?? null,
                'provider_id' => $result['id'] ?? null,
                'status' => $result['status'] ?? ($result['payment_status'] ?? 'created'),
                'redirect_url' => $result['url'] ?? null,
                'session_id' => $result['id'] ?? null,
                'amount' => $amount,
                'currency' => $currency,
            ];
        }

        // Fallback: create a direct charge (requires token/source on server-side)
        $result = $this->request('POST', '/charges', [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'metadata' => json_encode($metadata),
        ]);

        return [
            'charge_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
        ];
    }

    public function refund(string $chargeId, ?int $amount = null): array
    {
        $data = [];
        if ($amount !== null) {
            $data['amount'] = $amount;
        }
        $data['charge'] = $chargeId;
        
        $result = $this->request('POST', '/refunds', $data);
        
        return [
            'refund_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
        ];
    }

    public function getInvoice(string $invoiceId): array
    {
        $result = $this->request('GET', "/invoices/$invoiceId");
        
        return [
            'invoice_id' => $result['id'],
            'customer_id' => $result['customer'],
            'status' => $result['status'],
            'amount_paid' => $result['amount_paid'],
            'amount_due' => $result['amount_due'],
            'lines' => $result['lines']['data'] ?? [],
        ];
    }

    public function listInvoices(string $customerId, int $limit = 10): array
    {
        $result = $this->request('GET', "/invoices?customer=$customerId&limit=$limit");
        
        return [
            'invoices' => array_map(function ($inv) {
                return [
                    'invoice_id' => $inv['id'],
                    'status' => $inv['status'],
                    'amount_due' => $inv['amount_due'],
                    'created' => $inv['created'],
                ];
            }, $result['data'] ?? []),
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (!$this->webhookSecret) {
            return false;
        }
        
        $computedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        $receivedSignature = str_replace('t=', '', explode(',', $signature)[0] ?? '');
        
        return hash_equals($computedSignature, $receivedSignature);
    }

    public function processWebhookEvent(array $event): array
    {
        $type = $event['type'] ?? null;
        $data = $event['data']['object'] ?? [];
        
        switch ($type) {
            case 'customer.subscription.updated':
                return ['event_type' => 'subscription_updated', 'subscription_id' => $data['id']];
            case 'customer.subscription.deleted':
                return ['event_type' => 'subscription_canceled', 'subscription_id' => $data['id']];
            case 'invoice.payment_succeeded':
                return ['event_type' => 'payment_succeeded', 'invoice_id' => $data['id']];
            case 'invoice.payment_failed':
                return ['event_type' => 'payment_failed', 'invoice_id' => $data['id']];
            default:
                return ['event_type' => 'unknown'];
        }
    }

    public function getProvider(): string
    {
        return 'stripe';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'INR', 'MXN', 'ZAR'];
    }
}
