<?php
/**
 * Feed Service (MVP)
 * Composes a simple chronological feed for a user using `social` posts and `follows` data.
 * Supports optional Redis caching; falls back to file-based cache.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'feed');
define('SERVICE_PORT', 8011);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function cacheLoad(): array { return ServiceHelpers::loadJson('feed', 'cache.json'); }
function cacheSave(array $d): bool { return ServiceHelpers::saveJson('feed', 'cache.json', $d); }

// TODO: Replace file-backed cache with Redis materialized timelines. Use the `redis` service from
// docker-compose.dev.yml and publish feed updates when posts are created/updated in DB.

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '1.0.0', 'time' => gmdate('c')]);
}

if ($method === 'GET' && $uri === '/api/v1/feed') {
    $userId = $_GET['user_id'] ?? ServiceHelpers::getHeader('X-User-Id') ?? null;
    if (!$userId) ServiceHelpers::sendJson(400, ['error' => 'user_id required']);
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));

    $cacheKey = md5(implode('|', [$userId, $tenant ?: '', $page, $limit]));
    $ttl = intval($_ENV['FEED_CACHE_TTL_SEC'] ?? 10);

    // Try Redis first
    try {
        if (class_exists('Redis')) {
            $r = new Redis();
            $r->connect($_ENV['GATEWAY_REDIS_HOST'] ?? '127.0.0.1', intval($_ENV['GATEWAY_REDIS_PORT'] ?? 6379), 1.0);
            $cached = $r->get('feed:' . $cacheKey);
            if ($cached !== false) {
                ServiceHelpers::sendJson(200, json_decode($cached, true));
            }
        }
    } catch (Throwable $e) {
        // Redis not available — fall back
    }

    // File-cache fallback
    $cache = cacheLoad();
    if (isset($cache[$cacheKey]) && ($cache[$cacheKey]['expires'] ?? 0) >= time()) {
        ServiceHelpers::sendJson(200, $cache[$cacheKey]['data']);
    }

    $posts = ServiceHelpers::loadJson('social', 'posts.json');
    $follows = ServiceHelpers::loadJson('social', 'follows.json');

    $followees = array_values(array_map(fn($r) => $r['followee'], array_values(array_filter($follows, fn($r) => $r['follower'] === $userId))));
    $candidates = array_unique(array_merge($followees, [$userId]));

    $items = array_values(array_filter($posts, function($p) use ($candidates, $tenant) {
        if (!in_array($p['user_id'], $candidates)) return false;
        if ($tenant && (($p['tenant_id'] ?? null) != $tenant)) return false;
        return true;
    }));

    usort($items, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

    $total = count($items);
    $offset = ($page - 1) * $limit;
    $pageItems = array_slice($items, $offset, $limit);

    $response = ['items' => $pageItems, 'page' => $page, 'limit' => $limit, 'total' => $total];

    // Save to cache
    try {
        if (isset($r) && $r instanceof Redis) {
            $r->setex('feed:' . $cacheKey, $ttl > 0 ? $ttl : 10, json_encode($response));
        }
    } catch (Throwable $e) {}

    $cache[$cacheKey] = ['expires' => time() + max(1, $ttl), 'data' => $response];
    cacheSave($cache);

    ServiceHelpers::sendJson(200, $response);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
