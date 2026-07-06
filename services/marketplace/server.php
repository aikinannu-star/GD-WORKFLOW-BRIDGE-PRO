<?php
require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'marketplace');
define('SERVICE_PORT', 8006);

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
    exit;
}

function getProducts(): array
{
    $products = ServiceHelpers::loadJson('marketplace', 'products.json');
    if (!empty($products)) {
        return $products;
    }

    return [
        [
            'id' => '1',
            'title' => 'Godemar Streetwear Hoodie',
            'description' => 'A premium hoodie for designers and entrepreneurs.',
            'price' => 45,
            'category' => 'Clothing',
            'brand' => 'Godemar',
            'image' => 'https://placehold.co/400x400?text=Hoodie',
            'inventory' => 120,
        ],
        [
            'id' => '2',
            'title' => 'Afrobeats Vinyl Record',
            'description' => 'Limited edition vinyl for music lovers.',
            'price' => 30,
            'category' => 'Music',
            'brand' => 'VinylCo',
            'image' => 'https://placehold.co/400x400?text=Vinyl',
            'inventory' => 52,
        ],
        [
            'id' => '3',
            'title' => 'Designer Cap',
            'description' => 'A stylish cap for urban entrepreneurs.',
            'price' => 25,
            'category' => 'Accessories',
            'brand' => 'SnapStyle',
            'image' => 'https://placehold.co/400x400?text=Cap',
            'inventory' => 68,
        ],
        [
            'id' => '4',
            'title' => 'Graphic Tee',
            'description' => 'Comfortable graphic tee for everyday wear.',
            'price' => 20,
            'category' => 'Clothing',
            'brand' => 'Godemar',
            'image' => 'https://placehold.co/400x400?text=Tee',
            'inventory' => 88,
        ],
        [
            'id' => '5',
            'title' => 'Limited Sneakers',
            'description' => 'Premium sneakers for street style.',
            'price' => 120,
            'category' => 'Footwear',
            'brand' => 'StepUp',
            'image' => 'https://placehold.co/400x400?text=Sneakers',
            'inventory' => 30,
        ],
    ];
}

function filterProducts(array $products): array
{
    $category = $_GET['category'] ?? null;
    $brand = $_GET['brand'] ?? null;
    $minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : null;
    $maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : null;
    $sortBy = $_GET['sort_by'] ?? null;

    $filtered = array_filter($products, function ($product) use ($category, $brand, $minPrice, $maxPrice) {
        if ($category && strcasecmp($category, 'all') !== 0 && strcasecmp($product['category'] ?? '', $category) !== 0) {
            return false;
        }
        if ($brand && strcasecmp($brand, 'all') !== 0 && strcasecmp($product['brand'] ?? '', $brand) !== 0) {
            return false;
        }
        if ($minPrice !== null && ($product['price'] ?? 0) < $minPrice) {
            return false;
        }
        if ($maxPrice !== null && ($product['price'] ?? 0) > $maxPrice) {
            return false;
        }
        return true;
    });

    if ($sortBy === 'price_asc') {
        usort($filtered, fn($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
    } elseif ($sortBy === 'price_desc') {
        usort($filtered, fn($a, $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));
    }

    return array_values($filtered);
}

function getProductById(string $id): ?array
{
    foreach (getProducts() as $product) {
        if ((string)($product['id'] ?? '') === $id) {
            return $product;
        }
    }
    return null;
}

function getRequestBody(): array
{
    return ServiceHelpers::getRequestBody();
}

function sortKeysRecursively(&$data)
{
    if (is_array($data)) {
        // associative array: sort keys
        $keys = array_keys($data);
        $isAssoc = $keys !== array_keys(array_values($data));
        if ($isAssoc) ksort($data);
        foreach ($data as &$v) {
            sortKeysRecursively($v);
        }
    }
}

function canonicalJson($data)
{
    sortKeysRecursively($data);
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_PRESERVE_ZERO_FRACTION') ? JSON_PRESERVE_ZERO_FRACTION : 0));
}

function validateManifest($manifest)
{
    $errors = [];
    if (!is_array($manifest)) {
        return ['manifest_must_be_object'];
    }
    $required = ['name', 'version', 'entrypoint'];
    foreach ($required as $r) {
        if (!isset($manifest[$r]) || !is_string($manifest[$r]) || trim($manifest[$r]) === '') {
            $errors[] = 'missing_' . $r;
        }
    }
    if (isset($manifest['permissions']) && !is_array($manifest['permissions'])) {
        $errors[] = 'permissions_must_be_array';
    }
    if (isset($manifest['assets']) && !is_array($manifest['assets'])) {
        $errors[] = 'assets_must_be_array';
    }
    if (isset($manifest['assets']) && is_array($manifest['assets'])) {
        foreach ($manifest['assets'] as $i => $asset) {
            if (!is_array($asset) || empty($asset['url'])) {
                $errors[] = "assets[{$i}]_missing_url";
            }
        }
    }
    if (isset($manifest['dependencies'])) {
        if (!is_array($manifest['dependencies'])) {
            $errors[] = 'dependencies_must_be_array';
        } else {
            foreach ($manifest['dependencies'] as $i => $dep) {
                if (!is_array($dep) || empty($dep['plugin_id']) || empty($dep['version'])) {
                    $errors[] = "dependencies[{$i}]_invalid_format";
                }
            }
        }
    }
    return $errors;
}

function verifyManifestSignature(array $manifest, string $signatureB64, ?string $publicPem = null): bool
{
    if (!function_exists('openssl_verify')) {
        return false;
    }
    $canonical = canonicalJson($manifest);
    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;
    if ($publicPem === null || trim($publicPem) === '') {
        $defaultKey = __DIR__ . '/../../keys/public.pem';
        if (file_exists($defaultKey)) {
            $publicPem = file_get_contents($defaultKey);
        }
    }
    if (!$publicPem) return false;
    $res = openssl_pkey_get_public($publicPem);
    if ($res === false) return false;
    $ok = openssl_verify($canonical, $sig, $res, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

function verifyArtifactSignature(string $rawData, string $signatureB64, ?string $publicPem = null): bool
{
    if (!function_exists('openssl_verify')) {
        return false;
    }
    $sig = base64_decode($signatureB64, true);
    if ($sig === false) return false;
    if ($publicPem === null || trim($publicPem) === '') {
        $defaultKey = __DIR__ . '/../../keys/public.pem';
        if (file_exists($defaultKey)) {
            $publicPem = file_get_contents($defaultKey);
        }
    }
    if (!$publicPem) return false;
    $res = openssl_pkey_get_public($publicPem);
    if ($res === false) return false;
    $ok = openssl_verify($rawData, $sig, $res, OPENSSL_ALGO_SHA256);
    return $ok === 1;
}

function ensureDir(string $path)
{
    if (!file_exists($path)) mkdir($path, 0777, true);
}

$path = preg_replace('#^/api/v1/marketplace#', '', $uri);

if ($method === 'GET' && ($path === '' || $path === '/' || $path === '/products' || $path === '/products/')) {
    $products = filterProducts(getProducts());
    ServiceHelpers::sendJson(200, ['items' => $products, 'total' => count($products)]);
}

if ($method === 'GET' && preg_match('#^/products/([^/]+)$#', $path, $matches)) {
    $product = getProductById($matches[1]);
    if (!$product) {
        ServiceHelpers::sendJson(404, ['error' => 'product_not_found']);
    }
    ServiceHelpers::sendJson(200, $product);
}

if ($method === 'POST' && ($path === '/products' || $path === '/products/')) {
    $payload = getRequestBody();
    $title = trim($payload['title'] ?? '');
    $price = isset($payload['price']) ? floatval($payload['price']) : null;

    if ($title === '' || $price === null || $price <= 0) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_product', 'message' => 'Product title and valid price are required.']);
    }

    $products = ServiceHelpers::loadJson('marketplace', 'products.json');

    $product = [
        'id' => ServiceHelpers::generateUuid(),
        'title' => $title,
        'description' => trim($payload['description'] ?? ''),
        'price' => $price,
        'category' => trim($payload['category'] ?? 'Other'),
        'brand' => trim($payload['brand'] ?? 'Independent Seller'),
        'image' => trim($payload['image'] ?? 'https://placehold.co/400x400?text=Product'),
        'inventory' => isset($payload['inventory']) ? max(1, intval($payload['inventory'])) : 10,
        'tenant_id' => trim($payload['tenant_id'] ?? ''),
        'created_at' => gmdate('c'),
    ];

    $products[] = $product;
    ServiceHelpers::saveJson('marketplace', 'products.json', $products);
    ServiceHelpers::sendJson(201, $product);
}

if ($method === 'POST' && preg_match('#^/products/([^/]+)/purchase$#', $path, $matches)) {
    $product = getProductById($matches[1]);
    if (!$product) {
        ServiceHelpers::sendJson(404, ['error' => 'product_not_found']);
    }

    $payload = getRequestBody();
    $quantity = isset($payload['quantity']) ? intval($payload['quantity']) : 1;
    if ($quantity < 1) {
        $quantity = 1;
    }

    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'product' => $product,
        'quantity' => $quantity,
        'message' => 'Purchase registered (prototype).',
    ]);
}

// --- Plugins (Module Registration) ---
function getPlugins(): array
{
    return ServiceHelpers::loadJson('marketplace', 'plugins.json');
}

function getPluginById(string $id): ?array
{
    foreach (getPlugins() as $p) {
        if ((string)($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

function getPluginKeys(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    return array_values(array_filter($all, fn($k) => (($k['plugin_id'] ?? '') === $pluginId)));
}

function getPluginKeyById(string $pluginId, string $keyId): ?array
{
    foreach (getPluginKeys($pluginId) as $k) {
        if ((string)($k['id'] ?? '') === $keyId) return $k;
    }
    return null;
}

if ($method === 'GET' && ($path === '' || $path === '/' || $path === '/plugins' || $path === '/plugins/')) {
    $plugins = getPlugins();
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    if ($tenant) {
        $plugins = array_values(array_filter($plugins, fn($p) => (($p['tenant_id'] ?? '') === $tenant)));
    }
    ServiceHelpers::sendJson(200, ['items' => $plugins, 'total' => count($plugins)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)$#', $path, $matches)) {
    $plugin = getPluginById($matches[1]);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    ServiceHelpers::sendJson(200, $plugin);
}

if ($method === 'POST' && ($path === '/plugins' || $path === '/plugins/')) {
    $payload = getRequestBody();
    $name = trim($payload['name'] ?? '');
    $version = trim($payload['version'] ?? '');

    if ($name === '' || $version === '') {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_plugin', 'message' => 'Plugin name and version are required.']);
    }

    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');

    $plugin = [
        'id' => ServiceHelpers::generateUuid(),
        'name' => $name,
        'description' => trim($payload['description'] ?? ''),
        'author' => trim($payload['author'] ?? ''),
        'version' => $version,
        'manifest_url' => trim($payload['manifest_url'] ?? ''),
        'published' => true,
        'tenant_id' => ServiceHelpers::normalizeTenantId($payload) ?? '',
        'created_at' => gmdate('c'),
    ];

    $plugins[] = $plugin;
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(201, $plugin);
}

// Update plugin metadata
if ($method === 'PUT' && preg_match('#^/plugins/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    $payload = getRequestBody();
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p = array_merge($p, $payload);
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// Publish plugin
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/publish$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p['published'] = true;
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// Unpublish plugin
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/unpublish$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugins = ServiceHelpers::loadJson('marketplace', 'plugins.json');
    $found = false;
    foreach ($plugins as &$p) {
        if (($p['id'] ?? '') === $pluginId) {
            $p['published'] = false;
            $p['updated_at'] = gmdate('c');
            $found = true;
            $result = $p;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugins.json', $plugins);
    ServiceHelpers::sendJson(200, $result);
}

// --- Plugin Keys (public signing keys) ---
if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/keys$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $keys = getPluginKeys($pluginId);
    ServiceHelpers::sendJson(200, ['items' => $keys, 'total' => count($keys)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $key = getPluginKeyById($pluginId, $keyId);
    if (!$key) {
        ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    }
    ServiceHelpers::sendJson(200, $key);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $publicKey = trim($payload['public_key'] ?? '');
    $label = trim($payload['label'] ?? '');
    if ($publicKey === '') {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_key', 'message' => 'public_key is required']);
    }
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'public_key' => $publicKey,
        'label' => $label,
        'revoked' => false,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

// Revoke a key
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)/revoke$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    foreach ($all as &$k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $k['revoked'] = true;
            $k['revoked_at'] = gmdate('c');
            $found = true;
            $result = $k;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(200, $result);
}

// Activate (unrevoke) a key
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)/activate$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    foreach ($all as &$k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $k['revoked'] = false;
            unset($k['revoked_at']);
            $found = true;
            $result = $k;
            break;
        }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $all);
    ServiceHelpers::sendJson(200, $result);
}

// Delete a key
if ($method === 'DELETE' && preg_match('#^/plugins/([^/]+)/keys/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $keyId = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_keys.json');
    $found = false;
    $new = [];
    foreach ($all as $k) {
        if (($k['plugin_id'] ?? '') === $pluginId && ($k['id'] ?? '') === $keyId) {
            $found = true;
            continue;
        }
        $new[] = $k;
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'key_not_found']);
    ServiceHelpers::saveJson('marketplace', 'plugin_keys.json', $new);
    ServiceHelpers::sendJson(200, ['status' => 'deleted']);
}

// --- Plugin Versions & Installs ---
function getPluginVersions(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugins_versions.json');
    return array_values(array_filter($all, fn($v) => (($v['plugin_id'] ?? '') === $pluginId)));
}

function getPluginVersionByIdentifier(string $pluginId, string $identifier): ?array
{
    foreach (getPluginVersions($pluginId) as $v) {
        if ((string)($v['id'] ?? '') === $identifier || (string)($v['version'] ?? '') === $identifier) {
            return $v;
        }
    }
    return null;
}

function getPluginInstalls(string $pluginId): array
{
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    return array_values(array_filter($all, fn($i) => (($i['plugin_id'] ?? '') === $pluginId)));
}

function sendWebhook(string $url, array $payload): void
{
    try {
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 2,
                'content' => json_encode($payload),
            ],
        ];
        $ctx = stream_context_create($opts);
        @file_get_contents($url, false, $ctx);
    } catch (Throwable $e) {
        // ignore webhook failures
    }
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $versions = getPluginVersions($pluginId);
    ServiceHelpers::sendJson(200, ['items' => $versions, 'total' => count($versions)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $identifier = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $version = getPluginVersionByIdentifier($pluginId, $identifier);
    if (!$version) {
        ServiceHelpers::sendJson(404, ['error' => 'version_not_found']);
    }
    ServiceHelpers::sendJson(200, $version);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/versions$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $ver = trim($payload['version'] ?? '');
    $manifestUrl = isset($payload['manifest_url']) ? trim($payload['manifest_url']) : '';
    $manifestObj = isset($payload['manifest']) ? $payload['manifest'] : null;
    if ($ver === '' || ($manifestUrl === '' && $manifestObj === null)) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_version', 'message' => 'Version string and manifest (or manifest_url) are required.']);
    }

    $manifestValidated = false;
    $signatureVerified = false;
    if ($manifestObj !== null) {
        $errors = validateManifest($manifestObj);
        if (!empty($errors)) {
            ServiceHelpers::sendJson(400, ['error' => 'invalid_manifest', 'details' => $errors]);
        }
        $manifestValidated = true;
        $signature = trim($payload['signature'] ?? '');
        $publicKey = isset($payload['public_key']) ? trim($payload['public_key']) : null;
        if ($signature !== '') {
            $ok = false;
            // If a public_key was provided in the request, try it first
            if ($publicKey !== null && $publicKey !== '') {
                $ok = verifyManifestSignature($manifestObj, $signature, $publicKey);
            }
            // Otherwise try any registered plugin keys
            if (!$ok) {
                $registered = getPluginKeys($pluginId);
                foreach ($registered as $rk) {
                    if (!empty($rk['public_key']) && empty($rk['revoked'])) {
                        if (verifyManifestSignature($manifestObj, $signature, $rk['public_key'])) {
                            $ok = true;
                            break;
                        }
                    }
                }
            }
            // Fallback to default server key if still not ok
            if (!$ok) {
                if (verifyManifestSignature($manifestObj, $signature, null)) {
                    $ok = true;
                }
            }
            if (!$ok) {
                ServiceHelpers::sendJson(400, ['error' => 'invalid_signature', 'message' => 'Manifest signature verification failed.']);
            }
            $signatureVerified = true;
        }
    }

    $versions = ServiceHelpers::loadJson('marketplace', 'plugins_versions.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'version' => $ver,
        'manifest_url' => $manifestUrl,
        'manifest' => $manifestObj !== null ? $manifestObj : null,
        'manifest_validated' => $manifestValidated,
        'signature_verified' => $signatureVerified,
        'changelog' => trim($payload['changelog'] ?? ''),
        'created_at' => gmdate('c'),
    ];
    $versions[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugins_versions.json', $versions);
    ServiceHelpers::sendJson(201, $entry);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/installs$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    $installs = getPluginInstalls($pluginId);
    if ($tenant) {
        $installs = array_values(array_filter($installs, fn($i) => (($i['tenant_id'] ?? '') === $tenant)));
    }
    ServiceHelpers::sendJson(200, ['items' => $installs, 'total' => count($installs)]);
}

// Plugin ratings
if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/ratings$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $items = array_values(array_filter($all, fn($r) => (($r['plugin_id'] ?? '') === $pluginId)));
    $avg = null;
    if (!empty($items)) {
        $sum = 0; foreach ($items as $it) $sum += intval($it['rating'] ?? 0);
        $avg = $sum / count($items);
    }
    ServiceHelpers::sendJson(200, ['items' => $items, 'total' => count($items), 'average' => $avg]);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/ratings$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $payload = getRequestBody();
    $rating = isset($payload['rating']) ? intval($payload['rating']) : 0;
    $comment = trim($payload['comment'] ?? '');
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if ($rating < 1 || $rating > 5) ServiceHelpers::sendJson(400, ['error' => 'invalid_rating', 'message' => 'rating must be 1-5']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_ratings.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'tenant_id' => $tenantId,
        'rating' => $rating,
        'comment' => $comment,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_ratings.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

// --- Plugin Artifacts ---
if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $payload = getRequestBody();
    $fileName = trim($payload['file_name'] ?? 'artifact.bin');
    $artifactBase64 = $payload['artifact_base64'] ?? '';
    if (trim($artifactBase64) === '') ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact', 'message' => 'artifact_base64 required']);
    $raw = base64_decode($artifactBase64, true);
    if ($raw === false) ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact', 'message' => 'artifact_base64 not valid base64']);

    $artDir = __DIR__ . '/../../services/data/artifacts';
    ensureDir($artDir);
    $fileId = ServiceHelpers::generateUuid();
    $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fileName);
    $pathOnDisk = $artDir . '/' . $pluginId . '_' . $version . '_' . $fileId . '_' . $safeName;
    file_put_contents($pathOnDisk, $raw);

    $signature = trim($payload['signature'] ?? '');
    $publicKey = isset($payload['public_key']) ? trim($payload['public_key']) : null;
    $signatureVerified = false;
    if ($signature !== '') {
        $ok = false;
        if ($publicKey !== null && $publicKey !== '') {
            $ok = verifyArtifactSignature($raw, $signature, $publicKey);
        }
        if (!$ok) {
            $registered = getPluginKeys($pluginId);
            foreach ($registered as $rk) {
                if (!empty($rk['public_key']) && empty($rk['revoked'])) {
                    if (verifyArtifactSignature($raw, $signature, $rk['public_key'])) {
                        $ok = true;
                        break;
                    }
                }
            }
        }
        if (!$ok) {
            if (verifyArtifactSignature($raw, $signature, null)) {
                $ok = true;
            }
        }
        if (!$ok) {
            ServiceHelpers::sendJson(400, ['error' => 'invalid_artifact_signature', 'message' => 'Artifact signature verification failed.']);
        }
        $signatureVerified = true;
    }

    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $entry = [
        'id' => $fileId,
        'plugin_id' => $pluginId,
        'version' => $version,
        'file_name' => $fileName,
        'path' => $pathOnDisk,
        'signature_verified' => $signatureVerified,
        'signature' => $signature !== '' ? $signature : null,
        'public_key' => $publicKey !== null ? $publicKey : null,
        'created_at' => gmdate('c'),
    ];
    $all[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_artifacts.json', $all);
    ServiceHelpers::sendJson(201, $entry);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $items = array_values(array_filter($all, fn($a) => (($a['plugin_id'] ?? '') === $pluginId && ($a['version'] ?? '') === $version)));
    ServiceHelpers::sendJson(200, ['items' => $items, 'total' => count($items)]);
}

if ($method === 'GET' && preg_match('#^/plugins/([^/]+)/versions/([^/]+)/artifact/([^/]+)$#', $path, $matches)) {
    $pluginId = $matches[1];
    $version = $matches[2];
    $artifactId = $matches[3];
    $plugin = getPluginById($pluginId);
    if (!$plugin) ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    $all = ServiceHelpers::loadJson('marketplace', 'plugin_artifacts.json');
    $item = null;
    foreach ($all as $a) {
        if (($a['id'] ?? '') === $artifactId && ($a['plugin_id'] ?? '') === $pluginId && ($a['version'] ?? '') === $version) {
            $item = $a;
            break;
        }
    }
    if (!$item) ServiceHelpers::sendJson(404, ['error' => 'artifact_not_found']);
    if (empty($item['path']) || !file_exists($item['path'])) {
        ServiceHelpers::sendJson(500, ['error' => 'artifact_missing_on_disk']);
    }
    $raw = file_get_contents($item['path']);
    if ($raw === false) ServiceHelpers::sendJson(500, ['error' => 'artifact_read_error']);
    $b64 = base64_encode($raw);
    $resp = $item;
    $resp['download_base64'] = $b64;
    ServiceHelpers::sendJson(200, $resp);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/install$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'missing_tenant', 'message' => 'tenant_id required to install plugin']);
    }
    $versionReq = trim($payload['version'] ?? '');
    if ($versionReq !== '') {
        $versionObj = getPluginVersionByIdentifier($pluginId, $versionReq);
        if (!$versionObj) {
            ServiceHelpers::sendJson(404, ['error' => 'version_not_found']);
        }
        $versionToUse = $versionObj['version'];
    } else {
        $versionToUse = $plugin['version'] ?? null;
    }
    // Resolve version object if available
    $versionObj = getPluginVersionByIdentifier($pluginId, $versionToUse);
    $manifestForVersion = $versionObj['manifest'] ?? null;
    $autoInstallDeps = !empty($payload['auto_install_dependencies']);
    // If manifest declares dependencies, ensure they are satisfied or auto-install
    if (is_array($manifestForVersion) && !empty($manifestForVersion['dependencies'])) {
        $missing = [];
        foreach ($manifestForVersion['dependencies'] as $dep) {
            $depPluginId = $dep['plugin_id'] ?? null;
            $depVersion = $dep['version'] ?? null;
            if (!$depPluginId || !$depVersion) {
                ServiceHelpers::sendJson(400, ['error' => 'invalid_dependency_spec', 'detail' => $dep]);
            }
            // check if dependency is already installed for tenant
            $installsAll = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
            $found = false;
            foreach ($installsAll as $ins) {
                if (($ins['plugin_id'] ?? '') === $depPluginId && ($ins['tenant_id'] ?? '') === $tenantId && ($ins['status'] ?? '') === 'installed' && ($ins['version'] ?? '') === $depVersion) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = ['plugin_id' => $depPluginId, 'version' => $depVersion];
            }
        }
        if (!empty($missing)) {
            if ($autoInstallDeps) {
                // attempt to auto-install missing dependencies
                $allInstalls = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
                foreach ($missing as $m) {
                    $depPlugin = getPluginById($m['plugin_id']);
                    if (!$depPlugin) ServiceHelpers::sendJson(404, ['error' => 'dependency_plugin_not_found', 'detail' => $m]);
                    $depVersionObj = getPluginVersionByIdentifier($m['plugin_id'], $m['version']);
                    if (!$depVersionObj) ServiceHelpers::sendJson(404, ['error' => 'dependency_version_not_found', 'detail' => $m]);
                    $depEntry = [
                        'id' => ServiceHelpers::generateUuid(),
                        'plugin_id' => $m['plugin_id'],
                        'tenant_id' => $tenantId,
                        'version' => $m['version'],
                        'status' => 'installed',
                        'installed_at' => gmdate('c'),
                    ];
                    $allInstalls[] = $depEntry;
                    // send webhook for dependency install if configured
                    if (!empty($depPlugin['webhook_url'])) {
                        sendWebhook($depPlugin['webhook_url'], ['event' => 'installed', 'plugin_id' => $m['plugin_id'], 'tenant_id' => $tenantId, 'version' => $m['version'], 'install' => $depEntry]);
                    }
                }
                ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $allInstalls);
            } else {
                ServiceHelpers::sendJson(400, ['error' => 'missing_dependencies', 'details' => $missing]);
            }
        }
    }
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'plugin_id' => $pluginId,
        'tenant_id' => $tenantId,
        'version' => $versionToUse,
        'status' => 'installed',
        'installed_at' => gmdate('c'),
    ];
    $installs[] = $entry;
    ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $installs);
    // send install webhook if configured
    if (!empty($plugin['webhook_url'])) {
        sendWebhook($plugin['webhook_url'], ['event' => 'installed', 'plugin_id' => $pluginId, 'tenant_id' => $tenantId, 'version' => $versionToUse, 'install' => $entry]);
    }
    ServiceHelpers::sendJson(200, ['status' => 'installed', 'install' => $entry]);
}

if ($method === 'POST' && preg_match('#^/plugins/([^/]+)/uninstall$#', $path, $matches)) {
    $pluginId = $matches[1];
    $plugin = getPluginById($pluginId);
    if (!$plugin) {
        ServiceHelpers::sendJson(404, ['error' => 'plugin_not_found']);
    }
    $payload = getRequestBody();
    $tenantId = ServiceHelpers::normalizeTenantId($payload) ?? trim($payload['tenant_id'] ?? '');
    if (!$tenantId) {
        ServiceHelpers::sendJson(400, ['error' => 'missing_tenant', 'message' => 'tenant_id required to uninstall plugin']);
    }
    $installs = ServiceHelpers::loadJson('marketplace', 'plugin_installs.json');
    $found = false;
    foreach ($installs as &$ins) {
        if (($ins['plugin_id'] ?? '') === $pluginId && ($ins['tenant_id'] ?? '') === $tenantId && ($ins['status'] ?? '') === 'installed') {
            $ins['status'] = 'uninstalled';
            $ins['uninstalled_at'] = gmdate('c');
            $found = true;
            $result = $ins;
            break;
        }
    }
    if (!$found) {
        ServiceHelpers::sendJson(404, ['error' => 'not_installed']);
    }
    ServiceHelpers::saveJson('marketplace', 'plugin_installs.json', $installs);
    // send uninstall webhook if configured
    if (!empty($plugin['webhook_url'])) {
        sendWebhook($plugin['webhook_url'], ['event' => 'uninstalled', 'plugin_id' => $pluginId, 'tenant_id' => $tenantId, 'version' => $result['version'] ?? null, 'install' => $result]);
    }
    ServiceHelpers::sendJson(200, ['status' => 'uninstalled', 'install' => $result]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);

// --- Simple UI Endpoints ---
if ($method === 'GET' && ($path === '/dep-graph' || $path === '/ui/dep-graph')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Dependency Graph</title><script src="https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js"></script></head><body>';
    echo '<h2>Marketplace Dependency Graph</h2>';
    echo '<div id="graph" class="mermaid">Loading graph...</div>';
    echo '<p><a href="/dep-graph.mmd" download>Download mermaid source</a></p>';
    echo '<script>';
    echo 'mermaid.initialize({ startOnLoad: false });';
    echo 'fetch("/dep-graph.mmd").then(r=>r.text()).then(t=>{ document.getElementById("graph").innerText = t; mermaid.init(undefined, document.getElementById("graph")); }).catch(e=>{document.getElementById("graph").innerText = "Error loading graph: "+e});';
    echo '</script></body></html>';
    exit;
}

if ($method === 'GET' && $path === '/dep-graph.mmd') {
    $f = __DIR__ . '/../../tools/dep-graph.mmd';
    if (!file_exists($f)) ServiceHelpers::sendJson(404, ['error' => 'not_found']);
    header('Content-Type: text/plain; charset=utf-8');
    echo file_get_contents($f);
    exit;
}

if ($method === 'GET' && ($path === '/marketplace-ui' || $path === '/ui')) {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Marketplace UI</title>
</head>
<body>
<h2>Marketplace Admin UI</h2>
<button onclick="refresh()">Refresh Plugins</button> <span id="status"></span>
<table id="plugins" border="1" cellpadding="6" style="margin-top:12px; border-collapse: collapse;"><thead><tr><th>Name</th><th>Version</th><th>Published</th><th>Actions</th></tr></thead><tbody></tbody></table>
<script>
  function apiGet(p){ return fetch(p,{headers:{Accept:"application/json"}}).then(r=>r.json()); }
  function apiPost(p,b){ return fetch(p,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(b||{})}).then(r=>r.json()); }
  function setStatus(s){ document.getElementById("status").innerText = s; }
  async function refresh(){ setStatus("loading..."); try{ const res = await apiGet('/api/v1/marketplace/plugins'); const items = res.items || []; const tb = document.querySelector('#plugins tbody'); tb.innerHTML = ''; for(const p of items){ const tr=document.createElement('tr'); tr.innerHTML = `<td>${p.name||p.id}</td><td>${p.version||''}</td><td>${p.published?"yes":"no"}</td><td></td>`; const td = tr.querySelector('td:last-child');
        const pubBtn = document.createElement('button'); pubBtn.innerText = p.published? 'Unpublish' : 'Publish'; pubBtn.onclick = async ()=>{ setStatus('updating...'); try{ const ep = '/api/v1/marketplace/plugins/'+encodeURIComponent(p.id)+'/'+(p.published? 'unpublish' : 'publish'); const r = await apiPost(ep,{}); alert('Result: '+JSON.stringify(r)); await refresh(); } catch(e){ alert('Error: '+e); } };
        const rateBtn = document.createElement('button'); rateBtn.innerText='Rate'; rateBtn.onclick=async ()=>{ const rating = prompt('Rating 1-5'); if(!rating) return; const comment = prompt('Comment (optional)')||''; try{ const r = await apiPost('/api/v1/marketplace/plugins/'+encodeURIComponent(p.id)+'/ratings',{rating:parseInt(rating,10),comment}); alert('Rated: '+JSON.stringify(r)); }catch(e){ alert('Error: '+e); } };
        const installBtn = document.createElement('button'); installBtn.innerText='Install'; installBtn.onclick=async ()=>{ const tenant = prompt('Tenant ID (required)'); if(!tenant) return; const auto = confirm('Auto-install dependencies?'); try{ const r = await apiPost('/api/v1/marketplace/plugins/'+encodeURIComponent(p.id)+'/install',{tenant_id:tenant,auto_install_dependencies: auto}); alert('Installed: '+JSON.stringify(r)); await refresh(); }catch(e){ alert('Error: '+JSON.stringify(e)); } };
        const uninstallBtn = document.createElement('button'); uninstallBtn.innerText='Uninstall'; uninstallBtn.onclick=async ()=>{ const tenant = prompt('Tenant ID (required)'); if(!tenant) return; try{ const r = await apiPost('/api/v1/marketplace/plugins/'+encodeURIComponent(p.id)+'/uninstall',{tenant_id:tenant}); alert('Uninstalled: '+JSON.stringify(r)); await refresh(); }catch(e){ alert('Error: '+JSON.stringify(e)); } };
        td.appendChild(pubBtn); td.appendChild(document.createTextNode(' ')); td.appendChild(rateBtn); td.appendChild(document.createTextNode(' ')); td.appendChild(installBtn); td.appendChild(document.createTextNode(' ')); td.appendChild(uninstallBtn);
        tb.appendChild(tr);
    }
    setStatus('loaded '+items.length+' plugins'); }catch(e){ setStatus('error'); alert('Failed to load plugins: '+e); } }
  window.onload=()=>refresh();
</script>
</body>
</html>
HTML;
    exit;
}
