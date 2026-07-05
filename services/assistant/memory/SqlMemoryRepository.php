<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/MemoryRecord.php';

class SqlMemoryRepository implements MemoryRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    public function save(MemoryRecord $record): MemoryRecord
    {
        if ($record->id === null) {
            $record->id = uniqid('memory_', true);
        }

        $stmt = $this->pdo->prepare('INSERT INTO assistant_memories (id, tenant_id, user_id, conversation_id, type, content, confidence, tags, source_messages, created_at, last_confirmed_at, expires_at, metadata) VALUES (:id, :tenant_id, :user_id, :conversation_id, :type, :content, :confidence, :tags, :source_messages, :created_at, :last_confirmed_at, :expires_at, :metadata) ON CONFLICT(id) DO UPDATE SET tenant_id = EXCLUDED.tenant_id, user_id = EXCLUDED.user_id, conversation_id = EXCLUDED.conversation_id, type = EXCLUDED.type, content = EXCLUDED.content, confidence = EXCLUDED.confidence, tags = EXCLUDED.tags, source_messages = EXCLUDED.source_messages, last_confirmed_at = EXCLUDED.last_confirmed_at, expires_at = EXCLUDED.expires_at, metadata = EXCLUDED.metadata');
        $stmt->execute([
            ':id' => $record->id,
            ':tenant_id' => $record->tenantId,
            ':user_id' => $record->userId,
            ':conversation_id' => $record->conversationId,
            ':type' => $record->type,
            ':content' => $record->content,
            ':confidence' => $record->confidence,
            ':tags' => json_encode($record->tags),
            ':source_messages' => json_encode($record->sourceMessages),
            ':created_at' => $record->createdAt,
            ':last_confirmed_at' => $record->lastConfirmedAt,
            ':expires_at' => $record->expiresAt,
            ':metadata' => json_encode($record->metadata),
        ]);

        return $record;
    }

    public function get(string $id): ?MemoryRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assistant_memories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function listByUser(string $userId, string $tenantId = 'default'): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assistant_memories WHERE user_id = :user_id AND tenant_id = :tenant_id ORDER BY last_confirmed_at DESC');
        $stmt->execute([':user_id' => $userId, ':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): MemoryRecord { return $this->hydrate($row); }, $rows);
    }

    public function search(string $userId, string $tenantId, array $filters = []): array
    {
        $sql = 'SELECT * FROM assistant_memories WHERE user_id = :user_id AND tenant_id = :tenant_id';
        $params = [':user_id' => $userId, ':tenant_id' => $tenantId];

        if (!empty($filters['type'])) {
            $sql .= ' AND type = :type';
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['tag'])) {
            $sql .= ' AND tags::text LIKE :tag';
            $params[':tag'] = '%' . $filters['tag'] . '%';
        }
        if (!empty($filters['keyword'])) {
            $sql .= ' AND content LIKE :keyword';
            $params[':keyword'] = '%' . $filters['keyword'] . '%';
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY last_confirmed_at DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): MemoryRecord { return $this->hydrate($row); }, $rows);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM assistant_memories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function deleteExpired(string $tenantId = 'default'): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM assistant_memories WHERE tenant_id = :tenant_id AND expires_at IS NOT NULL AND expires_at < NOW()');
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS assistant_memories (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, user_id TEXT NOT NULL, conversation_id TEXT, type TEXT NOT NULL, content TEXT NOT NULL, confidence REAL NOT NULL DEFAULT 0.0, tags TEXT NOT NULL DEFAULT '[]', source_messages TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL, last_confirmed_at TEXT NOT NULL, expires_at TEXT, metadata TEXT NOT NULL DEFAULT '{}')");
    }

    private function hydrate(array $row): MemoryRecord
    {
        return new MemoryRecord([
            'id' => $row['id'],
            'tenantId' => $row['tenant_id'],
            'userId' => $row['user_id'],
            'conversationId' => $row['conversation_id'],
            'type' => $row['type'],
            'content' => $row['content'],
            'confidence' => (float)$row['confidence'],
            'tags' => json_decode($row['tags'], true) ?: [],
            'sourceMessages' => json_decode($row['source_messages'], true) ?: [],
            'createdAt' => $row['created_at'],
            'lastConfirmedAt' => $row['last_confirmed_at'],
            'expiresAt' => $row['expires_at'],
            'metadata' => json_decode($row['metadata'], true) ?: [],
        ]);
    }
}
