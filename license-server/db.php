<?php
// Minimal DB helper for license-server
// Supports Postgres (recommended) and MySQL via DSN or discrete env vars.

function log_server(string $msg): void {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/server.log';
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function try_pdo_connect(string $dsn, string $user, string $pass, array $options): ?PDO {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        log_server("DB connect success: dsn={$dsn} user={$user}");
        return $pdo;
    } catch (Throwable $e) {
        log_server(sprintf('DB connect failed: dsn=%s user=%s err=%s', $dsn, $user, $e->getMessage()));
        return null;
    }
}

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = getenv('LICENSE_DB_DSN') ?: '';
    $user = getenv('LICENSE_DB_USER') ?: '';
    $pass = getenv('LICENSE_DB_PASS') ?: '';

    if (empty($dsn)) {
        $driver = getenv('LICENSE_DB_DRIVER') ?: 'pgsql';
        $host = getenv('LICENSE_DB_HOST') ?: '';
        $port = getenv('LICENSE_DB_PORT') ?: '';
        $name = getenv('LICENSE_DB_NAME') ?: '';

        if (!empty($host) && !empty($name)) {
            if (empty($port)) {
                $port = $driver === 'pgsql' ? '5432' : '3306';
            }
            if ($driver === 'pgsql') {
                $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
            } else {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
            }
        }
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // If a DSN is available via envs, try it first
    if (!empty($dsn)) {
        $pdo = try_pdo_connect($dsn, $user, $pass, $options);
        if ($pdo) return $pdo;
    }
    // Auto-detect local Postgres for development when no explicit DSN provided.
    // If auto-detect is disabled via LICENSE_DB_AUTO_DETECT=0 or 'false', skip this.
    $autoDetect = getenv('LICENSE_DB_AUTO_DETECT');
    if ($autoDetect !== false) {
        $autoDetect = strtolower(trim($autoDetect));
        if (in_array($autoDetect, ['0', 'false', 'no'], true)) {
            log_server('DB auto-detect disabled by LICENSE_DB_AUTO_DETECT');
            return null;
        }
    }

    // Try common credential combinations to connect to a local Postgres instance at 127.0.0.1:5432/licenses
    $defaultDsn = 'pgsql:host=127.0.0.1;port=5432;dbname=licenses';

    $candidates = [];
    // prefer explicit env creds (even if empty)
    $candidates[] = [$user, $pass];
    // PGPASSWORD or LICENSE_DB_PASS
    $pgpass = getenv('PGPASSWORD') ?: getenv('LICENSE_DB_PASS');
    if ($pgpass !== false && $pgpass !== '') {
        $candidates[] = ['postgres', $pgpass];
    }
    // current OS user (often works with trust/peer auth)
    $curUser = getenv('USERNAME') ?: getenv('USER');
    if (!empty($curUser)) {
        $candidates[] = [$curUser, ''];
    }
    // common defaults for dev
    $candidates[] = ['postgres', ''];
    $candidates[] = ['', ''];
    $candidates[] = ['postgres', 'postgres'];

    foreach ($candidates as $cred) {
        $tryUser = $cred[0] ?? '';
        $tryPass = $cred[1] ?? '';
        $pdo = try_pdo_connect($defaultDsn, $tryUser, $tryPass, $options);
        if ($pdo) {
            return $pdo;
        }
    }

    error_log('license-server: no DB connection available (tried env DSN and local defaults)');
    return null;
}

function db_get_license(PDO $pdo, string $key): ?array {
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_create_license(PDO $pdo, string $key, array $features = [], ?int $expires_at = null, ?string $plan = null): bool {
    $features_json = json_encode(array_values($features));
    $meta = [];
    if (!empty($plan)) $meta['plan'] = $plan;
    $meta_json = json_encode($meta);
    $sql = 'INSERT INTO licenses (license_key, status, features, meta, created_at, expires_at) VALUES (:key, :status, :features, :meta, now(), to_timestamp(:exp))';
    $params = [':key' => $key, ':status' => 'active', ':features' => $features_json, ':meta' => $meta_json, ':exp' => $expires_at ?: time()];
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('db_create_license error: ' . $e->getMessage());
        return false;
    }
}

function db_update_license_after_activation(PDO $pdo, string $key, int $exp, array $features = [], ?string $plan = null): bool {
    $features_json = json_encode(array_values($features));
    $sql = 'UPDATE licenses SET expires_at = to_timestamp(:exp), features = :features, status = :status, activated_at = now()';
    $params = [':exp' => $exp, ':features' => $features_json, ':status' => 'active', ':key' => $key];
    if (!is_null($plan)) {
        $sql .= ', meta = :meta';
        $params[':meta'] = json_encode(['plan' => $plan]);
    }
    $sql .= ' WHERE license_key = :key';
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('db_update_license_after_activation error: ' . $e->getMessage());
        return false;
    }
}

function db_revoke_license(PDO $pdo, string $key): bool {
    try {
        $stmt = $pdo->prepare("UPDATE licenses SET status='revoked', revoked_at = now() WHERE license_key = :key");
        return $stmt->execute([':key' => $key]);
    } catch (Throwable $e) {
        error_log('db_revoke_license error: ' . $e->getMessage());
        return false;
    }
}

// Ensure the `jti` column exists on `license_activations` (safe to call repeatedly)
function ensure_jti_column(PDO $pdo): void {
    try {
        $pdo->exec("ALTER TABLE license_activations ADD COLUMN IF NOT EXISTS jti TEXT");
    } catch (Throwable $e) {
        // non-fatal
        error_log('ensure_jti_column error: ' . $e->getMessage());
    }
}

function db_record_activation(PDO $pdo, string $key, ?string $site, ?string $ip, ?string $user_agent, string $event, ?string $jti = null): bool {
    try {
        ensure_jti_column($pdo);
        $sql = 'INSERT INTO license_activations (license_key, site, ip, user_agent, event, jti, created_at) VALUES (:key, :site, :ip, :ua, :event, :jti, now())';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([':key' => $key, ':site' => $site, ':ip' => $ip, ':ua' => $user_agent, ':event' => $event, ':jti' => $jti]);
    } catch (Throwable $e) {
        error_log('db_record_activation error: ' . $e->getMessage());
        return false;
    }
}

function db_get_jtis_for_license(PDO $pdo, string $key): array {
    try {
        ensure_jti_column($pdo);
        $stmt = $pdo->prepare('SELECT jti FROM license_activations WHERE license_key = :key AND jti IS NOT NULL');
        $stmt->execute([':key' => $key]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $jtis = [];
        foreach ($rows as $r) {
            if (!empty($r)) $jtis[] = $r;
        }
        return $jtis;
    } catch (Throwable $e) {
        error_log('db_get_jtis_for_license error: ' . $e->getMessage());
        return [];
    }
}
