<?php
/**
 * Paystack Payment Gateway Implementation
 * Popular in Nigeria and across Africa
 * https://paystack.com
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class PaystackPaymentGateway implements PaymentGatewayInterface
{
    private $apiKey;
    private $secretKey;
    private $baseUrl = 'https://api.paystack.co';

    public function authenticate(array $config): bool
    {
        if (!isset($config['public_key']) || !isset($config['secret_key'])) {
            throw new Exception('Paystack public and secret keys required');
        }
        $this->apiKey = $config['public_key'];
        $this->secretKey = $config['secret_key'];
        return true;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->secretKey,
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
        
        if (!($result['status'] ?? false)) {
            throw new Exception($result['message'] ?? 'Paystack API error');
        }
        
        return $result['data'] ?? $result;
    }

    public function createCustomer(array $data): array
    {
        $result = $this->request('POST', '/customer', [
            'email' => $data['email'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ]);
        
        return [
            'customer_id' => $result['customer_code'],
            'provider_id' => $result['id'],
            'provider' => 'paystack',
            'created_at' => date('c'),
        ];
    }

    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $result = $this->request('POST', '/subscription', [
            'customer' => $customerId,
            'plan' => $planId,
            'authorization' => $metadata['authorization_code'] ?? null,
            'metadata' => $metadata,
        ]);
        
        return [
            'subscription_id' => $result['subscription_code'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'next_payment_date' => $result['next_payment_date'],
            'provider' => 'paystack',
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $result = $this->request('GET', "/subscription/$subscriptionId");
        
        return [
            'subscription_id' => $result['subscription_code'],
            'status' => $result['status'],
            'customer_id' => $result['customer']['customer_code'],
            'plan_id' => $result['plan']['plan_code'],
            'next_payment_date' => $result['next_payment_date'],
            'invoices_count' => count($result['invoices'] ?? []),
        ];
    }

    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        $data = [];
        
        if (isset($updates['plan'])) {
            $data['plan'] = $updates['plan'];
        }
        
        $result = $this->request('POST', "/subscription/$subscriptionId", $data);
        
        return [
            'subscription_id' => $result['subscription_code'],
            'status' => $result['status'],
            'updated_at' => date('c'),
        ];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        $result = $this->request('POST', "/subscription/$subscriptionId/disable", []);
        
        return [
            'subscription_id' => $result['subscription_code'],
            'status' => $result['status'],
            'canceled_at' => date('c'),
        ];
    }

    public function charge(string $customerId, int $amount, string $currency = 'NGN', array $metadata = []): array
    {
        // If a redirect/checkout is requested, initialize a transaction and return the authorization URL
        if (!empty($metadata['redirect_url']) || !empty($metadata['callback_url'])) {
            $data = [
                'email' => $customerId,
                'amount' => $amount * 100, // Convert to kobo (assumes $amount is in NGN major units)
                'callback_url' => $metadata['redirect_url'] ?? $metadata['callback_url'] ?? null,
                'metadata' => $metadata,
            ];

            $result = $this->request('POST', '/transaction/initialize', $data);

            return [
                'charge_id' => $result['reference'] ?? ($result['data']['reference'] ?? null),
                'authorization_url' => $result['authorization_url'] ?? ($result['data']['authorization_url'] ?? null),
                'status' => $result['status'] ?? 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'raw' => $result,
            ];
        }

        // Fallback: direct charge (legacy)
        $result = $this->request('POST', '/charge', [
            'email' => $customerId,
            'amount' => $amount * 100, // Convert to kobo
            'metadata' => $metadata,
            'currency' => strtoupper($currency),
        ]);

        return [
            'charge_id' => $result['reference'] ?? null,
            'provider_id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
            'amount' => $result['amount'] ?? $amount,
            'currency' => $currency,
        ];
    }

    public function refund(string $chargeId, ?int $amount = null): array
    {
        $data = ['transaction' => $chargeId];
        if ($amount !== null) {
            $data['amount'] = $amount * 100; // Convert to kobo
        }
        
        $result = $this->request('POST', '/refund', $data);
        
        return [
            'refund_id' => $result['reference'],
            'status' => 'processed',
            'amount' => $result['amount'] ?? 0,
        ];
    }

    public function getInvoice(string $invoiceId): array
    {
        $result = $this->request('GET', "/invoice/$invoiceId");
        
        return [
            'invoice_id' => $result['invoice_code'],
            'subscription_id' => $result['subscription']['subscription_code'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'paid_at' => $result['paid_at'],
        ];
    }

    public function listInvoices(string $customerId, int $limit = 10): array
    {
        $result = $this->request('GET', "/customer/$customerId/invoices?limit=$limit");
        
        return [
            'invoices' => array_map(function ($inv) {
                return [
                    'invoice_id' => $inv['invoice_code'],
                    'status' => $inv['status'],
                    'amount' => $inv['amount'],
                    'created' => $inv['created_at'],
                ];
            }, $result['invoices'] ?? []),
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $hash = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($hash, $signature);
    }

    public function processWebhookEvent(array $event): array
    {
        $type = $event['event'] ?? null;
        $data = $event['data'] ?? [];
        
        switch ($type) {
            case 'subscription.create':
                return ['event_type' => 'subscription_created', 'subscription_id' => $data['subscription_code']];
            case 'subscription.disable':
                return ['event_type' => 'subscription_canceled', 'subscription_id' => $data['subscription_code']];
            case 'charge.success':
                return ['event_type' => 'payment_succeeded', 'reference' => $data['reference']];
            case 'charge.failed':
                return ['event_type' => 'payment_failed', 'reference' => $data['reference']];
            default:
                return ['event_type' => 'unknown'];
        }
    }

    public function getProvider(): string
    {
        return 'paystack';
    }

    public function getSupportedCurrencies(): array
    {
        return ['NGN', 'USD', 'GHS', 'ZAR', 'UGX', 'KES'];
    }
}
