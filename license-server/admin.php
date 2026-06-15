<?php
// Admin helper: authorization methods and simple file-backed rate-limiting.

function get_request_headers(): array {
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $h = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$h] = $value;
        }
    }
    return $headers;
}

function get_client_ip(): string {
    $headers = get_request_headers();
    if (!empty($headers['X-Forwarded-For'])) {
        $parts = explode(',', $headers['X-Forwarded-For']);
        return trim($parts[0]);
    }
    if (!empty($headers['X-Real-IP'])) return $headers['X-Real-IP'];
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function load_admin_token(): ?string {
    // Prefer environment variable for admin token (safer in CI/containers)
    $env = getenv('LICENSE_ADMIN_TOKEN');
    if ($env !== false && $env !== '') return trim($env);
    $env2 = getenv('ADMIN_TOKEN');
    if ($env2 !== false && $env2 !== '') return trim($env2);

    $path = getenv('LICENSE_ADMIN_TOKEN_PATH') ?: __DIR__ . '/keys/admin_token.txt';
    if (!file_exists($path)) return null;
    return trim(file_get_contents($path));
}

function load_admin_credentials(): ?array {
    $path = __DIR__ . '/keys/admin_credentials.json';
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || empty($data['username']) || empty($data['password_hash'])) return null;
    return $data;
}

function load_admin_tokens(): ?array {
    $path = __DIR__ . '/keys/admin_tokens.json';
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return null;
    return $data;
}

// Optional: IP allowlist for admin access. Comma-separated list of IPs or CIDR ranges.
function cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return false;
    list($subnet, $mask) = explode('/', $cidr, 2);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    if ($ip_long === false || $subnet_long === false) return false;
    $mask = intval($mask);
    if ($mask < 0 || $mask > 32) return false;
    $mask_long = ($mask === 0) ? 0 : ((~0 << (32 - $mask)) & 0xFFFFFFFF);
    return (($ip_long & $mask_long) === ($subnet_long & $mask_long));
}

function admin_ip_allowed(): bool {
    $allow = getenv('LICENSE_ADMIN_IP_ALLOWLIST') ?: getenv('ADMIN_IP_ALLOWLIST') ?: '';
    if (trim($allow) === '') return true;
    $parts = array_map('trim', explode(',', $allow));
    $ip = get_client_ip();
    foreach ($parts as $p) {
        if ($p === '') continue;
        if ($p === $ip) return true;
        if (strpos($p, '/') !== false && cidr_match($ip, $p)) return true;
        // try DNS name match
        if (!filter_var($p, FILTER_VALIDATE_IP)) {
            $resolved = gethostbyname($p);
            if ($resolved && $resolved === $ip) return true;
        }
    }
    return false;
}

function admin_get_actor(): array {
    $headers = get_request_headers();
    $actor = ['type' => 'unknown', 'id' => null, 'user' => null];

    // Authorization header
    if (!empty($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            // check token store first
            $tokens = load_admin_tokens();
            if (is_array($tokens)) {
                foreach ($tokens as $entry) {
                    if (!empty($entry['password_hash']) && password_verify($token, $entry['password_hash'])) {
                        $actor['type'] = 'token_store';
                        $actor['id'] = $entry['id'] ?? null;
                        return $actor;
                    }
                    if (!empty($entry['token']) && hash_equals($entry['token'], $token)) {
                        $actor['type'] = 'token_store';
                        $actor['id'] = $entry['id'] ?? null;
                        return $actor;
                    }
                }
            }
            // fallback to env token
            $env = load_admin_token();
            if (!empty($env) && hash_equals($env, $token)) {
                $actor['type'] = 'env_token';
                $actor['id'] = 'env';
                return $actor;
            }
            // unknown bearer token
            $actor['type'] = 'bearer';
            $actor['id'] = substr($token, 0, 8);
            return $actor;
        }
        if (stripos($auth, 'Basic ') === 0) {
            $b64 = substr($auth, 6);
            $decoded = base64_decode($b64);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2) {
                    $actor['type'] = 'basic';
                    $actor['user'] = $parts[0];
                    $actor['id'] = $parts[0];
                    return $actor;
                }
            }
        }
    }

    // X-Admin-Token header
    if (!empty($headers['X-Admin-Token'])) {
        $token = trim($headers['X-Admin-Token']);
        $env = load_admin_token();
        if (!empty($env) && hash_equals($env, $token)) {
            $actor['type'] = 'env_token';
            $actor['id'] = 'env';
            return $actor;
        }
        $actor['type'] = 'x_admin_token';
        $actor['id'] = substr($token, 0, 8);
        return $actor;
    }

    return $actor;
}

function _keys_index_file(): string {
    $keysDir = __DIR__ . '/keys';
    return $keysDir . '/keys_index.json';
}

function _current_kid(): ?string {
    $f = _keys_index_file();
    if (!file_exists($f)) return null;
    $idx = json_decode(file_get_contents($f), true);
    if (!is_array($idx)) return null;
    return $idx['current_kid'] ?? null;
}

function admin_issue_jwt(array $scopes = [], int $exp_seconds = 900, string $sub = 'admin'): string {
    $privatePath = getenv('LICENSE_ADMIN_JWT_PRIVATE_KEY_PATH') ?: (__DIR__ . '/keys/private.pem');
    if (!file_exists($privatePath)) throw new Exception('private key for admin JWT missing');
    $priv = file_get_contents($privatePath);
    $kid = _current_kid();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($kid)) $header['kid'] = $kid;

    $now = time();
    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $sub,
        'iat' => $now,
        'exp' => $now + $exp_seconds,
        'scopes' => array_values($scopes),
        'jti' => bin2hex(random_bytes(8))
    ];

    $hb = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $pb = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signed = $hb . '.' . $pb;

    $pkey = @openssl_pkey_get_private($priv);
    if ($pkey === false) throw new Exception('failed to load private key');
    $sig = '';
    if (!openssl_sign($signed, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new Exception('failed to sign admin jwt');
    }
    $sigb = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    return $signed . '.' . $sigb;
}

function admin_verify_jwt(string $jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($hb64, $pb64, $sig64) = $parts;
    $header = json_decode(base64_decode(strtr($hb64, '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($pb64, '-_', '+/')), true);
    $sig = base64_decode(strtr($sig64, '-_', '+/'));
    if (!is_array($header) || !is_array($payload) || $sig === false) return false;

    $kid = $header['kid'] ?? null;
    $pubPath = null;
    if (!empty($kid)) {
        $p = __DIR__ . '/keys/public_' . $kid . '.pem';
        if (file_exists($p)) $pubPath = $p;
    }
    if (empty($pubPath)) {
        $p2 = __DIR__ . '/keys/public.pem';
        if (file_exists($p2)) $pubPath = $p2;
    }
    if (empty($pubPath)) return false;
    $pub = file_get_contents($pubPath);
    if ($pub === false) return false;

    $verify = openssl_verify($hb64 . '.' . $pb64, $sig, $pub, OPENSSL_ALGO_SHA256);
    if ($verify !== 1) return false;

    $now = time();
    if (isset($payload['exp']) && $payload['exp'] < $now) return false;
    // Check blacklist for JWT jti if present
    if (!empty($payload['jti']) && function_exists('admin_blacklist_check')) {
        if (admin_blacklist_check($payload['jti'])) return false;
    }
    return $payload;
}

function _admin_blacklist_file(): string {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/admin_jwt_blacklist.json';
}

function admin_blacklist_add(string $jti, ?int $expires_at = null, string $reason = '', ?array $actor = null): bool {
    $file = _admin_blacklist_file();
    $data = [];
    if (file_exists($file)) {
        $contents = @file_get_contents($file);
        $data = json_decode($contents, true) ?: [];
    }
    $data[$jti] = [
        'jti' => $jti,
        'expires_at' => $expires_at ? date('c', $expires_at) : null,
        'reason' => $reason,
        'actor' => $actor,
        'created_at' => date('c')
    ];
    @file_put_contents($file, json_encode($data), LOCK_EX);

    // Try DB insert if configured
    $dsn = getenv('LICENSE_DB_DSN') ?: getenv('DATABASE_URL');
    if (!empty($dsn)) {
        try {
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
                $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
            $stmt = $pdo->prepare('INSERT INTO admin_jwt_revocations (jti, reason, actor, expires_at) VALUES (:jti, :reason, :actor, :expires_at)');
            $stmt->execute([
                ':jti' => $jti,
                ':reason' => $reason,
                ':actor' => $actor ? json_encode($actor) : null,
                ':expires_at' => $expires_at ? date('c', $expires_at) : null
            ]);
        } catch (Throwable $e) {
            // ignore DB failures
        }
    }

    // Try Redis for fast lookup if available
    if (function_exists('redis_connect')) {
        try {
            $r = redis_connect();
            if ($r instanceof Redis) {
                $ttl = $expires_at ? max(1, $expires_at - time()) : 0;
                $key = 'admin_jti:' . $jti;
                if ($ttl > 0) $r->set($key, json_encode(['reason'=>$reason,'actor'=>$actor]), $ttl);
                else $r->set($key, json_encode(['reason'=>$reason,'actor'=>$actor]));
                // Publish revocation event for listeners
                try {
                    if (function_exists('redis_publish_revocation')) {
                        redis_publish_revocation($jti, $ttl, 'admin_jti');
                    } else {
                        $payload = ['jti' => $jti, 'prefix' => 'admin_jti'];
                        if ($ttl > 0) $payload['expires_at'] = time() + $ttl;
                        @$r->publish('jti_revocations', json_encode($payload));
                    }
                } catch (Throwable $e) { /* ignore publish errors */ }
            }
        } catch (Throwable $e) {
            // ignore redis failures
        }
    }

    return true;
}

function admin_blacklist_check(string $jti): bool {
    // Check Redis first if available
    if (function_exists('redis_connect')) {
        try {
            $r = redis_connect();
            if ($r instanceof Redis) {
                $key = 'admin_jti:' . $jti;
                $exists = $r->exists($key);
                if ($exists === 1) return true;
            }
        } catch (Throwable $e) {
            // ignore redis failures
        }
    }

    // Check file-backed blacklist first
    $file = _admin_blacklist_file();
    if (file_exists($file)) {
        $contents = @file_get_contents($file);
        $data = json_decode($contents, true) ?: [];
        if (!empty($data[$jti])) {
            $entry = $data[$jti];
            if (empty($entry['expires_at'])) return true;
            if (strtotime($entry['expires_at']) > time()) return true;
        }
    }

    // Check DB if available
    $dsn = getenv('LICENSE_DB_DSN') ?: getenv('DATABASE_URL');
    if (!empty($dsn)) {
        try {
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
                $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
            $stmt = $pdo->prepare('SELECT expires_at FROM admin_jwt_revocations WHERE jti = :jti ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([':jti' => $jti]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (empty($row['expires_at'])) return true;
                if (strtotime($row['expires_at']) > time()) return true;
            }
        } catch (Throwable $e) {
            // ignore DB failures
        }
    }

    return false;
}

function admin_authorize(array $required_scopes = []): bool {
    // Enforce IP allowlist if configured
    if (!admin_ip_allowed()) return false;

    // legacy authorization (env token, basic, x-admin-token, token store)
    if (admin_is_authorized()) return true;

    // check for bearer JWT
    $headers = get_request_headers();
    if (!empty($headers['Authorization']) && stripos($headers['Authorization'], 'Bearer ') === 0) {
        $token = trim(substr($headers['Authorization'], 7));
        if (strpos($token, '.') !== false) {
            $payload = admin_verify_jwt($token);
            if ($payload === false) return false;
            if (!empty($required_scopes)) {
                $sc = $payload['scopes'] ?? [];
                if (is_string($sc)) $sc = preg_split('/\s+/', $sc);
                foreach ($required_scopes as $rs) {
                    if (!in_array($rs, $sc, true)) return false;
                }
            }
            return true;
        }
    }

    return false;
}

function admin_is_authorized(array $input = []): bool {
    // Enforce IP allowlist if configured
    if (!admin_ip_allowed()) return false;

    $headers = get_request_headers();

    // Authorization header
    if (!empty($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            // Check token store first (hashed or raw tokens)
            $tokens = load_admin_tokens();
            if (is_array($tokens)) {
                foreach ($tokens as $entry) {
                    if (!empty($entry['password_hash']) && password_verify($token, $entry['password_hash'])) return true;
                    if (!empty($entry['token']) && hash_equals($entry['token'], $token)) return true;
                }
            }
            // fallback to single env token
            $expected = load_admin_token();
            if (!empty($expected) && hash_equals($expected, $token)) return true;
        }
        if (stripos($auth, 'Basic ') === 0) {
            $b64 = substr($auth, 6);
            $decoded = base64_decode($b64);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2) {
                    $user = $parts[0];
                    $pass = $parts[1];
                    $creds = load_admin_credentials();
                    if ($creds && $user === $creds['username'] && password_verify($pass, $creds['password_hash'])) {
                        return true;
                    }
                }
            }
        }
    }

    // X-Admin-Token header
    if (!empty($headers['X-Admin-Token'])) {
        $token = trim($headers['X-Admin-Token']);
        $tokens = load_admin_tokens();
        if (is_array($tokens)) {
            foreach ($tokens as $entry) {
                if (!empty($entry['password_hash']) && password_verify($token, $entry['password_hash'])) return true;
                if (!empty($entry['token']) && hash_equals($entry['token'], $token)) return true;
            }
        }
        $expected = load_admin_token();
        if (!empty($expected) && hash_equals($expected, $token)) return true;
    }

    // Body fallback: admin_secret (legacy)
    if (!empty($input['admin_secret'])) {
        $expected = file_exists(__DIR__ . '/keys/admin_secret.txt') ? trim(file_get_contents(__DIR__ . '/keys/admin_secret.txt')) : '';
        if (!empty($expected) && hash_equals($expected, trim($input['admin_secret']))) return true;
    }

    return false;
}

function rate_limit_allow(string $ip): bool {
    $limitEnv = getenv('ADMIN_RATE_LIMIT_PER_MIN');
    $limit = is_numeric($limitEnv) ? (int)$limitEnv : 5;
    $window = 60; // seconds

    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/rate_limits.json';
    if (!file_exists($file)) file_put_contents($file, json_encode(new stdClass()));

    $fp = @fopen($file, 'c+');
    if ($fp === false) return true; // can't enforce, allow
    flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    $data = json_decode($contents, true);
    if (!is_array($data)) $data = [];

    $now = time();
    if (empty($data[$ip]) || !is_array($data[$ip])) $data[$ip] = [];
    // prune
    $data[$ip] = array_values(array_filter($data[$ip], function($t) use ($now, $window) { return ($now - $t) < $window; }));

    if (count($data[$ip]) >= $limit) {
        // write back pruned list
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $data[$ip][] = $now;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
