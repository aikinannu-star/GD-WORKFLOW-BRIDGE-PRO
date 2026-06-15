<?php
/**
 * CMS Service
 * Site builder and content management
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'cms');
define('SERVICE_PORT', 8004);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadSites(): array
{
    return ServiceHelpers::loadJson('cms', 'sites.json');
}

function saveSites(array $sites): bool
{
    return ServiceHelpers::saveJson('cms', 'sites.json', $sites);
}

function loadPages(): array
{
    return ServiceHelpers::loadJson('cms', 'pages.json');
}

function savePages(array $pages): bool
{
    return ServiceHelpers::saveJson('cms', 'pages.json', $pages);
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
}

if ($method === 'POST' && $uri === '/api/v1/sites') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = $input['tenant_id'] ?? null;
    $name = trim($input['name'] ?? '');
    if (!$tenantId || !$name) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id and name are required']);
    }

    $sites = loadSites();
    $siteId = ServiceHelpers::generateUuid();
    $newSite = [
        'id' => $siteId,
        'tenant_id' => $tenantId,
        'name' => $name,
        'domain' => $input['domain'] ?? null,
        'status' => 'draft',
        'published_at' => null,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $sites[] = $newSite;
    saveSites($sites);
    ServiceHelpers::sendJson(201, ['site' => $newSite]);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'PUT' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $sites = loadSites();
    foreach ($sites as &$site) {
        if ($site['id'] === $siteId) {
            $site['name'] = $input['name'] ?? $site['name'];
            $site['domain'] = $input['domain'] ?? $site['domain'];
            $site['updated_at'] = gmdate('c');
            saveSites($sites);
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    $updated = array_filter($sites, fn($site) => $site['id'] !== $siteId);
    if (count($updated) === count($sites)) {
        ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
    }
    saveSites($updated);
    ServiceHelpers::sendJson(200, ['success' => true]);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9]+)/pages$#', $uri, $matches)) {
    $siteId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $sites = loadSites();
    $found = false;
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
    }

    $pageId = ServiceHelpers::generateUuid();
    $pages = loadPages();
    $newPage = [
        'id' => $pageId,
        'site_id' => $siteId,
        'title' => trim($input['title'] ?? 'Untitled Page'),
        'slug' => trim($input['slug'] ?? 'page-' . substr($pageId, 0, 8)),
        'content' => $input['content'] ?? '',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $pages[] = $newPage;
    savePages($pages);
    ServiceHelpers::sendJson(201, ['page' => $newPage]);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9]+)/publish$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as &$site) {
        if ($site['id'] === $siteId) {
            $site['status'] = 'published';
            $site['published_at'] = gmdate('c');
            $site['updated_at'] = gmdate('c');
            saveSites($sites);
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/([a-f0-9]+)/analytics$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            ServiceHelpers::sendJson(200, [
                'analytics' => [
                    'page_views' => rand(100, 1200),
                    'unique_visitors' => rand(50, 680),
                    'bounce_rate' => rand(20, 70),
                    'published_at' => $site['published_at'],
                ],
            ]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
