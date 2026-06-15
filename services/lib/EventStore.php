<?php
/**
 * EventStore
 * Provides a Postgres-backed event store with JSONB fields and a file-based fallback.
 */

class EventStore
{
    private $pdo = null;
    private $enabled = false;

    public function __construct()
    {
        $dsn = $_ENV['EVENTS_DSN'] ?? null;
        $user = $_ENV['PGUSER'] ?? $_ENV['DB_USER'] ?? null;
        $pass = $_ENV['PGPASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? null;

        if (!$dsn) {
            $host = $_ENV['PGHOST'] ?? null;
            $port = $_ENV['PGPORT'] ?? '5432';
            $db = $_ENV['PGDATABASE'] ?? null;
            if ($host && $db) {
                $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            }
        }

        if ($dsn) {
            try {
                $this->pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $this->enabled = true;
            } catch (PDOException $e) {
                $this->pdo = null;
                $this->enabled = false;
            }
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && ($this->pdo instanceof PDO);
    }

    public function ensureSchema(): void
    {
        if (!$this->isEnabled()) return;
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS billing_events (
  event_key TEXT PRIMARY KEY,
  provider TEXT,
  event_id TEXT,
  reference TEXT,
  license_key TEXT,
  metadata JSONB,
  raw JSONB,
  status TEXT,
  attempts INTEGER DEFAULT 0,
  created_at TIMESTAMPTZ DEFAULT now(),
  last_attempt_at TIMESTAMPTZ,
  processed_at TIMESTAMPTZ,
  next_retry_at TIMESTAMPTZ
);
SQL;
        $this->pdo->exec($sql);
    }

    public function allAsAssocByKey(): array
    {
        if (!$this->isEnabled()) return [];
        $stmt = $this->pdo->query('SELECT * FROM billing_events ORDER BY created_at DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : [];
            $r['raw'] = $r['raw'] ? json_decode($r['raw'], true) : [];
            $out[$r['event_key']] = $r;
        }
        return $out;
    }

    public function getEvent(string $key): ?array
    {
        if (!$this->isEnabled()) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM billing_events WHERE event_key = :k');
        $stmt->execute([':k' => $key]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : [];
        $r['raw'] = $r['raw'] ? json_decode($r['raw'], true) : [];
        return $r;
    }

    public function saveEvent(string $key, array $data): bool
    {
        if (!$this->isEnabled()) return false;

        $sql = <<<SQL
INSERT INTO billing_events (event_key, provider, event_id, reference, license_key, metadata, raw, status, attempts, created_at, last_attempt_at, processed_at, next_retry_at)
VALUES (:event_key, :provider, :event_id, :reference, :license_key, :metadata::jsonb, :raw::jsonb, :status, :attempts, :created_at, :last_attempt_at, :processed_at, :next_retry_at)
ON CONFLICT (event_key) DO UPDATE SET
  provider = EXCLUDED.provider,
  event_id = EXCLUDED.event_id,
  reference = EXCLUDED.reference,
  license_key = EXCLUDED.license_key,
  metadata = EXCLUDED.metadata,
  raw = EXCLUDED.raw,
  status = EXCLUDED.status,
  attempts = EXCLUDED.attempts,
  last_attempt_at = EXCLUDED.last_attempt_at,
  processed_at = EXCLUDED.processed_at,
  next_retry_at = EXCLUDED.next_retry_at;
SQL;
        $stmt = $this->pdo->prepare($sql);

        $meta = isset($data['metadata']) ? json_encode($data['metadata']) : json_encode(new stdClass());
        $raw = isset($data['raw']) ? json_encode($data['raw']) : json_encode(new stdClass());

        $params = [
            ':event_key' => $key,
            ':provider' => $data['provider'] ?? null,
            ':event_id' => $data['event_id'] ?? null,
            ':reference' => $data['reference'] ?? null,
            ':license_key' => $data['license_key'] ?? null,
            ':metadata' => $meta,
            ':raw' => $raw,
            ':status' => $data['status'] ?? null,
            ':attempts' => intval($data['attempts'] ?? 0),
            ':created_at' => $data['created_at'] ?? gmdate('c'),
            ':last_attempt_at' => $data['last_attempt_at'] ?? null,
            ':processed_at' => $data['processed_at'] ?? null,
            ':next_retry_at' => $data['next_retry_at'] ?? null,
        ];

        return $stmt->execute($params);
    }

    public function saveAll(array $events): bool
    {
        if (!$this->isEnabled()) return false;
        $this->pdo->beginTransaction();
        try {
            foreach ($events as $k => $ev) {
                $this->saveEvent($k, $ev);
            }
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return false;
        }
    }

    public function fetchPending(int $limit = 50): array
    {
        if (!$this->isEnabled()) return [];
        $sql = "SELECT * FROM billing_events WHERE ((status IN ('failed','pending') AND (next_retry_at IS NULL OR next_retry_at <= now())) OR (status='processing' AND (last_attempt_at IS NULL OR last_attempt_at <= now() - INTERVAL '5 minutes'))) ORDER BY next_retry_at NULLS FIRST, created_at ASC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : [];
            $r['raw'] = $r['raw'] ? json_decode($r['raw'], true) : [];
        }
        return $rows;
    }
}
