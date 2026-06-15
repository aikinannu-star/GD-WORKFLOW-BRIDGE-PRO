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

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
