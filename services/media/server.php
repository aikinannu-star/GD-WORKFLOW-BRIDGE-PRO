<?php
/**
 * Media Service (MVP) - object storage integration
 * Accepts base64-encoded file uploads via JSON, uploads to S3/MinIO,
 * stores metadata in the `media` DB table, and returns metadata with a URL.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

// optionally load Composer autoloader if available (for AWS SDK)
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

require_once __DIR__ . '/../../includes/DB.php';
require_once __DIR__ . '/../../includes/Storage/S3Adapter.php';

define('SERVICE_NAME', 'media');
define('SERVICE_PORT', 8010);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadMedia(): array { return ServiceHelpers::loadJson('media', 'files.json'); }
function saveMedia(array $d): bool { return ServiceHelpers::saveJson('media', 'files.json', $d); }

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME, 'version' => '1.1.0', 'time' => gmdate('c')]);
}

if ($method === 'POST' && $uri === '/api/v1/media/upload') {
    // Support both multipart/form-data file uploads and legacy base64 JSON uploads.
    // Multipart upload: expect field name 'file' (single file) and optional 'tenant_id'.
    if (!empty($_FILES) && isset($_FILES['file'])) {
        $f = $_FILES['file'];
        $tmp = $f['tmp_name'] ?? null;
        $filename = $f['name'] ?? ($f['filename'] ?? 'upload.bin');
        $userId = ServiceHelpers::getHeader('X-User-Id') ?? $_POST['user_id'] ?? null;
        if (!$userId) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

        $tenantId = ServiceHelpers::normalizeTenantId($_POST) ?: 'default';
        if (!$tmp || !is_uploaded_file($tmp)) ServiceHelpers::sendJson(400, ['error' => 'invalid_upload']);

        $content = file_get_contents($tmp);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content) ?: ($f['type'] ?? 'application/octet-stream');

        $rawId = ServiceHelpers::generateUuid();
        $id = substr($rawId,0,8) . '-' . substr($rawId,8,4) . '-' . substr($rawId,12,4) . '-' . substr($rawId,16,4) . '-' . substr($rawId,20,12);
        $now = gmdate('c');

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        $key = sprintf('media/%s/%s/%s', rawurlencode($tenantId), $id, $safeName);
        $bucket = getenv('S3_BUCKET') ?: 'gdwb-media';

        try {
            $s3 = new \GDWB\Storage\S3Adapter();
            $s3->putObject($bucket, $key, $content, $mime, ['ACL' => 'public-read']);
            $url = $s3->getObjectUrl($bucket, $key);

            // persist metadata
            try {
                $pdo = \GDWB\DB::getPDO();
                $stmt = $pdo->prepare('INSERT INTO media (id, tenant_id, user_id, key, bucket, mime, size, created_at) VALUES (:id, :tenant_id, :user_id, :key, :bucket, :mime, :size, :created_at)');
                $stmt->execute([
                    ':id' => $id,
                    ':tenant_id' => $tenantId,
                    ':user_id' => $userId,
                    ':key' => $key,
                    ':bucket' => $bucket,
                    ':mime' => $mime,
                    ':size' => strlen($content),
                    ':created_at' => $now,
                ]);
            } catch (Exception $e) {
                // non-fatal: continue and also write file-backed record
                error_log('Media DB insert error: ' . $e->getMessage());
            }

            $files = loadMedia();
            $files[] = [
                'id' => $id,
                'filename' => $filename,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'size' => strlen($content),
                'key' => $key,
                'bucket' => $bucket,
                'mime' => $mime,
                'url' => $url,
                'created_at' => $now,
            ];
            saveMedia($files);

            ServiceHelpers::sendJson(201, ['media' => [
                'id' => $id,
                'filename' => $filename,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'size' => strlen($content),
                'mime' => $mime,
                'key' => $key,
                'bucket' => $bucket,
                'url' => $url,
                'created_at' => $now,
            ]]);

        } catch (Exception $e) {
            error_log('Media multipart upload error: ' . $e->getMessage());
            ServiceHelpers::sendJson(500, ['error' => 'upload_failed', 'message' => $e->getMessage()]);
        }
    }

    // Legacy JSON base64 upload fallback
    $input = ServiceHelpers::getRequestBody();
    $userId = ServiceHelpers::getHeader('X-User-Id') ?? $input['user_id'] ?? null;
    if (!$userId) ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);

    $tenantId = ServiceHelpers::normalizeTenantId($input) ?: 'default';
    $filename = trim($input['filename'] ?? '');
    $contentB64 = $input['content'] ?? null;
    if ($filename === '' || !$contentB64) ServiceHelpers::sendJson(400, ['error' => 'filename_and_content_required']);

    $decoded = base64_decode($contentB64, true);
    if ($decoded === false) ServiceHelpers::sendJson(400, ['error' => 'invalid_base64']);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($decoded) ?: 'application/octet-stream';

    $rawId = ServiceHelpers::generateUuid();
    $id = substr($rawId,0,8) . '-' . substr($rawId,8,4) . '-' . substr($rawId,12,4) . '-' . substr($rawId,16,4) . '-' . substr($rawId,20,12);
    $now = gmdate('c');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    $key = sprintf('media/%s/%s/%s', rawurlencode($tenantId), $id, $safeName);
    $bucket = getenv('S3_BUCKET') ?: 'gdwb-media';

    try {
        $s3 = new \GDWB\Storage\S3Adapter();
        $s3->putObject($bucket, $key, $decoded, $mime, ['ACL' => 'public-read']);
        $url = $s3->getObjectUrl($bucket, $key);

        try {
            $pdo = \GDWB\DB::getPDO();
            $stmt = $pdo->prepare('INSERT INTO media (id, tenant_id, user_id, key, bucket, mime, size, created_at) VALUES (:id, :tenant_id, :user_id, :key, :bucket, :mime, :size, :created_at)');
            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId,
                ':user_id' => $userId,
                ':key' => $key,
                ':bucket' => $bucket,
                ':mime' => $mime,
                ':size' => strlen($decoded),
                ':created_at' => $now,
            ]);
        } catch (Exception $e) {
            error_log('Media DB insert error: ' . $e->getMessage());
        }

        $files = loadMedia();
        $files[] = [
            'id' => $id,
            'filename' => $filename,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'size' => strlen($decoded),
            'key' => $key,
            'bucket' => $bucket,
            'mime' => $mime,
            'url' => $url,
            'created_at' => $now,
        ];
        saveMedia($files);

        ServiceHelpers::sendJson(201, ['media' => [
            'id' => $id,
            'filename' => $filename,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'size' => strlen($decoded),
            'mime' => $mime,
            'key' => $key,
            'bucket' => $bucket,
            'url' => $url,
            'created_at' => $now,
        ]]);

    } catch (Exception $e) {
        error_log('Media upload error: ' . $e->getMessage());
        ServiceHelpers::sendJson(500, ['error' => 'upload_failed', 'message' => $e->getMessage()]);
    }
}

if ($method === 'GET' && preg_match('#^/api/v1/media/([^/]+)$#', $uri, $m)) {
    $id = $m[1];

    // try DB first
    try {
        $pdo = \GDWB\DB::getPDO();
        $stmt = $pdo->prepare('SELECT id, tenant_id, user_id, key, bucket, mime, size, created_at FROM media WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $s3 = new \GDWB\Storage\S3Adapter();
            $row['url'] = $s3->getObjectUrl($row['bucket'], $row['key']);
            ServiceHelpers::sendJson(200, ['media' => $row]);
        }
    } catch (Exception $e) {
        // ignore and fallback to file-backed storage
        error_log('Media DB lookup error: ' . $e->getMessage());
    }

    // fallback: check file-backed records for legacy data
    $files = loadMedia();
    foreach ($files as $f) {
        if ($f['id'] === $id) {
            ServiceHelpers::sendJson(200, ['media' => $f]);
        }
    }

    ServiceHelpers::sendJson(404, ['error' => 'not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
