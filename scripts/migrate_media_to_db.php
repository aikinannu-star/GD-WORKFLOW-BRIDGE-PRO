<?php
/**
 * scripts/migrate_media_to_db.php
 *
 * Usage:
 *   php scripts/migrate_media_to_db.php [--dry-run] [--no-upload] [--update-files] [--limit=N]
 *
 * - --dry-run     : simulate actions; no DB inserts or S3 uploads performed
 * - --no-upload   : skip S3/MinIO uploads (only insert metadata if DB available)
 * - --update-files: write services/data/media_files_migrated.json with updated records
 * - --limit=N     : process only first N records
 *
 * The script will attempt to connect to the DB when not running in dry-run. In
 * dry-run mode the script will still run even if DB or S3 are unavailable and
 * will print the actions it would take.
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../services/lib/ServiceHelpers.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/Storage/S3Adapter.php';

$argv = $_SERVER['argv'];
$dryRun = in_array('--dry-run', $argv, true);
$noUpload = in_array('--no-upload', $argv, true);
$updateFiles = in_array('--update-files', $argv, true);
$limit = null;
foreach ($argv as $a) {
    if (strpos($a, '--limit=') === 0) {
        $limit = (int) substr($a, 8);
    }
}

function logMsg(string $m): void { echo '[' . date('c') . '] ' . $m . PHP_EOL; }

$records = ServiceHelpers::loadJson('media', 'files.json');
if (!is_array($records) || count($records) === 0) {
    logMsg('No media records found (services/data/media_files.json).');
    exit(0);
}

// DB connection: required unless dry-run
$pdo = null;
$canCheckDB = false;
if (!$dryRun) {
    try {
        require_once __DIR__ . '/../includes/DB.php';
        $pdo = \GDWB\DB::getPDO();
        $pdo->query('SELECT 1 FROM media LIMIT 1');
        $canCheckDB = true;
    } catch (Exception $e) {
        logMsg('DB not available: ' . $e->getMessage() . '. Aborting (not in dry-run).');
        exit(1);
    }
} else {
    // Attempt optional DB connection for richer dry-run output
    try {
        require_once __DIR__ . '/../includes/DB.php';
        $pdo = \GDWB\DB::getPDO();
        $pdo->query('SELECT 1 FROM media LIMIT 1');
        $canCheckDB = true;
    } catch (Exception $e) {
        $pdo = null;
        $canCheckDB = false;
        logMsg('Dry-run: DB unavailable; continuing without DB checks.');
    }
}

// S3 client: only when we will perform uploads (not in dry-run)
$s3 = null;
if (!$noUpload && !$dryRun) {
    $s3 = new \GDWB\Storage\S3Adapter();
} elseif ($noUpload) {
    logMsg('Uploads disabled via --no-upload.');
} elseif ($dryRun) {
    logMsg('Dry-run: uploads will be simulated (no actual S3 calls).');
}

$checkStmt = null;
$insertStmt = null;
if ($canCheckDB && $pdo) {
    $checkStmt = $pdo->prepare('SELECT id FROM media WHERE id = :id');
    $insertStmt = $pdo->prepare('INSERT INTO media (id, tenant_id, user_id, key, bucket, mime, size, created_at) VALUES (:id, :tenant_id, :user_id, :key, :bucket, :mime, :size, :created_at)');
}

function normalizeId(string $raw): string {
    $hex = preg_replace('/[^0-9a-f]/i', '', $raw);
    if (strlen($hex) === 32) {
        return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20,12);
    }
    return $raw;
}

$stats = ['processed'=>0,'skipped'=>0,'uploaded'=>0,'inserted'=>0,'errors'=>0];
$migrated = [];
$idx = 0;

foreach ($records as $i => $r) {
    if ($limit !== null && $idx >= $limit) break;
    $idx++;
    $stats['processed']++;

    $rawId = $r['id'] ?? null;
    if (!$rawId) {
        $rawId = ServiceHelpers::generateUuid();
        $r['id'] = $rawId;
    }
    $id = normalizeId($rawId);

    // Skip if present in DB (when possible)
    if ($checkStmt) {
        $checkStmt->execute([':id' => $id]);
        if ($checkStmt->fetch()) {
            logMsg("[$idx] Skipping (already in DB): $id");
            $stats['skipped']++;
            continue;
        }
    }

    $tenantId = $r['tenant_id'] ?? $r['tenant'] ?? 'default';
    $filename = $r['filename'] ?? ($r['name'] ?? 'file.bin');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    $key = $r['key'] ?? sprintf('media/%s/%s/%s', rawurlencode($tenantId), $id, $safeName);
    $bucket = $r['bucket'] ?? getenv('S3_BUCKET') ?: 'gdwb-media';
    $mime = $r['mime'] ?? null;
    $size = $r['size'] ?? null;
    $createdAt = $r['created_at'] ?? gmdate('c');

    $haveBase64 = isset($r['content_b64']) && $r['content_b64'] !== '';

    if ($haveBase64 && !$noUpload) {
        $decoded = base64_decode($r['content_b64'], true);
        if ($decoded === false) {
            logMsg("[$idx] Invalid base64 for id $id - skipping");
            $stats['errors']++;
            continue;
        }
        if (!$mime) {
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($decoded) ?: 'application/octet-stream';
            } else {
                $mime = 'application/octet-stream';
            }
        }
        $size = strlen($decoded);

        if ($dryRun) {
            logMsg("[$idx] Dry-run: would upload $filename -> $bucket/$key (size={$size})");
            $stats['uploaded']++;
        } else {
            try {
                $s3->putObject($bucket, $key, $decoded, $mime, ['ACL' => 'public-read']);
                $stats['uploaded']++;
            } catch (Exception $e) {
                logMsg("[$idx] Upload failed for $id: " . $e->getMessage());
                $stats['errors']++;
                continue;
            }
        }
    } elseif ($haveBase64 && $noUpload) {
        logMsg("[$idx] Skipping upload by --no-upload for $id");
    } elseif (!$haveBase64 && !empty($r['key']) && !empty($r['bucket'])) {
        $key = $r['key'];
        $bucket = $r['bucket'];
        logMsg("[$idx] Found existing object link for $id: $bucket/$key");
    } else {
        logMsg("[$idx] No content or object refs for $id - skipping");
        $stats['skipped']++;
        continue;
    }

    // persist metadata in DB (unless dry-run)
    if (!$dryRun && $insertStmt) {
        try {
            $insertStmt->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId,
                ':user_id' => $r['user_id'] ?? null,
                ':key' => $key,
                ':bucket' => $bucket,
                ':mime' => $mime,
                ':size' => $size,
                ':created_at' => $createdAt,
            ]);
            $stats['inserted']++;
        } catch (Exception $e) {
            logMsg("[$idx] DB insert failed for $id: " . $e->getMessage());
            $stats['errors']++;
            continue;
        }
    }

    // Build migrated record for optional file output
    $new = $r;
    $new['id'] = $id;
    $new['key'] = $key;
    $new['bucket'] = $bucket;
    $new['mime'] = $mime;
    $new['size'] = $size;
    $new['created_at'] = $createdAt;
    if ($updateFiles && !$dryRun) unset($new['content_b64']);
    $migrated[] = $new;
}

if ($updateFiles) {
    if ($dryRun) {
        logMsg('Dry-run: would write migrated file services/data/media_files_migrated.json');
    } else {
        ServiceHelpers::saveJson('media', 'files_migrated.json', $migrated);
        logMsg('Wrote services/data/media_files_migrated.json');
    }
}

logMsg("Migration summary: processed={$stats['processed']} skipped={$stats['skipped']} uploaded={$stats['uploaded']} inserted={$stats['inserted']} errors={$stats['errors']}");
exit(0);
