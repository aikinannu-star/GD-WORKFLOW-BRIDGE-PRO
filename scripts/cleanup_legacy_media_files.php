<?php
/**
 * scripts/cleanup_legacy_media_files.php
 *
 * Usage:
 *   php scripts/cleanup_legacy_media_files.php [--dry-run] [--force] [--apply] [--backup] [--limit=N]
 *
 * - --dry-run : shows what would be changed without writing files
 * - --force   : remove base64 content from all records regardless of DB presence
 * - --apply   : overwrite the original services/data/media_files.json with cleaned data
 * - --backup  : when used with --apply, write a backup of the original file
 * - --limit=N : process only first N records
 *
 * Behavior:
 * - Loads legacy records from services/data/media_files.json
 * - If a record's `id` exists in the DB `media` table, removes `content_b64`
 *   (or removes for all records if `--force`)
 * - Writes `services/data/media_files_cleaned.json` with cleaned records
 * - When `--apply` is provided, optionally writes a timestamped backup and
 *   replaces the original `files.json` with the cleaned records.
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../services/lib/ServiceHelpers.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/DB.php';

$argv = $_SERVER['argv'];
$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$apply = in_array('--apply', $argv, true);
$backup = in_array('--backup', $argv, true);
$limit = null;
foreach ($argv as $a) {
    if (strpos($a, '--limit=') === 0) $limit = (int) substr($a, 8);
}

function logMsg(string $m): void { echo '[' . date('c') . '] ' . $m . PHP_EOL; }

$records = ServiceHelpers::loadJson('media', 'files.json');
if (!is_array($records) || count($records) === 0) {
    logMsg('No legacy media records found (services/data/media_files.json)');
    exit(0);
}

try {
    $pdo = \GDWB\DB::getPDO();
    $dbOk = true;
} catch (Exception $e) {
    $dbOk = false;
    logMsg('DB not available: ' . $e->getMessage());
}

$checkStmt = $dbOk ? $pdo->prepare('SELECT id FROM media WHERE id = :id') : null;

function normalizeId(string $raw): string {
    $hex = preg_replace('/[^0-9a-f]/i', '', $raw);
    if (strlen($hex) === 32) {
        return substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20,12);
    }
    return $raw;
}

$cleaned = [];
$stats = ['total' => 0, 'removed' => 0, 'kept' => 0];

foreach ($records as $i => $rec) {
    if ($limit !== null && $stats['total'] >= $limit) break;
    $stats['total']++;

    $idRaw = $rec['id'] ?? '';
    $id = $idRaw !== '' ? normalizeId($idRaw) : '';

    $shouldRemove = false;
    if ($force) {
        $shouldRemove = true;
    } elseif ($dbOk && $id !== '') {
        try {
            $checkStmt->execute([':id' => $id]);
            if ($checkStmt->fetch()) $shouldRemove = true;
        } catch (Exception $e) {
            // if DB query fails, do not remove unless forced
            $shouldRemove = false;
        }
    }

    if ($shouldRemove && array_key_exists('content_b64', $rec)) {
        unset($rec['content_b64']);
        $rec['cleaned_at'] = date('c');
        $stats['removed']++;
    } else {
        $stats['kept']++;
    }

    $cleaned[] = $rec;
}

logMsg('Processing complete. Total: ' . $stats['total'] . ', removed: ' . $stats['removed'] . ', kept: ' . $stats['kept']);

$cleanedFilename = 'files_cleaned.json';
if ($dryRun) {
    logMsg('Dry-run: not writing files.');
    // show small sample
    logMsg('Sample cleaned record:');
    echo json_encode($cleaned[array_key_first($cleaned)] ?? [], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

// write cleaned file
$ok = ServiceHelpers::saveJson('media', $cleanedFilename, $cleaned);
if ($ok) logMsg("Wrote cleaned file: services/data/media_{$cleanedFilename}");
else logMsg('Failed to write cleaned file.');

if ($apply) {
    if ($backup) {
        $bakName = 'files_backup_' . date('Ymd_His') . '.json';
        ServiceHelpers::saveJson('media', $bakName, $records);
        logMsg("Wrote backup: services/data/media_{$bakName}");
    }
    // overwrite original files.json
    $applied = ServiceHelpers::saveJson('media', 'files.json', $cleaned);
    if ($applied) logMsg('Applied cleaned data to services/data/media_files.json');
    else logMsg('Failed to apply cleaned data to original file');
}

exit(0);
