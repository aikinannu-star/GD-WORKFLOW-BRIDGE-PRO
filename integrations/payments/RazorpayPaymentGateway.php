<?php
/**
 * Razorpay Payment Gateway Implementation
 * Popular in India and South Asia
 * https://razorpay.com
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class RazorpayPaymentGateway implements PaymentGatewayInterface
{
    private $keyId;
    private $keySecret;
    private $baseUrl = 'https://api.razorpay.com/v1';

    public function authenticate(array $config): bool
    {
        if (!isset($config['key_id']) || !isset($config['key_secret'])) {
            throw new Exception('Razorpay key ID and secret required');
        }
        $this->keyId = $config['key_id'];
        $this->keySecret = $config['key_secret'];
        return true;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->keyId . ':' . $this->keySecret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
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
            throw new Exception($result['error']['description'] ?? 'Razorpay API error');
        }
        
        return $result;
    }

    public function createCustomer(array $data): array
    {
        $result = $this->request('POST', '/customers', [
            'email' => $data['email'],
            'contact' => $data['phone'] ?? null,
            'name' => $data['name'] ?? null,
            'notes' => $data['metadata'] ?? [],
        ]);
        
        return [
            'customer_id' => $result['id'],
            'provider_id' => $result['id'],
            'provider' => 'razorpay',
            'created_at' => date('c'),
        ];
    }

    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $result = $this->request('POST', '/subscriptions', [
            'plan_id' => $planId,
            'customer_notify' => 1,
            'notes' => $metadata,
        ]);
        
        return [
            'subscription_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'start_at' => $result['start_at'],
            'provider' => 'razorpay',
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $result = $this->request('GET', "/subscriptions/$subscriptionId");
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'plan_id' => $result['plan_id'],
            'customer_id' => $result['customer_id'],
            'current_start' => $result['current_start'],
            'current_end' => $result['current_end'],
        ];
    }

    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        $data = [];
        
        if (isset($updates['plan'])) {
            $data['plan_id'] = $updates['plan'];
        }
        
        if (isset($updates['metadata'])) {
            $data['notes'] = $updates['metadata'];
        }
        
        $result = $this->request('PUT', "/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'updated_at' => date('c'),
        ];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        $result = $this->request('POST', "/subscriptions/$subscriptionId/cancel", [
            'cancel_at_cycle_end' => $immediate ? 0 : 1,
        ]);
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'ended_at' => $result['ended_at'],
        ];
    }

    public function charge(string $customerId, int $amount, string $currency = 'INR', array $metadata = []): array
    {
        // If a client-side checkout is desired, create an order and return order_id
        if (!empty($metadata['redirect_url']) || !empty($metadata['receipt'])) {
            $data = [
                'amount' => $amount * 100, // Convert to paise
                'currency' => strtoupper($currency),
                'receipt' => $metadata['receipt'] ?? bin2hex(random_bytes(6)),
                'notes' => $metadata,
            ];

            $result = $this->request('POST', '/orders', $data);

            return [
                'order_id' => $result['id'] ?? null,
                'provider_id' => $result['id'] ?? null,
                'status' => $result['status'] ?? 'created',
                'amount' => $amount,
                'currency' => $currency,
                'raw' => $result,
            ];
        }

        // Fallback: create a payment directly (legacy)
        $result = $this->request('POST', '/payments', [
            'amount' => $amount * 100, // Convert to paise
            'currency' => strtoupper($currency),
            'customer_id' => $customerId,
            'notes' => $metadata,
        ]);

        return [
            'charge_id' => $result['id'] ?? null,
            'provider_id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
            'amount' => $result['amount'] ?? $amount,
            'currency' => $result['currency'] ?? $currency,
        ];
    }

    public function refund(string $chargeId, ?int $amount = null): array
    {
        $data = [];
        if ($amount !== null) {
            $data['amount'] = $amount * 100; // Convert to paise
        }
        
        $result = $this->request('POST', "/payments/$chargeId/refund", $data);
        
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
            'subscription_id' => $result['subscription_id'],
            'status' => $result['status'],
            'amount_due' => $result['amount_due'],
            'amount_paid' => $result['amount_paid'],
        ];
    }

    public function listInvoices(string $customerId, int $limit = 10): array
    {
        $result = $this->request('GET', "/invoices?customer_id=$customerId&count=$limit");
        
        return [
            'invoices' => array_map(function ($inv) {
                return [
                    'invoice_id' => $inv['id'],
                    'status' => $inv['status'],
                    'amount_due' => $inv['amount_due'],
                    'created_at' => $inv['created_at'],
                ];
            }, $result['items'] ?? []),
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    public function processWebhookEvent(array $event): array
    {
        $type = $event['event'] ?? null;
        $data = $event['payload']['subscription']['entity'] ?? [];
        
        switch ($type) {
            case 'subscription.created':
                return ['event_type' => 'subscription_created', 'subscription_id' => $data['id']];
            case 'subscription.updated':
                return ['event_type' => 'subscription_updated', 'subscription_id' => $data['id']];
            case 'subscription.cancelled':
                return ['event_type' => 'subscription_canceled', 'subscription_id' => $data['id']];
            case 'payment.authorized':
                return ['event_type' => 'payment_authorized', 'payment_id' => $data['id']];
            default:
                return ['event_type' => 'unknown'];
        }
    }

    public function getProvider(): string
    {
        return 'razorpay';
    }

    public function getSupportedCurrencies(): array
    {
        return ['INR', 'USD', 'AED', 'GBP', 'EUR', 'AUD'];
    }
}
