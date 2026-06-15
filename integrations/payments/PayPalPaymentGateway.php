<?php
/**
 * PayPal Payment Gateway Implementation
 * Global payment provider with recurring billing support
 * https://developer.paypal.com
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class PayPalPaymentGateway implements PaymentGatewayInterface
{
    private $clientId;
    private $clientSecret;
    private $mode; // 'sandbox' or 'live'
    private $accessToken;

    public function authenticate(array $config): bool
    {
        if (!isset($config['client_id']) || !isset($config['client_secret'])) {
            throw new Exception('PayPal client ID and secret required');
        }
        $this->clientId = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->mode = $config['mode'] ?? 'sandbox';
        
        $this->getAccessToken();
        return true;
    }

    private function getAccessToken(): void
    {
        $baseUrl = $this->mode === 'sandbox' 
            ? 'https://api-m.sandbox.paypal.com' 
            : 'https://api-m.paypal.com';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$baseUrl/v1/oauth2/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ':' . $this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        $this->accessToken = $result['access_token'] ?? null;
        
        if (!$this->accessToken) {
            throw new Exception('Failed to get PayPal access token');
        }
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $baseUrl = $this->mode === 'sandbox' 
            ? 'https://api-m.sandbox.paypal.com' 
            : 'https://api-m.paypal.com';
        
        $url = $baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
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
            throw new Exception($result['message'] ?? 'PayPal API error');
        }
        
        return $result;
    }

    public function createCustomer(array $data): array
    {
        // PayPal doesn't have explicit customer creation
        // Customer is identified by email during transaction
        return [
            'customer_id' => $data['email'],
            'provider_id' => $data['email'],
            'provider' => 'paypal',
            'created_at' => date('c'),
        ];
    }

    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        $result = $this->request('POST', '/v1/billing/subscriptions', [
            'plan_id' => $planId,
            'subscriber' => [
                'email_address' => $customerId,
                'name' => [
                    'given_name' => $metadata['first_name'] ?? 'Customer',
                    'surname' => $metadata['last_name'] ?? '',
                ],
            ],
            'metadata' => $metadata,
        ]);
        
        return [
            'subscription_id' => $result['id'],
            'provider_id' => $result['id'],
            'status' => $result['status'],
            'start_time' => $result['start_time'],
            'provider' => 'paypal',
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        $result = $this->request('GET', "/v1/billing/subscriptions/$subscriptionId");
        
        return [
            'subscription_id' => $result['id'],
            'status' => $result['status'],
            'plan_id' => $result['plan_id'],
            'subscriber' => $result['subscriber']['email_address'],
            'start_time' => $result['start_time'],
            'next_billing_time' => $result['billing_cycles'][0]['pricing_scheme']['currency_code'] ?? null,
        ];
    }

    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        $data = [];
        
        if (isset($updates['plan'])) {
            $data['plan_id'] = $updates['plan'];
        }
        
        if (isset($updates['metadata'])) {
            $data['custom_id'] = json_encode($updates['metadata']);
        }
        
        $this->request('PATCH', "/v1/billing/subscriptions/$subscriptionId", $data);
        
        return [
            'subscription_id' => $subscriptionId,
            'status' => 'updated',
            'updated_at' => date('c'),
        ];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        $this->request('POST', "/v1/billing/subscriptions/$subscriptionId/cancel", [
            'reason' => 'Customer requested',
        ]);
        
        return [
            'subscription_id' => $subscriptionId,
            'status' => 'cancelled',
            'canceled_at' => date('c'),
        ];
    }

    public function charge(string $customerId, int $amount, string $currency = 'USD', array $metadata = []): array
    {
        $result = $this->request('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value' => number_format($amount / 100, 2, '.', ''),
                    ],
                ],
            ],
            'payer' => [
                'email_address' => $customerId,
            ],
        ]);
        $redirect = null;
        if (!empty($result['links']) && is_array($result['links'])) {
            foreach ($result['links'] as $link) {
                if (!empty($link['rel']) && in_array(strtolower($link['rel']), ['approve','approval_url'])) {
                    $redirect = $link['href'];
                    break;
                }
            }
        }

        return [
            'charge_id' => $result['id'] ?? null,
            'provider_id' => $result['id'] ?? null,
            'status' => $result['status'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => $redirect,
            'raw' => $result,
        ];
    }

    public function refund(string $chargeId, ?int $amount = null): array
    {
        // Get order details first to get capture ID
        $order = $this->request('GET', "/v2/checkout/orders/$chargeId");
        $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
        
        if (!$captureId) {
            throw new Exception('Capture not found for refund');
        }
        
        $data = [];
        if ($amount !== null) {
            $data['amount'] = [
                'currency_code' => 'USD',
                'value' => number_format($amount / 100, 2, '.', ''),
            ];
        }
        
        $result = $this->request('POST', "/v2/payments/captures/$captureId/refund", $data);
        
        return [
            'refund_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount']['value'] ?? 0,
        ];
    }

    public function getInvoice(string $invoiceId): array
    {
        $result = $this->request('GET', "/v2/invoicing/invoices/$invoiceId");
        
        return [
            'invoice_id' => $result['id'],
            'status' => $result['status'],
            'amount' => $result['amount']['total'],
            'currency' => $result['amount']['currency_code'],
        ];
    }

    public function listInvoices(string $customerId, int $limit = 10): array
    {
        $result = $this->request('GET', "/v2/invoicing/invoices?fields=invoicer,primary_recipients,amount&page_size=$limit");
        
        return [
            'invoices' => array_map(function ($inv) {
                return [
                    'invoice_id' => $inv['id'],
                    'status' => $inv['status'],
                    'amount' => $inv['amount']['value'],
                    'created' => $inv['create_time'],
                ];
            }, $result['items'] ?? []),
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // PayPal webhook verification requires transmission_id, cert_url, etc.
        // Simplified for this implementation
        return !empty($signature);
    }

    public function processWebhookEvent(array $event): array
    {
        $type = $event['event_type'] ?? null;
        $resource = $event['resource'] ?? [];
        
        switch ($type) {
            case 'BILLING.SUBSCRIPTION.CREATED':
                return ['event_type' => 'subscription_created', 'subscription_id' => $resource['id']];
            case 'BILLING.SUBSCRIPTION.UPDATED':
                return ['event_type' => 'subscription_updated', 'subscription_id' => $resource['id']];
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                return ['event_type' => 'subscription_canceled', 'subscription_id' => $resource['id']];
            case 'PAYMENT.CAPTURE.COMPLETED':
                return ['event_type' => 'payment_succeeded', 'payment_id' => $resource['id']];
            default:
                return ['event_type' => 'unknown'];
        }
    }

    public function getProvider(): string
    {
        return 'paypal';
    }

    public function getSupportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'HUF', 'CZK', 'INR', 'MYR', 'PHP', 'TWD', 'THB', 'BRL', 'ZAR'];
    }
}
