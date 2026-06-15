<?php
// Admin audit logger: writes JSON-lines to data/admin_audit.log and optionally inserts into Postgres (LICENSE_DB_DSN or DATABASE_URL).

function _ensure_admin_data_dir(): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function audit_log_admin(string $action, array $details = []): bool {
    $actor = function_exists('admin_get_actor') ? admin_get_actor() : ['type' => 'unknown', 'id' => null];
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $headers = function_exists('get_request_headers') ? get_request_headers() : [];
    $ua = $headers['User-Agent'] ?? $headers['user-agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);

    $entry = [
        'timestamp' => date('c'),
        'action' => $action,
        'actor' => $actor,
        'ip' => $ip,
        'user_agent' => $ua,
        'details' => $details
    ];

    $dir = _ensure_admin_data_dir();
    $file = $dir . '/admin_audit.log';
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    // append to file
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

    // Try to insert into DB if configured
    $dsn = getenv('LICENSE_DB_DSN') ?: getenv('DATABASE_URL');
    if (!empty($dsn)) {
        try {
            // support DATABASE_URL like postgres://user:pass@host:port/dbname
            $pdo = null;
            if (strpos($dsn, 'postgres://') === 0 || strpos($dsn, 'postgresql://') === 0) {
                $parts = parse_url($dsn);
                $host = $parts['host'] ?? 'localhost';
                $port = $parts['port'] ?? 5432;
                $user = $parts['user'] ?? null;
                $pass = $parts['pass'] ?? null;
                $path = $parts['path'] ?? null;
                $dbname = $path ? ltrim($path, '/') : null;
                $pdo_dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
                $pdo = new PDO($pdo_dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } else {
                // assume a PDO DSN is provided
                $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }

            $stmt = $pdo->prepare('INSERT INTO jwks_audit (action, actor, ip, user_agent, payload) VALUES (:action, :actor, :ip, :ua, :payload)');
            $stmt->execute([
                ':action' => $entry['action'],
                ':actor' => json_encode($entry['actor']),
                ':ip' => $entry['ip'],
                ':ua' => $entry['user_agent'],
                ':payload' => json_encode($entry['details'])
            ]);
        } catch (Throwable $e) {
            // ignore DB failures — file log is primary
        }
    }

    return true;
}
