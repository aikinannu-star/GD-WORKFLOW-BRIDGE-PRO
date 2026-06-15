<?php
// migrate_users_to_db.php
// Simple script to migrate license-server data/users.json into the database configured by LICENSE_DB_DSN

$usersDataPath = getenv('LICENSE_USERS_DATA_PATH') ?: __DIR__ . '/data/users.json';
$licenseDsn = getenv('LICENSE_DB_DSN') ?: '';
$dbUser = getenv('LICENSE_DB_USER') ?: null;
$dbPass = getenv('LICENSE_DB_PASSWORD') ?: null;

if (empty($licenseDsn)) {
    fwrite(STDERR, "LICENSE_DB_DSN not configured. Set LICENSE_DB_DSN to a valid PDO DSN for Postgres (eg. pgsql:host=127.0.0.1;port=5432;dbname=gdwb_app)\n");
    exit(1);
}

if (!file_exists($usersDataPath)) {
    fwrite(STDERR, "No users data file found at $usersDataPath. Nothing to migrate.\n");
    exit(0);
}

$json = @file_get_contents($usersDataPath);
$users = $json ? json_decode($json, true) : [];
if (!is_array($users) || empty($users)) {
    fwrite(STDOUT, "No users to migrate (empty or invalid JSON).\n");
    exit(0);
}

try {
    $pdo = new PDO($licenseDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Ensure users table exists and has the expected columns.
    // If the table already exists but uses a different schema (e.g., app's users table),
    // add `password_hash`, `created_at`, and `updated_at` columns when missing and copy values.
    $colsStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND table_schema = current_schema()");
    $colsStmt->execute();
    $cols = array_map('strtolower', $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0));
    if (empty($cols)) {
        // Table does not exist — create canonical license-server users table
        $pdo->exec("CREATE TABLE users (
            email TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE,
            updated_at TIMESTAMP WITH TIME ZONE
        )");
        fwrite(STDOUT, "Created users table\n");
    } else {
        // Ensure password_hash column exists
        if (!in_array('password_hash', $cols)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN password_hash TEXT');
            fwrite(STDOUT, "Added password_hash column\n");
            // If table already had a `password` column, copy values into `password_hash`
            if (in_array('password', $cols)) {
                $pdo->exec('UPDATE users SET password_hash = password WHERE password IS NOT NULL');
                fwrite(STDOUT, "Copied existing password -> password_hash\n");
            }
        }
        // Ensure timestamps exist
        if (!in_array('created_at', $cols)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN created_at TIMESTAMP WITH TIME ZONE');
            fwrite(STDOUT, "Added created_at column\n");
        }
        if (!in_array('updated_at', $cols)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN updated_at TIMESTAMP WITH TIME ZONE');
            fwrite(STDOUT, "Added updated_at column\n");
        }
    }

    // Build INSERT statement dynamically to match existing users table schema
    $hasId = in_array('id', $cols);
    $colsToInsert = [];
    if ($hasId) $colsToInsert[] = 'id';
    $colsToInsert[] = 'email';
    // include legacy `password` column if present (some schemas require it NOT NULL)
    $includePasswordCol = in_array('password', $cols);
    if ($includePasswordCol) $colsToInsert[] = 'password';
    // we ensured password_hash exists earlier
    $colsToInsert[] = 'password_hash';
    if (in_array('first_name', $cols)) $colsToInsert[] = 'first_name';
    if (in_array('tenant_id', $cols)) $colsToInsert[] = 'tenant_id';
    if (in_array('created_at', $cols)) $colsToInsert[] = 'created_at';
    if (in_array('updated_at', $cols)) $colsToInsert[] = 'updated_at';

    $placeholders = array_map(function($c){ return ':' . $c; }, $colsToInsert);
    $sql = 'INSERT INTO users(' . implode(',', $colsToInsert) . ') VALUES(' . implode(',', $placeholders) . ') ON CONFLICT (email) DO NOTHING';
    $insertStmt = $pdo->prepare($sql);

    // helper to generate UUID v4
    function generate_uuid_v4() {
        try {
            $data = random_bytes(16);
        } catch (Exception $e) {
            $data = openssl_random_pseudo_bytes(16);
        }
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data),4));
    }

    $count = 0;
    foreach ($users as $email => $u) {
        $emailKey = $u['email'] ?? $email;
        $passwordHash = $u['password_hash'] ?? ($u['password'] ?? null);
        if (empty($emailKey) || empty($passwordHash)) continue;

        $params = [];
        foreach ($colsToInsert as $col) {
            switch ($col) {
                case 'id':
                    $params[':id'] = generate_uuid_v4();
                    break;
                case 'email':
                    $params[':email'] = $emailKey;
                    break;
                case 'password':
                    // populate legacy password column with the same bcrypt hash
                    $params[':password'] = $passwordHash;
                    break;
                case 'password_hash':
                    $params[':password_hash'] = $passwordHash;
                    break;
                case 'first_name':
                    $params[':first_name'] = $u['first_name'] ?? null;
                    break;
                case 'tenant_id':
                    $params[':tenant_id'] = $u['tenant_id'] ?? null;
                    break;
                case 'created_at':
                    $params[':created_at'] = $u['created_at'] ?? null;
                    break;
                case 'updated_at':
                    $params[':updated_at'] = $u['updated_at'] ?? null;
                    break;
                default:
                    $params[':' . $col] = $u[$col] ?? null;
            }
        }

        try {
            $insertStmt->execute($params);
            $count += ($insertStmt->rowCount() > 0) ? 1 : 0;
        } catch (Throwable $e) {
            // Non-fatal for single rows — log and continue
            fwrite(STDERR, "Row insert failed for {$emailKey}: " . $e->getMessage() . "\n");
        }
    }

    fwrite(STDOUT, "Migrated $count users into the database.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
