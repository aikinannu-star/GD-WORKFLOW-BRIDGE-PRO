<?php

require_once __DIR__ . '/../execution/PostgresExecutionReportRepository.php';

function usage(): void
{
    $script = basename(__FILE__);
    echo "Usage: php $script [--source=<path>] [--dry-run] [--archive] [--help]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --source=<path>   Directory containing file-backed execution reports.\n";
    echo "                    Defaults to services/assistant/data/execution_reports\n";
    echo "  --dry-run         Validate and report what would be migrated without writing to Postgres.\n";
    echo "  --archive         Rename successfully migrated files to *.migrated.json.\n";
    echo "  --help            Show this help message.\n";
    echo "\n";
    echo "Environment variables:\n";
    echo "  DB_DSN            Optional PDO DSN for Postgres, e.g. pgsql:host=127.0.0.1;port=5432;dbname=gdwb_dev\n";
    echo "  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS\n";
    exit(1);
}

$options = getopt('', ['source:', 'dry-run', 'archive', 'help']);
if (isset($options['help'])) {
    usage();
}

$sourceDir = rtrim($options['source'] ?? __DIR__ . '/../data/execution_reports', DIRECTORY_SEPARATOR);
$dryRun = isset($options['dry-run']);
$archive = isset($options['archive']);

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Source directory not found: $sourceDir\n");
    exit(1);
}

$dbDsn = getenv('DB_DSN') ?: null;
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'gdwb_dev';
$dbUser = getenv('DB_USER') ?: 'gdwb';
$dbPass = getenv('DB_PASS') ?: 'gdwb';

if (!$dbDsn) {
    $dbDsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);
}

try {
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Failed to connect to Postgres: " . $e->getMessage() . "\n");
    exit(1);
}

$repo = new PostgresExecutionReportRepository($pdo);

$files = glob($sourceDir . '/*.json');
if ($files === false) {
    fwrite(STDERR, "Failed to scan source directory: $sourceDir\n");
    exit(1);
}

$reportFiles = array_filter($files, fn(string $file) => !str_ends_with($file, '.migrated.json'));
if (count($reportFiles) === 0) {
    echo "No JSON execution report files found in $sourceDir\n";
    exit(0);
}

$total = 0;
$success = 0;
$skipped = 0;
$failed = 0;

function loadJsonFile(string $path): ?array
{
    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

function extractExecutionId(array $report, string $path): ?string
{
    if (!empty($report['executionId'])) {
        return (string)$report['executionId'];
    }
    if (!empty($report['metadata']['execution_id'])) {
        return (string)$report['metadata']['execution_id'];
    }

    if (preg_match('/([A-Za-z0-9_\-]+)\.partial\.json$/', basename($path), $matches)) {
        return $matches[1];
    }
    if (preg_match('/([A-Za-z0-9_\-]+)\.json$/', basename($path), $matches)) {
        return $matches[1];
    }

    return null;
}

function archiveFile(string $path): bool
{
    $target = $path . '.migrated';
    return rename($path, $target);
}

foreach ($reportFiles as $file) {
    $total++;
    $relative = substr($file, strlen($sourceDir) + 1);
    $json = loadJsonFile($file);
    if ($json === null) {
        fwrite(STDERR, "Skipping invalid JSON file: $relative\n");
        $failed++;
        continue;
    }

    $executionId = extractExecutionId($json, $file);
    if (!$executionId) {
        fwrite(STDERR, "Skipping report without execution_id: $relative\n");
        $failed++;
        continue;
    }

    $isPartial = str_ends_with($file, '.partial.json');
    $action = $isPartial ? 'savePartial' : 'save';
    $description = $isPartial ? 'partial report' : 'full report';

    echo sprintf("Processing %s (%s): %s\n", $executionId, $description, $relative);

    if ($dryRun) {
        $success++;
        continue;
    }

    $result = false;
    if ($isPartial) {
        $result = $repo->savePartial($executionId, $json);
    } else {
        $result = $repo->save($json);
    }

    if (!$result) {
        fwrite(STDERR, "Failed to migrate $relative to Postgres\n");
        $failed++;
        continue;
    }

    $success++;
    if ($archive) {
        if (!archiveFile($file)) {
            fwrite(STDERR, "Warning: could not archive migrated file $relative\n");
        }
    }
}

echo "\nMigration summary:\n";
echo "  total scanned: $total\n";
echo "  migrated:     $success\n";
echo "  skipped:      $skipped\n";
echo "  failed:       $failed\n";

exit($failed > 0 ? 1 : 0);
