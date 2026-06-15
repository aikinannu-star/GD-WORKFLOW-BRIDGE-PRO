<?php
/**
 * Billing Service
 * Handles subscriptions, payments, invoices and tenant billing metadata.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../../integrations/payments/PaymentGatewayManager.php';
require_once __DIR__ . '/../../integrations/payments/CommonBillingEvent.php';
require_once __DIR__ . '/../lib/LicenseActivator.php';
require_once __DIR__ . '/../lib/EventStore.php';
require_once __DIR__ . '/../lib/Metrics.php';

// Metrics helper (uses Redis when available, otherwise file-based)
$metrics = new Metrics();

define('SERVICE_NAME', 'billing');
define('SERVICE_PORT', 8003);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadSubscriptions(): array
{
    return ServiceHelpers::loadJson('billing', 'subscriptions.json');
}

function saveSubscriptions(array $subscriptions): bool
{
    return ServiceHelpers::saveJson('billing', 'subscriptions.json', $subscriptions);
}

function loadInvoices(): array
{
    return ServiceHelpers::loadJson('billing', 'invoices.json');
}

function saveInvoices(array $invoices): bool
{
    return ServiceHelpers::saveJson('billing', 'invoices.json', $invoices);
}

function loadEvents(): array
{
    $es = new EventStore();
    if ($es->isEnabled()) {
        try {
            $es->ensureSchema();
            return $es->allAsAssocByKey();
        } catch (Throwable $e) {
            return ServiceHelpers::loadJson('billing', 'events.json');
        }
    }
    return ServiceHelpers::loadJson('billing', 'events.json');
}

function saveEvents(array $events): bool
{
    $es = new EventStore();
    if ($es->isEnabled()) {
        try {
            $es->ensureSchema();
            return $es->saveAll($events);
        } catch (Throwable $e) {
            return ServiceHelpers::saveJson('billing', 'events.json', $events);
        }
    }
    return ServiceHelpers::saveJson('billing', 'events.json', $events);
}

function isAdminAuthorized(): bool
{
    $token = ServiceHelpers::getHeader('X-Admin-Token') ?? ServiceHelpers::getHeader('X_ADMIN_TOKEN');
    $envToken = $_ENV['BILLING_ADMIN_TOKEN'] ?? $_ENV['LICENSE_ADMIN_TOKEN'] ?? null;
    if (!empty($envToken) && !empty($token) && trim($token) === trim($envToken)) return true;
    $auth = ServiceHelpers::getHeader('Authorization');
    if (!empty($auth) && preg_match('/Bearer\s+(\S+)/i', $auth, $m) && trim($m[1]) === trim($envToken)) return true;
    return false;
}

// Admin endpoints for billing (list invoices, events and retry)
if ($method === 'GET' && $uri === '/api/v1/admin/invoices') {
    if (!isAdminAuthorized()) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);
    ServiceHelpers::sendJson(200, ['invoices' => loadInvoices()]);
}

if ($method === 'GET' && $uri === '/api/v1/admin/events') {
    if (!isAdminAuthorized()) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);
    ServiceHelpers::sendJson(200, ['events' => loadEvents()]);
}

if ($method === 'POST' && preg_match('#^/api/v1/admin/events/([^/]+)/retry$#', $uri, $m)) {
    if (!isAdminAuthorized()) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);
    $eventKey = rawurldecode($m[1]);
    $events = loadEvents();
    if (empty($events[$eventKey])) ServiceHelpers::sendJson(404, ['error' => 'event_not_found']);
    $ev = $events[$eventKey];
    $licenseKey = $ev['license_key'] ?? ($ev['metadata']['license_key'] ?? null);
    if (empty($licenseKey)) ServiceHelpers::sendJson(400, ['error' => 'no_license_key']);

    $resp = LicenseActivator::activate($licenseKey, $ev['metadata']['site'] ?? null);
    $events[$eventKey]['last_attempt_at'] = gmdate('c');
    $events[$eventKey]['attempts'] = ($events[$eventKey]['attempts'] ?? 0) + 1;
    if (!empty($resp['success']) || !empty($resp['access_token']) || !empty($resp['token'])) {
        $events[$eventKey]['status'] = 'processed';
        $events[$eventKey]['processed_at'] = gmdate('c');
        $events[$eventKey]['result'] = $resp;
    } else {
        $events[$eventKey]['status'] = 'failed';
        $events[$eventKey]['last_error'] = $resp;
    }
    saveEvents($events);
    ServiceHelpers::sendJson(200, ['success' => true, 'event' => $events[$eventKey]]);
}

function gatewayConfig(): array
{
    return [
        'stripe' => [
            'api_key' => $_ENV['STRIPE_API_KEY'] ?? null,
            'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null,
        ],
        'paystack' => [
            'public_key' => $_ENV['PAYSTACK_PUBLIC_KEY'] ?? null,
            'secret_key' => $_ENV['PAYSTACK_SECRET_KEY'] ?? null,
        ],
        'razorpay' => [
            'key_id' => $_ENV['RAZORPAY_KEY_ID'] ?? null,
            'key_secret' => $_ENV['RAZORPAY_KEY_SECRET'] ?? null,
        ],
        'paypal' => [
            'client_id' => $_ENV['PAYPAL_CLIENT_ID'] ?? null,
            'client_secret' => $_ENV['PAYPAL_CLIENT_SECRET'] ?? null,
            'mode' => $_ENV['PAYPAL_MODE'] ?? 'sandbox',
        ],
        'flutterwave' => [
            'public_key' => $_ENV['FLUTTERWAVE_PUBLIC_KEY'] ?? null,
            'secret_key' => $_ENV['FLUTTERWAVE_SECRET_KEY'] ?? null,
        ],
    ];
}

function getPaymentManager(): PaymentGatewayManager
{
    return new PaymentGatewayManager(gatewayConfig());
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
        'providers' => PaymentGatewayManager::getRegisteredProviders(),
    ]);
}

// Serve admin UI at /admin
if ($uri === '/admin' || $uri === '/admin/') {
    $file = __DIR__ . '/admin/index.html';
    if (file_exists($file)) {
        header('Content-Type: text/html');
        echo file_get_contents($file);
        exit;
    }
}

if ($method === 'GET' && $uri === '/api/v1/gateways') {
    $manager = getPaymentManager();
    ServiceHelpers::sendJson(200, [
        'providers' => PaymentGatewayManager::getRegisteredProviders(),
        'capabilities' => $manager->getAllCapabilities(),
    ]);
}

if ($method === 'POST' && $uri === '/api/v1/subscriptions') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = $input['tenant_id'] ?? null;
    $customerId = $input['customer_id'] ?? null;
    $planId = $input['plan_id'] ?? null;
    $currency = $input['currency'] ?? 'USD';
    $provider = $input['gateway'] ?? 'stripe';

    if (!$tenantId || !$customerId || !$planId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id, customer_id and plan_id are required']);
    }

    $subscriptions = loadSubscriptions();
    $subscriptionId = ServiceHelpers::generateUuid();
    $record = [
        'id' => $subscriptionId,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'plan_id' => $planId,
        'currency' => strtoupper($currency),
        'provider' => $provider,
        'status' => 'active',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $subscriptions[] = $record;
    saveSubscriptions($subscriptions);

    ServiceHelpers::sendJson(201, ['subscription' => $record]);
}

if ($method === 'GET' && preg_match('#^/api/v1/subscriptions/([a-f0-9]+)$#', $uri, $matches)) {
    $subscriptionId = $matches[1];
    $subscriptions = loadSubscriptions();
    foreach ($subscriptions as $subscription) {
        if ($subscription['id'] === $subscriptionId) {
            ServiceHelpers::sendJson(200, ['subscription' => $subscription]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'subscription_not_found']);
}

if ($method === 'PUT' && preg_match('#^/api/v1/subscriptions/([a-f0-9]+)$#', $uri, $matches)) {
    $subscriptionId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $subscriptions = loadSubscriptions();
    foreach ($subscriptions as &$subscription) {
        if ($subscription['id'] === $subscriptionId) {
            $subscription['plan_id'] = $input['plan_id'] ?? $subscription['plan_id'];
            $subscription['status'] = $input['status'] ?? $subscription['status'];
            $subscription['updated_at'] = gmdate('c');
            saveSubscriptions($subscriptions);
            ServiceHelpers::sendJson(200, ['subscription' => $subscription]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'subscription_not_found']);
}

if ($method === 'POST' && preg_match('#^/api/v1/subscriptions/([a-f0-9]+)/cancel$#', $uri, $matches)) {
    $subscriptionId = $matches[1];
    $subscriptions = loadSubscriptions();
    foreach ($subscriptions as &$subscription) {
        if ($subscription['id'] === $subscriptionId) {
            $subscription['status'] = 'cancelled';
            $subscription['cancelled_at'] = gmdate('c');
            $subscription['updated_at'] = gmdate('c');
            saveSubscriptions($subscriptions);
            ServiceHelpers::sendJson(200, ['subscription' => $subscription]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'subscription_not_found']);
}

if ($method === 'GET' && $uri === '/api/v1/invoices') {
    $customerId = $_GET['customer_id'] ?? null;
    if (!$customerId) {
        ServiceHelpers::sendJson(400, ['error' => 'customer_id required']);
    }
    $invoices = loadInvoices();
    $filtered = array_values(array_filter($invoices, fn($invoice) => $invoice['customer_id'] === $customerId));
    ServiceHelpers::sendJson(200, ['invoices' => $filtered]);
}

if ($method === 'POST' && $uri === '/api/v1/invoices') {
    $input = ServiceHelpers::getRequestBody();
    $customerId = $input['customer_id'] ?? null;
    $amount = $input['amount'] ?? null;
    $currency = strtoupper($input['currency'] ?? 'USD');
    $tenantId = $input['tenant_id'] ?? null;
    if (!$customerId || !$amount || !$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id, customer_id and amount are required']);
    }
    $invoices = loadInvoices();
    $invoiceId = ServiceHelpers::generateUuid();
    $record = [
        'id' => $invoiceId,
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'amount' => $amount,
        'currency' => $currency,
        'status' => 'pending',
        'issued_at' => gmdate('c'),
    ];
    $invoices[] = $record;
    saveInvoices($invoices);
    ServiceHelpers::sendJson(201, ['invoice' => $record]);
}

if ($method === 'POST' && $uri === '/api/v1/charges') {
    $input = ServiceHelpers::getRequestBody();
    $customerId = $input['customer_id'] ?? null;
    $amount = $input['amount'] ?? null;
    $currency = strtoupper($input['currency'] ?? 'USD');
    if (!$customerId || !$amount) {
        ServiceHelpers::sendJson(400, ['error' => 'customer_id and amount are required']);
    }
    $chargeId = ServiceHelpers::generateUuid();
    ServiceHelpers::sendJson(201, [
        'charge' => [
            'id' => $chargeId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'captured',
            'created_at' => gmdate('c'),
        ],
    ]);
}

// Purchase endpoint: initiates a payment for a plan and provisions a license_key
if ($method === 'POST' && $uri === '/api/v1/purchase') {
    $input = ServiceHelpers::getRequestBody();
    $plan = $input['plan'] ?? null;
    $currency = strtoupper($input['currency'] ?? 'USD');
    $provider = $input['gateway'] ?? null;
    $email = $input['email'] ?? ($input['customer_id'] ?? null);
    $successUrl = $input['success_url'] ?? null;
    $cancelUrl = $input['cancel_url'] ?? null;
    $site = $input['site'] ?? null;

    // Price mapping (minor units / cents)
    $priceMap = [ 'pro' => 2999, 'enterprise' => 19999 ];
    $amount = isset($input['amount_cents']) ? intval($input['amount_cents']) : (isset($input['price_cents']) ? intval($input['price_cents']) : null);
    if ($amount === null && $plan) $amount = $priceMap[strtolower($plan)] ?? null;

    if (empty($amount) || $amount <= 0) {
        ServiceHelpers::sendJson(400, ['error' => 'amount_required', 'message' => 'amount_cents or plan required']);
    }

    // Generate license key (same format used by WooCommerce provisioner)
    try {
        $rand = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $rand = bin2hex(openssl_random_pseudo_bytes(8));
    }
    $licenseKey = 'GDWB-' . strtoupper($rand);

    $metadata = $input['metadata'] ?? [];
    $metadata['license_key'] = $licenseKey;
    if ($site) $metadata['site'] = $site;
    if ($plan) $metadata['plan'] = $plan;
    if (!empty($input['tenant_id'])) $metadata['tenant_id'] = $input['tenant_id'];

    $manager = getPaymentManager();

    // Support recording payments created externally (WooCommerce) or simulate flag
    $simulatePurchase = !empty($input['simulate']) || !empty($input['record_only']) || (is_string($provider) && strtolower($provider) === 'woocommerce');

    if ($simulatePurchase) {
        // Treat as already-paid record (do not call provider SDK)
        $chargeResp = [
            'gateway_used' => $provider ?? 'woocommerce',
            'status' => 'paid',
            'charge_id' => $input['order_id'] ?? ($metadata['order_id'] ?? ServiceHelpers::generateUuid()),
            'amount' => $amount,
            'currency' => $currency,
        ];
    } else {
        try {
            $customerId = $email ?? ($input['customer_id'] ?? 'anonymous');
            $chargeResp = $manager->charge($customerId, intval($amount), $currency, array_merge($metadata, ['success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'email' => $email, 'description' => $input['description'] ?? $plan]), $provider);
        } catch (Throwable $e) {
            ServiceHelpers::sendJson(500, ['error' => 'charge_failed', 'message' => $e->getMessage()]);
        }
    }

    // Persist invoice record (pending or paid depending on provider response)
    $invoices = loadInvoices();
    $invoiceId = $chargeResp['charge_id'] ?? ($chargeResp['id'] ?? ServiceHelpers::generateUuid());
    $invoices[] = [
        'id' => $invoiceId,
        'provider' => $chargeResp['gateway_used'] ?? $provider,
        'provider_invoice_id' => $chargeResp['charge_id'] ?? ($chargeResp['id'] ?? null),
        'status' => (!empty($chargeResp['status']) && in_array($chargeResp['status'], ['captured','succeeded','paid'])) ? 'paid' : 'pending',
        'amount' => $amount,
        'currency' => $currency,
        'metadata' => $metadata,
        'raw' => $chargeResp,
        'created_at' => gmdate('c'),
    ];
    saveInvoices($invoices);

    // If gateway returned a redirect/approval url, return it for client-side redirect
    $redirect = $chargeResp['redirect_url'] ?? $chargeResp['authorization_url'] ?? $chargeResp['approval_url'] ?? null;
    if ($redirect) {
        ServiceHelpers::sendJson(200, [
            'success' => true,
            'license_key' => $licenseKey,
            'provider' => $chargeResp['gateway_used'] ?? $provider,
            'redirect_url' => $redirect,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    // Otherwise, attempt immediate activation (synchronous capture)
    try {
        $resp = LicenseActivator::activate($licenseKey, $site);
    } catch (Throwable $e) {
        ServiceHelpers::sendJson(500, ['error' => 'activation_failed', 'message' => $e->getMessage()]);
    }

    ServiceHelpers::sendJson(200, [
        'success' => true,
        'license_key' => $licenseKey,
        'license_response' => $resp,
        'provider' => $chargeResp['gateway_used'] ?? $provider,
        'invoice_id' => $invoiceId,
        'amount' => $amount,
        'currency' => $currency,
    ]);
}

// Webhook endpoint for payment providers (proxied via gateway)
// Accepts: POST /api/v1/billing/webhooks/{provider}  OR  POST /webhooks/{provider}
if ($method === 'POST' && (preg_match('#^/api/v1/billing/webhooks/([^/]+)$#', $uri, $m) || preg_match('#^/webhooks/([^/]+)$#', $uri, $m))) {
    $provider = $m[1];
    $payload = file_get_contents('php://input');

    // Collect common signature header candidates
    $sigHeader = ServiceHelpers::getHeader('Stripe-Signature') ?? ServiceHelpers::getHeader('Stripe_Signature') ?? ServiceHelpers::getHeader('X-Signature') ?? ServiceHelpers::getHeader('Signature') ?? ServiceHelpers::getHeader('verif-hash') ?? ServiceHelpers::getHeader('x-flutterwave-signature') ?? null;

    $manager = getPaymentManager();
    try {
        $gateway = PaymentGatewayManager::getGateway($provider);
    } catch (Exception $e) {
        ServiceHelpers::sendJson(400, ['error' => 'unknown_provider', 'message' => $e->getMessage()]);
    }

    // Verify signature when gateway supports it
    if (method_exists($gateway, 'verifyWebhookSignature')) {
        $ok = false;
        try {
            $ok = $gateway->verifyWebhookSignature($payload, $sigHeader);
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            try { $metrics->incr('billing_webhook_invalid_signature_total'); } catch (Throwable $_) {}
            ServiceHelpers::sendJson(400, ['error' => 'invalid_signature']);
        }
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_payload']);
    }

    try { $metrics->incr('billing_webhook_received_total'); } catch (Throwable $_) {}

    $processed = method_exists($gateway, 'processWebhookEvent') ? $gateway->processWebhookEvent($event) : ['event_type' => $event['type'] ?? 'unknown', 'object' => $event];
    $common = CommonBillingEvent::normalize($processed, $provider);

    // Idempotency / persistence: use event_key from normalizer
    $eventKey = $common['event_key'] ?? ($provider . ':' . ($common['event_id'] ?? ($common['reference'] ?? uniqid('evt_', true))));
    $events = loadEvents();

    // If already processed, acknowledge without re-processing
    if (!empty($events[$eventKey]) && ($events[$eventKey]['status'] ?? '') === 'processed') {
        ServiceHelpers::sendJson(200, ['success' => true, 'duplicate' => true, 'event' => $events[$eventKey]]);
    }

    // If currently processing, return 202 Accepted to avoid concurrent work
    if (!empty($events[$eventKey]) && ($events[$eventKey]['status'] ?? '') === 'processing') {
        ServiceHelpers::sendJson(202, ['success' => true, 'message' => 'event_already_processing']);
    }

    // Create processing record
    $events[$eventKey] = [
        'provider' => $provider,
        'event_id' => $common['event_id'] ?? null,
        'reference' => $common['reference'] ?? null,
        'license_key' => $common['license_key'] ?? null,
        'metadata' => $common['metadata'] ?? [],
        'status' => 'processing',
        'attempts' => 0,
        'created_at' => gmdate('c'),
        'raw' => $common['raw'] ?? $event,
    ];
    saveEvents($events);

    // Process event
    try {
        if (!empty($common['event']) && $common['event'] === 'payment_succeeded') {
            $licenseKey = $common['license_key'] ?? null;
            if (empty($licenseKey)) {
                try {
                    $licenseKey = 'TEST-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time();
                } catch (Throwable $e) {
                    $licenseKey = 'TEST-' . strtoupper(bin2hex(openssl_random_pseudo_bytes(8))) . '-' . time();
                }
            }

            $site = $common['metadata']['site'] ?? null;
            $resp = LicenseActivator::activate($licenseKey, $site);

            // Update event record
            $events[$eventKey]['attempts'] = ($events[$eventKey]['attempts'] ?? 0) + 1;
            $events[$eventKey]['last_attempt_at'] = gmdate('c');
            if (!empty($resp['success']) || !empty($resp['access_token']) || !empty($resp['token'])) {
                $events[$eventKey]['status'] = 'processed';
                $events[$eventKey]['processed_at'] = gmdate('c');
                $events[$eventKey]['result'] = $resp;
                try { $metrics->incr('billing_activation_success_total'); } catch (Throwable $_) {}
            } else {
                $events[$eventKey]['status'] = 'failed';
                $events[$eventKey]['last_error'] = $resp;
                $events[$eventKey]['next_retry_at'] = gmdate('c', time() + min(3600, 60 * pow(2, $events[$eventKey]['attempts'])));
                try { $metrics->incr('billing_activation_failure_total'); } catch (Throwable $_) {}
            }
            saveEvents($events);

            // Record invoice/charge as paid
            $invoices = loadInvoices();
            $newInvoice = [
                'id' => $common['reference'] ?? ServiceHelpers::generateUuid(),
                'provider' => $provider,
                'provider_invoice_id' => $common['reference'] ?? null,
                'status' => (!empty($events[$eventKey]['status']) && $events[$eventKey]['status'] === 'processed') ? 'paid' : 'pending',
                'amount' => $common['amount'] ?? null,
                'currency' => $common['currency'] ?? null,
                'metadata' => $common['metadata'] ?? [],
                'raw' => $common['raw'] ?? $event,
                'created_at' => gmdate('c'),
            ];
            $invoices[] = $newInvoice;
            saveInvoices($invoices);

            ServiceHelpers::sendJson(200, ['success' => true, 'license_key' => $licenseKey, 'license_response' => $resp, 'event' => $events[$eventKey]]);
        }

        if (!empty($common['event']) && $common['event'] === 'payment_failed') {
            $events[$eventKey]['status'] = 'failed';
            $events[$eventKey]['last_attempt_at'] = gmdate('c');
            $events[$eventKey]['attempts'] = ($events[$eventKey]['attempts'] ?? 0) + 1;
            saveEvents($events);

            $invoices = loadInvoices();
            $newInvoice = [
                'id' => $common['reference'] ?? ServiceHelpers::generateUuid(),
                'provider' => $provider,
                'provider_invoice_id' => $common['reference'] ?? null,
                'status' => 'failed',
                'amount' => $common['amount'] ?? null,
                'currency' => $common['currency'] ?? null,
                'metadata' => $common['metadata'] ?? [],
                'raw' => $common['raw'] ?? $event,
                'created_at' => gmdate('c'),
            ];
            $invoices[] = $newInvoice;
            saveInvoices($invoices);
            ServiceHelpers::sendJson(200, ['success' => true, 'message' => 'recorded_failed_payment', 'event' => $events[$eventKey]]);
        }

        ServiceHelpers::sendJson(200, ['received' => true, 'event' => $events[$eventKey]]);
    } catch (Throwable $e) {
        $events[$eventKey]['status'] = 'failed';
        $events[$eventKey]['last_error'] = $e->getMessage();
        $events[$eventKey]['attempts'] = ($events[$eventKey]['attempts'] ?? 0) + 1;
        $events[$eventKey]['next_retry_at'] = gmdate('c', time() + min(3600, 60 * pow(2, $events[$eventKey]['attempts'])));
        saveEvents($events);
        try { $metrics->incr('billing_activation_failure_total'); } catch (Throwable $_) {}
        ServiceHelpers::sendJson(500, ['error' => 'processing_failed', 'message' => $e->getMessage(), 'event' => $events[$eventKey]]);
    }
}
// Expose metrics in Prometheus text format
if ($method === 'GET' && ($uri === '/metrics' || $uri === '/metrics/')) {
    header('Content-Type: text/plain; version=0.0.4');
    echo $metrics->renderPrometheus();
    exit;
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
