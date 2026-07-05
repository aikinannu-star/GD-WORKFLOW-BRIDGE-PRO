<?php

class DecisionAuditDB
{
    private static $connection = null;
    private static $dbName = 'gdwb_decision_audit';

    public static function connect(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        try {
            self::$connection = new PDO(
                "mysql:host=$host;port=$port;dbname=" . self::$dbName,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5,
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }

        return self::$connection;
    }

    public static function createDatabaseIfNotExists(): void
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        try {
            $pdo = new PDO(
                "mysql:host=$host;port=$port",
                $user,
                $pass
            );

            $pdo->exec("CREATE DATABASE IF NOT EXISTS " . self::$dbName . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            throw new Exception("Failed to create database: " . $e->getMessage());
        }
    }

    public static function initializeSchema(): void
    {
        $schemaPath = __DIR__ . '/schema.sql';
        if (!file_exists($schemaPath)) {
            throw new Exception("Schema file not found: $schemaPath");
        }

        $sql = file_get_contents($schemaPath);
        $pdo = self::connect();

        // Split on DELIMITER changes and execute statements
        $statements = array_filter(array_map('trim', preg_split('/;(?=\s*(DELIMITER|$))/', $sql)));

        foreach ($statements as $statement) {
            if (!empty($statement) && !str_starts_with($statement, 'DELIMITER')) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Table may already exist, that's OK
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
    }

    public static function recordDecision(array $data): string
    {
        $pdo = self::connect();
        $decision_id = bin2hex(random_bytes(18)); // 36-char hex string

        $stmt = $pdo->prepare('
            INSERT INTO decisions (
                id, tenant_id, timestamp, decision_type, source_service,
                triggering_metrics, evidence, model_version, recommendation,
                recommendation_detail, confidence, priority, execution_status
            ) VALUES (
                :id, :tenant_id, NOW(), :decision_type, :source_service,
                :triggering_metrics, :evidence, :model_version, :recommendation,
                :recommendation_detail, :confidence, :priority, :execution_status
            )
        ');

        $stmt->execute([
            ':id' => $decision_id,
            ':tenant_id' => $data['tenant_id'],
            ':decision_type' => $data['decision_type'],
            ':source_service' => $data['source_service'],
            ':triggering_metrics' => json_encode($data['triggering_metrics'] ?? []),
            ':evidence' => json_encode($data['evidence'] ?? []),
            ':model_version' => $data['model_version'],
            ':recommendation' => $data['recommendation'],
            ':recommendation_detail' => json_encode($data['recommendation_detail'] ?? null),
            ':confidence' => (float)$data['confidence'],
            ':priority' => $data['priority'] ?? 'medium',
            ':execution_status' => 'pending',
        ]);

        return $decision_id;
    }

    public static function getDecision(string $decision_id): ?array
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('SELECT * FROM decisions WHERE id = :id');
        $stmt->execute([':id' => $decision_id]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return self::decodedecisionRow($row);
    }

    public static function getDecisions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $pdo = self::connect();

        $where = [];
        $params = [];

        if (!empty($filters['tenant_id'])) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $filters['tenant_id'];
        }

        if (!empty($filters['decision_type'])) {
            $where[] = 'decision_type = :decision_type';
            $params[':decision_type'] = $filters['decision_type'];
        }

        if (!empty($filters['source_service'])) {
            $where[] = 'source_service = :source_service';
            $params[':source_service'] = $filters['source_service'];
        }

        if (!empty($filters['operator_action'])) {
            $where[] = 'operator_action = :operator_action';
            $params[':operator_action'] = $filters['operator_action'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'timestamp >= :start_date';
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = 'timestamp <= :end_date';
            $params[':end_date'] = $filters['end_date'];
        }

        if (!empty($filters['min_effectiveness'])) {
            $where[] = 'effectiveness_score >= :min_effectiveness';
            $params[':min_effectiveness'] = $filters['min_effectiveness'];
        }

        if (!empty($filters['priority'])) {
            $where[] = 'priority = :priority';
            $params[':priority'] = $filters['priority'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT * FROM decisions
            $whereClause
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return array_map([self::class, 'decodeDecisionRow'], $stmt->fetchAll());
    }

    public static function getDecisionTimeline(string $tenant_id, string $start_date, string $end_date, int $limit = 50): array
    {
        $pdo = self::connect();

        $stmt = $pdo->prepare('
            SELECT
                d.id,
                d.timestamp,
                d.decision_type,
                d.source_service,
                d.recommendation,
                d.confidence,
                d.priority,
                d.operator_action,
                d.operator_timestamp,
                d.execution_status,
                d.effectiveness_score,
                COUNT(dr.id) as related_decisions_count
            FROM decisions d
            LEFT JOIN decision_relationships dr ON d.id = dr.parent_decision_id
            WHERE d.tenant_id = :tenant_id
                AND d.timestamp BETWEEN :start_date AND :end_date
            GROUP BY d.id
            ORDER BY d.timestamp DESC
            LIMIT :limit
        ');

        $stmt->bindValue(':tenant_id', $tenant_id);
        $stmt->bindValue(':start_date', $start_date);
        $stmt->bindValue(':end_date', $end_date);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function recordOperatorAction(string $decision_id, string $action, ?string $notes = null, ?string $operator_id = null): bool
    {
        $pdo = self::connect();

        $stmt = $pdo->prepare('
            UPDATE decisions SET
                operator_action = :action,
                operator_notes = :notes,
                operator_id = :operator_id,
                operator_timestamp = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $decision_id,
            ':action' => $action,
            ':notes' => $notes,
            ':operator_id' => $operator_id,
        ]);
    }

    public static function recordExecutionStart(string $decision_id): bool
    {
        $pdo = self::connect();

        $stmt = $pdo->prepare('
            UPDATE decisions SET
                execution_status = :status,
                execution_start = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $decision_id,
            ':status' => 'executing',
        ]);
    }

    public static function recordExecutionEnd(string $decision_id, string $status, ?string $error = null): bool
    {
        $pdo = self::connect();

        $stmt = $pdo->prepare('
            UPDATE decisions SET
                execution_status = :status,
                execution_end = NOW(),
                execution_error = :error,
                updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $decision_id,
            ':status' => $status,
            ':error' => $error,
        ]);
    }

    public static function calculateEffectiveness(string $decision_id, array $health_before, array $health_after): bool
    {
        $pdo = self::connect();

        // Get decision to check operator_action
        $decision = self::getDecision($decision_id);
        if (!$decision) {
            return false;
        }

        // Calculate effectiveness
        $health_improvement = ($health_after['health_score'] ?? 0) - ($health_before['health_score'] ?? 0);
        $health_improvement = min($health_improvement / 20, 1.0);

        $operator_confidence = 0.7; // accepted
        if ($decision['operator_action'] === 'rejected') {
            $operator_confidence = 0.0;
        } elseif ($decision['operator_action'] === 'deferred') {
            $operator_confidence = 0.5;
        }

        $effectiveness = ($health_improvement * 0.3) + ($operator_confidence * 0.7);
        $effectiveness = max(0, min(1, $effectiveness)); // Clamp to [0, 1]

        $stmt = $pdo->prepare('
            UPDATE decisions SET
                effectiveness_score = :effectiveness,
                health_before = :health_before,
                health_after = :health_after,
                updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $decision_id,
            ':effectiveness' => round($effectiveness, 2),
            ':health_before' => json_encode($health_before),
            ':health_after' => json_encode($health_after),
        ]);
    }

    public static function recordLearningFeedback(string $decision_id, array $feedback): bool
    {
        $pdo = self::connect();

        $stmt = $pdo->prepare('
            UPDATE decisions SET
                learning_feedback = :feedback,
                updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            ':id' => $decision_id,
            ':feedback' => json_encode($feedback),
        ]);
    }

    public static function addDecisionRelationship(string $parent_id, string $child_id, string $type, ?string $explanation = null): bool
    {
        $pdo = self::connect();

        try {
            $stmt = $pdo->prepare('
                INSERT INTO decision_relationships (parent_decision_id, child_decision_id, relationship_type, explanation)
                VALUES (:parent, :child, :type, :explanation)
            ');

            return $stmt->execute([
                ':parent' => $parent_id,
                ':child' => $child_id,
                ':type' => $type,
                ':explanation' => $explanation,
            ]);
        } catch (PDOException $e) {
            // Duplicate relationship, that's OK
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                throw $e;
            }
            return true;
        }
    }

    public static function getRelatedDecisions(string $decision_id): array
    {
        $pdo = self::connect();

        // Get both parent (caused this) and child (this caused) relationships
        $stmt = $pdo->prepare('
            SELECT
                dr.relationship_type,
                COALESCE(p.id, c.id) as related_id,
                COALESCE(p.decision_type, c.decision_type) as decision_type,
                COALESCE(p.recommendation, c.recommendation) as recommendation,
                COALESCE(p.timestamp, c.timestamp) as timestamp,
                dr.explanation
            FROM decision_relationships dr
            LEFT JOIN decisions p ON dr.parent_decision_id = :id AND dr.child_decision_id = p.id
            LEFT JOIN decisions c ON dr.child_decision_id = :id AND dr.parent_decision_id = c.id
            WHERE dr.parent_decision_id = :id OR dr.child_decision_id = :id
            ORDER BY COALESCE(p.timestamp, c.timestamp) DESC
        ');

        $stmt->execute([':id' => $decision_id]);

        return $stmt->fetchAll();
    }

    public static function getAnalytics(array $filters = []): array
    {
        $pdo = self::connect();

        $where = [];
        $params = [];

        if (!empty($filters['tenant_id'])) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $filters['tenant_id'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = 'period_date >= :start_date';
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = 'period_date <= :end_date';
            $params[':end_date'] = $filters['end_date'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT
                tenant_id,
                period_date,
                total_decisions,
                acceptance_rate,
                rejection_rate,
                deferral_rate,
                avg_effectiveness,
                avg_confidence,
                decisions_by_type,
                decisions_by_source,
                recommendations_implemented,
                avg_time_to_action
            FROM decision_analytics
            $whereClause
            ORDER BY period_date DESC
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public static function exportDecisionRecord(string $decision_id, string $format, ?string $purpose = null, ?string $exported_by = null): string
    {
        $decision = self::getDecision($decision_id);
        if (!$decision) {
            throw new Exception("Decision not found: $decision_id");
        }

        $pdo = self::connect();

        // Record export
        $stmt = $pdo->prepare('
            INSERT INTO decision_exports (decision_id, export_format, export_purpose, exported_by)
            VALUES (:id, :format, :purpose, :exported_by)
        ');

        $stmt->execute([
            ':id' => $decision_id,
            ':format' => $format,
            ':purpose' => $purpose,
            ':exported_by' => $exported_by,
        ]);

        // Generate export based on format
        switch ($format) {
            case 'json':
                return json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            case 'csv':
                return self::encodeDecisionCsv($decision);

            case 'pdf':
                // For now, return JSON; PDF generation would require a library
                return json_encode($decision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            default:
                throw new Exception("Unsupported export format: $format");
        }
    }

    public static function encodeDecisionCsv(array $decision): string
    {
        $rows = [];

        // Header
        $rows[] = implode(',', array_map(fn($k) => '"' . str_replace('"', '""', $k) . '"', array_keys($decision)));

        // Values
        $values = array_map(function ($v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            return '"' . str_replace('"', '""', (string)$v) . '"';
        }, $decision);
        $rows[] = implode(',', $values);

        return implode("\n", $rows);
    }

    private static function decodeDecisionRow(array $row): array
    {
        $row['triggering_metrics'] = json_decode($row['triggering_metrics'] ?? '{}', true);
        $row['evidence'] = json_decode($row['evidence'] ?? '{}', true);
        $row['recommendation_detail'] = json_decode($row['recommendation_detail'] ?? 'null', true);
        $row['health_before'] = json_decode($row['health_before'] ?? 'null', true);
        $row['health_after'] = json_decode($row['health_after'] ?? 'null', true);
        $row['learning_feedback'] = json_decode($row['learning_feedback'] ?? 'null', true);

        return $row;
    }
}
