<?php
/**
 * Social Microservice (MVP)
 * Minimal, file-backed service providing posts, comments, likes, and follows.
 * Designed to be non-invasive and tenant-aware. Stores data under services/data/
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'social');
define('SERVICE_PORT', 8008);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadPosts(): array { return ServiceHelpers::loadJson('social', 'posts.json'); }
function savePosts(array $d): bool { return ServiceHelpers::saveJson('social', 'posts.json', $d); }
function loadComments(): array { return ServiceHelpers::loadJson('social', 'comments.json'); }
function saveComments(array $d): bool { return ServiceHelpers::saveJson('social', 'comments.json', $d); }
function loadLikes(): array { return ServiceHelpers::loadJson('social', 'likes.json'); }
function saveLikes(array $d): bool { return ServiceHelpers::saveJson('social', 'likes.json', $d); }
function loadFollows(): array { return ServiceHelpers::loadJson('social', 'follows.json'); }
function saveFollows(array $d): bool { return ServiceHelpers::saveJson('social', 'follows.json', $d); }

function getCurrentUserId(): ?string {
    $h = ServiceHelpers::getHeader('X-User-Id');
    if (!empty($h)) return $h;
    $body = ServiceHelpers::getRequestBody();
    return $body['user_id'] ?? null;
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
}

// Create post
if ($method === 'POST' && $uri === '/api/v1/social/posts') {
    $input = ServiceHelpers::getRequestBody();
    $userId = getCurrentUserId();
    if (!$userId) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

    $tenantId = ServiceHelpers::normalizeTenantId($input);
    $content = trim($input['content'] ?? '');
    if ($content === '') ServiceHelpers::sendJson(400, ['error' => 'content_required']);

    $posts = loadPosts();
    $id = ServiceHelpers::generateUuid();
    $now = gmdate('c');
    $post = [
        'id' => $id,
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'content' => $content,
        'media' => $input['media'] ?? [],
        'likes_count' => 0,
        'comments_count' => 0,
        'created_at' => $now,
    ];
    array_unshift($posts, $post);
    savePosts($posts);
    ServiceHelpers::sendJson(201, ['post' => $post]);
}

// Get single post (with comments)
if ($method === 'GET' && preg_match('#^/api/v1/social/posts/([a-f0-9]+)$#i', $uri, $m)) {
    $id = $m[1];
    $posts = loadPosts();
    foreach ($posts as $p) {
        if ($p['id'] === $id) {
            $comments = array_values(array_filter(loadComments(), fn($c) => $c['post_id'] === $id));
            $p['comments'] = $comments;
            ServiceHelpers::sendJson(200, ['post' => $p]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'post_not_found']);
}

// List posts (tenant-aware, paginated)
if ($method === 'GET' && $uri === '/api/v1/social/posts') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $tenant = $_GET['tenant_id'] ?? ServiceHelpers::getHeader('X-Tenant-Id') ?? null;

    $posts = loadPosts();
    if ($tenant) {
        $posts = array_values(array_filter($posts, fn($p) => ($p['tenant_id'] ?? null) == $tenant));
    }

    $total = count($posts);
    $offset = ($page - 1) * $limit;
    $items = array_slice($posts, $offset, $limit);

    ServiceHelpers::sendJson(200, ['items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total]);
}

// Add comment to post
if ($method === 'POST' && preg_match('#^/api/v1/social/posts/([a-f0-9]+)/comments$#i', $uri, $m)) {
    $postId = $m[1];
    $input = ServiceHelpers::getRequestBody();
    $userId = getCurrentUserId();
    if (!$userId) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

    $text = trim($input['content'] ?? '');
    if ($text === '') ServiceHelpers::sendJson(400, ['error' => 'content_required']);

    // ensure post exists
    $posts = loadPosts();
    $found = false;
    foreach ($posts as &$p) {
        if ($p['id'] === $postId) { $found = true; break; }
    }
    if (!$found) ServiceHelpers::sendJson(404, ['error' => 'post_not_found']);

    $comments = loadComments();
    $id = ServiceHelpers::generateUuid();
    $now = gmdate('c');
    $comment = ['id' => $id, 'post_id' => $postId, 'user_id' => $userId, 'content' => $text, 'created_at' => $now];
    $comments[] = $comment;
    saveComments($comments);

    // increment count on post
    foreach ($posts as &$p) {
        if ($p['id'] === $postId) { $p['comments_count'] = ($p['comments_count'] ?? 0) + 1; }
    }
    savePosts($posts);

    ServiceHelpers::sendJson(201, ['comment' => $comment]);
}

// Like/unlike a post
if ($method === 'POST' && preg_match('#^/api/v1/social/posts/([a-f0-9]+)/like$#i', $uri, $m)) {
    $postId = $m[1];
    $userId = getCurrentUserId();
    if (!$userId) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

    $likes = loadLikes();
    // search for existing like
    $existsKey = null;
    foreach ($likes as $k => $r) {
        if ($r['post_id'] === $postId && $r['user_id'] === $userId) { $existsKey = $k; break; }
    }

    $posts = loadPosts();
    if ($existsKey !== null) {
        // unlike
        $removed = $likes[$existsKey];
        array_splice($likes, $existsKey, 1);
        saveLikes($likes);
        foreach ($posts as &$p) { if ($p['id'] === $postId) { $p['likes_count'] = max(0, ($p['likes_count'] ?? 1) - 1); } }
        savePosts($posts);
        ServiceHelpers::sendJson(200, ['liked' => false]);
    } else {
        $now = gmdate('c');
        $like = ['id' => ServiceHelpers::generateUuid(), 'post_id' => $postId, 'user_id' => $userId, 'created_at' => $now];
        $likes[] = $like;
        saveLikes($likes);
        foreach ($posts as &$p) { if ($p['id'] === $postId) { $p['likes_count'] = ($p['likes_count'] ?? 0) + 1; } }
        savePosts($posts);
        ServiceHelpers::sendJson(201, ['liked' => true]);
    }
}

// Follow / unfollow a user
if ($method === 'POST' && preg_match('#^/api/v1/social/users/([^/]+)/follow$#i', $uri, $m)) {
    $targetUser = $m[1];
    $input = ServiceHelpers::getRequestBody();
    $actor = getCurrentUserId();
    if (!$actor) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

    $action = strtolower($input['action'] ?? 'follow');
    $follows = loadFollows();
    if ($action === 'unfollow') {
        $removed = false;
        foreach ($follows as $k => $rec) {
            if ($rec['follower'] === $actor && $rec['followee'] === $targetUser) { array_splice($follows, $k, 1); $removed = true; break; }
        }
        saveFollows($follows);
        ServiceHelpers::sendJson(200, ['following' => false, 'removed' => $removed]);
    }

    // follow
    foreach ($follows as $rec) {
        if ($rec['follower'] === $actor && $rec['followee'] === $targetUser) {
            ServiceHelpers::sendJson(200, ['following' => true]);
        }
    }
    $follows[] = ['id' => ServiceHelpers::generateUuid(), 'follower' => $actor, 'followee' => $targetUser, 'created_at' => gmdate('c')];
    saveFollows($follows);
    ServiceHelpers::sendJson(201, ['following' => true]);
}

// Get followers or following lists
if ($method === 'GET' && preg_match('#^/api/v1/social/users/([^/]+)/(followers|following)$#i', $uri, $m)) {
    $userId = $m[1];
    $what = $m[2];
    $follows = loadFollows();
    if ($what === 'followers') {
        $list = array_values(array_map(fn($r) => $r['follower'], array_values(array_filter($follows, fn($r) => $r['followee'] === $userId))));
    } else {
        $list = array_values(array_map(fn($r) => $r['followee'], array_values(array_filter($follows, fn($r) => $r['follower'] === $userId))));
    }
    ServiceHelpers::sendJson(200, ['users' => $list]);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
