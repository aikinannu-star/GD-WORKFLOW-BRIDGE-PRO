<?php
// CLI helper: php stripe_webhook_test.php [url] [secret]
// Example: php stripe_webhook_test.php http://localhost:8000/wp-json/gdwb/v1/stripe-webhook whsec_test_secret

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$url = $argv[1] ?? 'http://localhost:8000/wp-json/gdwb/v1/stripe-webhook';
$secret = $argv[2] ?? getenv('STRIPE_TEST_SECRET') ?: '';
if (empty($secret)) {
    fwrite(STDERR, "Usage: php stripe_webhook_test.php <webhook_url> <signing_secret>\n");
    exit(2);
}

function genId($prefix = '') {
    try { return $prefix . bin2hex(random_bytes(4)); } catch (Throwable $e) { return $prefix . uniqid(); }
}

$event = [
    'id' => genId('evt_'),
    'type' => 'invoice.payment_succeeded',
    'data' => [ 'object' => [ 'id' => genId('in_'), 'subscription' => genId('sub_'), 'customer' => genId('cus_') ] ]
];

$payload = json_encode($event);
$timestamp = time();
$sig = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
$sigHeader = "t={$timestamp},v1={$sig}";

$opts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nStripe-Signature: {$sigHeader}\r\n",
        'content' => $payload,
        'timeout' => 10
    ]
];

$context = stream_context_create($opts);
$resp = @file_get_contents($url, false, $context);
$meta = $http_response_header ?? [];

echo "POST {$url}\n";
echo "Stripe-Signature: {$sigHeader}\n\n";
if ($resp === false) {
    $err = error_get_last();
    echo "Request failed: " . ($err['message'] ?? 'unknown') . "\n";
    if (!empty($meta)) echo implode("\n", $meta) . "\n";
    exit(3);
}

echo "Response:\n" . $resp . "\n";
if (!empty($meta)) echo implode("\n", $meta) . "\n";
exit(0);
