<?php
// Redis helper with file-backed fallback for JTI blacklist

function redis_connect() {
    static $client = null;
    if ($client !== null) return $client;

    $host = getenv('REDIS_HOST') ?: getenv('LICENSE_REDIS_HOST') ?: '127.0.0.1';
    $port = getenv('REDIS_PORT') ?: getenv('LICENSE_REDIS_PORT') ?: 6379;
    $pass = getenv('REDIS_PASS') ?: getenv('LICENSE_REDIS_PASS') ?: '';
    $db = getenv('REDIS_DB') ?: 0;

    if (class_exists('Redis')) {
        try {
            $r = new Redis();
            $ok = $r->connect($host, (int)$port, 1.5);
            if ($ok && !empty($pass)) {
                @ $r->auth($pass);
            }
            if ($ok && $db) $r->select((int)$db);
            $client = $r;
            return $client;
        } catch (Throwable $e) {
            error_log('redis_connect failed: ' . $e->getMessage());
            $client = null;
        }
    }

    // no Redis extension — return null to allow file fallback
    return null;
}

function redis_blacklist_add(string $jti, int $ttlSeconds): bool {
    if (empty($jti) || $ttlSeconds <= 0) return false;
    $r = redis_connect();
    if ($r instanceof Redis) {
        try {
            $key = 'jti:' . $jti;
            if ($ttlSeconds > 0) $r->set($key, 1, $ttlSeconds);
            else $r->set($key, 1);
            // Publish revocation event for listeners
            try {
                $payload = json_encode(['jti' => $jti, 'prefix' => 'jti', 'expires_at' => time() + $ttlSeconds]);
                @$r->publish('jti_revocations', $payload);
            } catch (Throwable $e) { /* ignore publish failures */ }
            return true;
        } catch (Throwable $e) {
            error_log('redis_blacklist_add error: ' . $e->getMessage());
        }
    }

    // File-backed fallback
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/jti_blacklist.json';
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    $data[$jti] = time() + $ttlSeconds;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    return true;
}

function redis_blacklist_check(string $jti): bool {
    if (empty($jti)) return false;
    $r = redis_connect();
    if ($r instanceof Redis) {
        try {
            $key = 'jti:' . $jti;
            $ex = $r->exists($key);
            return $ex === 1;
        } catch (Throwable $e) {
            error_log('redis_blacklist_check error: ' . $e->getMessage());
        }
    }

    $file = __DIR__ . '/data/jti_blacklist.json';
    if (!file_exists($file)) return false;
    $data = json_decode(file_get_contents($file), true) ?: [];
    if (empty($data[$jti])) return false;
    if ($data[$jti] < time()) {
        // expired — prune
        unset($data[$jti]);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        return false;
    }
    return true;
}

// Publish a revocation event to Redis channel `jti_revocations` when possible.
function redis_publish_revocation(string $jti, int $ttlSeconds = 0, string $prefix = 'jti'): bool {
    if (empty($jti)) return false;
    $r = redis_connect();
    if (!($r instanceof Redis)) return false;
    try {
        $payload = ['jti' => $jti, 'prefix' => $prefix];
        if ($ttlSeconds > 0) $payload['expires_at'] = time() + $ttlSeconds;
        $r->publish('jti_revocations', json_encode($payload));
        return true;
    } catch (Throwable $e) {
        error_log('redis_publish_revocation error: ' . $e->getMessage());
        return false;
    }
}
