<?php
/**
 * FlutterWave Payment Gateway Implementation
 * https://flutterwave.com
 */

require_once __DIR__ . '/PaymentGatewayInterface.php';

class FlutterWavePaymentGateway implements PaymentGatewayInterface
{
    private $publicKey;
    private $secretKey;
    private $baseUrl = 'https://api.flutterwave.com/v3';

    public function authenticate(array $config): bool
    {
        if (!isset($config['public_key']) || !isset($config['secret_key'])) {
            throw new Exception('FlutterWave public and secret keys required');
        }
        $this->publicKey = $config['public_key'];
        $this->secretKey = $config['secret_key'];
        return true;
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
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
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($resp, true) ?: [];
        if ($code >= 400) {
            throw new Exception($result['message'] ?? 'FlutterWave API error');
        }
        return $result;
    }

    public function createCustomer(array $data): array
    {
        // FlutterWave typically identifies customers by phone/email per payment
        return [
            'customer_id' => $data['email'] ?? ($data['phone'] ?? 'unknown'),
            'provider_id' => $data['email'] ?? ($data['phone'] ?? 'unknown'),
            'provider' => 'flutterwave',
            'created_at' => date('c'),
        ];
    }

    public function createSubscription(string $customerId, string $planId, array $metadata = []): array
    {
        // Stub: FlutterWave subscriptions vary by integration
        return [
            'subscription_id' => $planId . '-' . bin2hex(random_bytes(4)),
            'provider_id' => $planId,
            'status' => 'active',
            'provider' => 'flutterwave',
        ];
    }

    public function getSubscription(string $subscriptionId): array
    {
        return ['subscription_id' => $subscriptionId, 'status' => 'active'];
    }

    public function updateSubscription(string $subscriptionId, array $updates): array
    {
        return ['subscription_id' => $subscriptionId, 'status' => 'updated', 'updated_at' => date('c')];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediate = false): array
    {
        return ['subscription_id' => $subscriptionId, 'status' => 'cancelled', 'canceled_at' => date('c')];
    }

    public function charge(string $customerId, int $amount, string $currency = 'NGN', array $metadata = []): array
    {
        $result = $this->request('POST', '/payments', [
            'tx_ref' => 'tx-' . bin2hex(random_bytes(6)),
            'amount' => number_format($amount / 100, 2, '.', ''),
            'currency' => strtoupper($currency),
            'redirect_url' => $metadata['redirect_url'] ?? null,
            'customer' => ['email' => $customerId],
            'customizations' => $metadata['customizations'] ?? [],
        ]);

        return [
            'charge_id' => $result['data']['id'] ?? ($result['data']['tx_ref'] ?? ServiceHelpers::generateUuid()),
            'provider_id' => $result['data']['id'] ?? null,
            'status' => $result['status'] ?? 'unknown',
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ];
    }

    public function refund(string $chargeId, ?int $amount = null): array
    {
        $data = [];
        if ($amount !== null) $data['amount'] = number_format($amount / 100, 2, '.', '');
        $result = $this->request('POST', "/refunds", $data);
        return ['refund_id' => $result['data']['id'] ?? $chargeId, 'status' => $result['status'] ?? 'processed'];
    }

    public function getInvoice(string $invoiceId): array
    {
        return ['invoice_id' => $invoiceId, 'status' => 'unknown'];
    }

    public function listInvoices(string $customerId, int $limit = 10): array
    {
        return ['invoices' => []];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // FlutterWave typically sends 'verif-hash' header equal to secret
        if (empty($this->secretKey) || empty($signature)) return false;
        // Accept both exact match and HMAC style
        if (hash_equals($this->secretKey, $signature)) return true;
        $expected = hash_hmac('sha256', $payload, $this->secretKey);
        return hash_equals($expected, $signature);
    }

    public function processWebhookEvent(array $event): array
    {
        $type = $event['event'] ?? $event['event_type'] ?? null;
        $data = $event['data'] ?? $event['payload'] ?? [];

        // Map common events
        if (!empty($type)) {
            $t = strtolower($type);
            if (strpos($t, 'charge') !== false || strpos($t, 'payment') !== false || ($data['status'] ?? '') === 'successful') {
                return ['event_type' => 'payment_succeeded', 'object' => $data];
            }
            if (strpos($t, 'refund') !== false || ($data['status'] ?? '') === 'failed') {
                return ['event_type' => 'payment_failed', 'object' => $data];
            }
        }

        return ['event_type' => 'unknown', 'object' => $data];
    }

    public function getProvider(): string
    {
        return 'flutterwave';
    }

    public function getSupportedCurrencies(): array
    {
        return ['NGN', 'GHS', 'KES', 'USD', 'EUR'];
    }
}
