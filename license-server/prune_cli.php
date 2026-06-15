<?php
/**
 * CLI entrypoint to run pruneExpiredKeys without HTTP or admin token.
 * Implements file locking to avoid overlapping runs and detects stale locks.
 */
chdir(__DIR__);
require_once __DIR__ . '/jwks_lib.php';

// Ensure the keysDir global is available
global $keysDir;
if (empty($keysDir)) $keysDir = __DIR__ . '/keys';

$lockFile = rtrim($keysDir, "\/") . '/.prune.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false) {
    fwrite(STDERR, "Cannot open lock file: $lockFile\n");
    exit(2);
}

$staleTtl = intval(getenv('LICENSE_PRUNE_LOCK_TTL_SECONDS') ?: 43200);

// Try to acquire non-blocking lock
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // check for stale lock
    $mtime = @filemtime($lockFile) ?: 0;
    if ($mtime > 0 && (time() - $mtime) > $staleTtl) {
        // attempt to remove stale lock and reopen
        @fclose($lockFp);
        @unlink($lockFile);
        $lockFp = @fopen($lockFile, 'c');
        if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "Another prune is running (lock held)\n");
            exit(0);
        }
    } else {
        echo "Another prune is running (lock held). Exiting.\n";
        fclose($lockFp);
        exit(0);
    }
}

// We have the lock. Write PID and timestamp for diagnostics.
ftruncate($lockFp, 0);
fwrite($lockFp, getmypid() . "\n" . date('c') . "\n");
fflush($lockFp);

try {
    $index = getKeysIndex();
    $removed = pruneExpiredKeys($index);
    if (!empty($removed)) {
        echo "Pruned keys: " . json_encode($removed) . PHP_EOL;
    } else {
        echo "No keys to prune" . PHP_EOL;
    }
    // release lock
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Prune failed: " . $e->getMessage() . PHP_EOL);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(2);
}
