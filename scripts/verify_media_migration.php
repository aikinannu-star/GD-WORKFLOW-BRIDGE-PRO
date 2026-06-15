<?php
/**
 * scripts/verify_media_migration.php
 *
 * Usage:
 *   php scripts/verify_media_migration.php [--check-objects] [--sample=N]
 *
 * Checks that legacy/migrated media records exist in the database and (optionally)
 * verifies object availability in S3/MinIO.
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../services/lib/ServiceHelpers.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/DB.php';
require_once __DIR__ . '/../includes/Storage/S3Adapter.php';

$argv = $_SERVER['argv'];
$checkObjects = in_array('--check-objects', $argv, true);
$sample = 0;
foreach ($argv as $a) {
    if (strpos($a, '--sample=') === 0) $sample = (int) substr($a, 9);
}

function logMsg(string $m): void { echo '[' . date('c') . '] ' . $m . PHP_EOL; }

// candidate migrated filenames (ServiceHelpers will map to services/data/media_<name>)
$candidates = [
    'files_migrated.json',
    'files.migrated.json',
    'files_migrated.json',
    'files.json'
];

$records = [];
foreach ($candidates as $name) {
    $data = ServiceHelpers::loadJson('media', $name);
    if (!empty($data)) {
        $records = $data;
        logMsg("Using records file: media_{$name}");
        break;
    }
}

if (empty($records)) {
    logMsg('No media records found to verify (services/data/media_*.json)');
    exit(2);
}

if ($sample > 0) {
    $records = array_slice($records, 0, $sample);
}

try {
    $pdo = \GDWB\DB::getPDO();
} catch (Exception $e) {
    logMsg('ERROR: DB connection failed: ' . $e->getMessage());
    exit(2);
}

$checkStmt = $pdo->prepare('SELECT id, key, bucket FROM media WHERE id = :id');

$s3 = null;
if ($checkObjects) {
    $s3 = new \GDWB\Storage\S3Adapter();
}

$stats = ['total' => count($records), 'db_found' => 0, 'db_missing' => 0, 'obj_found' => 0, 'obj_missing' => 0];
$missingDb = [];
$missingObj = [];

function normalizeId(string $raw): string {
    $hex = preg_replace('/[^0-9a-f]/i', '', $raw);
    if (strlen($hex) === 32) {
        return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20,12);
    }
    return $raw;
}

function http_head_status(string $url): int {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return (int)$code;
}

foreach ($records as $rec) {
    $id = normalizeId($rec['id'] ?? ($rec['id'] = '') );
    if ($id === '') {
        $stats['db_missing']++;
        $missingDb[] = ['id' => null, 'reason' => 'no_id_in_record'];
        continue;
    }

    $checkStmt->execute([':id' => $id]);
    $row = $checkStmt->fetch();
    if ($row) {
        $stats['db_found']++;
        // object check
        if ($checkObjects) {
            $bucket = $row['bucket'] ?? null;
            $key = $row['key'] ?? null;
            if ($bucket && $key) {
                $url = $s3->getObjectUrl($bucket, $key);
                $code = http_head_status($url);
                if (in_array($code, [200, 301, 302, 403], true)) {
                    $stats['obj_found']++;
                } else {
                    $stats['obj_missing']++;
                    $missingObj[] = ['id' => $id, 'bucket' => $bucket, 'key' => $key, 'http_code' => $code, 'url' => $url];
                }
            } else {
                // try record-level key/bucket
                $bucket = $rec['bucket'] ?? null;
                $key = $rec['key'] ?? null;
                if ($bucket && $key) {
                    $url = $s3->getObjectUrl($bucket, $key);
                    $code = http_head_status($url);
                    if (in_array($code, [200,301,302,403], true)) {
                        $stats['obj_found']++;
                    } else {
                        $stats['obj_missing']++;
                        $missingObj[] = ['id' => $id, 'bucket' => $bucket, 'key' => $key, 'http_code' => $code, 'url' => $url];
                    }
                } else {
                    $stats['obj_missing']++;
                    $missingObj[] = ['id' => $id, 'reason' => 'no_bucket_or_key'];
                }
            }
        }
    } else {
        $stats['db_missing']++;
        $missingDb[] = ['id' => $id, 'record' => $rec];
    }
}

logMsg('Verification summary:');
logMsg('  total records: ' . $stats['total']);
logMsg('  db found:      ' . $stats['db_found']);
logMsg('  db missing:    ' . $stats['db_missing']);
if ($checkObjects) {
    logMsg('  objects found: ' . $stats['obj_found']);
    logMsg('  objects missing: ' . $stats['obj_missing']);
}

if (!empty($missingDb)) {
    logMsg('Missing DB records (sample):');
    foreach (array_slice($missingDb, 0, 10) as $m) {
        logMsg('  - ' . ($m['id'] ?? '<no-id>') . ' ' . ($m['reason'] ?? ''));
    }
}
if ($checkObjects && !empty($missingObj)) {
    logMsg('Missing objects (sample):');
    foreach (array_slice($missingObj, 0, 10) as $m) {
        logMsg('  - ' . ($m['id'] ?? '<no-id>') . ' ' . ($m['bucket'] ?? '') . '/' . ($m['key'] ?? '') . ' http:' . ($m['http_code'] ?? ''));
    }
}

$exit = ($stats['db_missing'] > 0 || ($checkObjects && $stats['obj_missing'] > 0)) ? 2 : 0;
exit($exit);
