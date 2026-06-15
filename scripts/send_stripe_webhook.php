<?php
// Simple test script to POST a mock Stripe webhook to the gateway
// Usage: php scripts/send_stripe_webhook.php [gateway_url]

$gatewayUrl = $argv[1] ?? 'http://127.0.0.1:8000/api/v1/billing/webhooks/stripe';
$secret = getenv('STRIPE_WEBHOOK_SECRET') ?: 'testsecret';

$event = [
    'id' => 'evt_test_' . bin2hex(random_bytes(6)),
    'type' => 'invoice.payment_succeeded',
    'data' => [
        'object' => [
            'id' => 'in_test_' . bin2hex(random_bytes(6)),
            'amount_paid' => 1000,
            'currency' => 'usd',
            'metadata' => [
                'license_key' => 'TEST-LICENSE-KEY-12345-ABCDE-FGHIJ-KLMNO',
                'site' => 'http://example.com'
            ],
        ],
    ],
];

$payload = json_encode($event);
$signature = 't=' . hash_hmac('sha256', $payload, $secret);

$ch = curl_init($gatewayUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Stripe-Signature: ' . $signature,
]);

$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $code\n";
echo $resp . "\n";
