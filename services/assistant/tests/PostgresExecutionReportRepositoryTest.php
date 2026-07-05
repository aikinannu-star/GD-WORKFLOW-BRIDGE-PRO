<?php

require_once __DIR__ . '/../execution/PostgresExecutionReportRepository.php';
require_once __DIR__ . '/../execution/ExecutionReport.php';

class PostgresExecutionReportRepositoryTest
{
    private \PDO $pdo;
    private PostgresExecutionReportRepository $repo;

    public function __construct()
    {
        // Connect to test database
        $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
        $dbName = getenv('DB_NAME') ?: 'gdwb_dev';
        $dbUser = getenv('DB_USER') ?: 'gdwb';
        $dbPass = getenv('DB_PASS') ?: 'gdwb';
        $dbPort = getenv('DB_PORT') ?: 5432;

        try {
            $this->pdo = new \PDO(
                "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName",
                $dbUser,
                $dbPass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\PDOException $e) {
            echo "⚠️  Postgres connection failed: " . $e->getMessage() . "\n";
            echo "Postgres test skipped (dev environment may not have local DB)\n";
            exit(0);
        }

        $this->repo = new PostgresExecutionReportRepository($this->pdo);
    }

    public function run(): bool
    {
        try {
            // Skip if table doesn't exist (migration not run)
            if (!$this->tableExists()) {
                echo "⚠️  execution_reports table not found. Run migrations first: php vendor/bin/phinx migrate -e development\n";
                return true;
            }

            $this->testSaveFullReport();
            $this->testSavePartialReport();
            $this->testReportPersistence();
            $this->testUsageSourceAndCostSource();

            echo "✅ Postgres execution report repository test passed\n";
            return true;
        } catch (\Exception $e) {
            echo "❌ Postgres execution report repository test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function tableExists(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM execution_reports LIMIT 1");
            return $stmt !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function testSaveFullReport(): void
    {
        $executionId = 'exec_test_' . uniqid();
        $report = [
            'executionId' => $executionId,
            'traceId' => 'trace_' . uniqid(),
            'requestId' => 'req_' . uniqid(),
            'tenantId' => 'tenant_123',
            'assistantId' => 'assistant_456',
            'conversationId' => 'conv_789',
            'workflowId' => 'workflow_abc',
            'userId' => 'user_def',
            'startedAt' => microtime(true),
            'finishedAt' => microtime(true) + 1.5,
            'duration' => 1.5,
            'status' => 'success',
            'stagesExecuted' => [['name' => 'ModelExecution', 'details' => []]],
            'errors' => [],
            'provider' => [
                'provider' => 'openai',
                'model' => 'gpt-4',
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'latencyMs' => 1234,
            ],
            'llmUsage' => [
                'promptTokens' => 150,
                'completionTokens' => 100,
                'totalTokens' => 250,
                'estimatedCost' => 0.0075,
                'currency' => 'USD',
            ],
            'usageSource' => 'reported',
            'costSource' => 'provider_pricing',
            'tools' => [
                ['name' => 'search', 'invocations' => 2],
            ],
            'memory' => [
                'reads' => 5,
                'writes' => 2,
                'vectorQueries' => 1,
                'summaries' => 0,
            ],
            'observability' => [
                'spans' => [],
                'metrics' => [],
                'events' => [],
            ],
            'output' => [
                'responseSize' => 512,
                'success' => true,
                'errorType' => null,
                'warnings' => [],
            ],
            'metadata' => [
                'execution_id' => $executionId,
            ],
        ];

        $result = $this->repo->save($report);
        if (!$result) {
            throw new \Exception('Failed to save full report');
        }

        // Verify record was saved
        $stmt = $this->pdo->prepare("SELECT * FROM execution_reports WHERE execution_id = ?");
        $stmt->execute([$executionId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new \Exception('Report not found in database after save');
        }

        // Verify key fields
        if ($row['trace_id'] !== $report['traceId']) {
            throw new \Exception('trace_id mismatch');
        }
        if ($row['prompt_tokens'] !== 150) {
            throw new \Exception('prompt_tokens mismatch');
        }
        if ($row['usage_source'] !== 'reported') {
            throw new \Exception('usage_source mismatch');
        }
        if ($row['cost_source'] !== 'provider_pricing') {
            throw new \Exception('cost_source mismatch');
        }

        // Clean up
        $this->pdo->prepare("DELETE FROM execution_reports WHERE execution_id = ?")->execute([$executionId]);
    }

    private function testSavePartialReport(): void
    {
        $executionId = 'exec_partial_' . uniqid();
        
        // Initial save
        $initial = [
            'executionId' => $executionId,
            'status' => 'running',
            'startedAt' => microtime(true),
            'metadata' => ['execution_id' => $executionId],
        ];

        $this->repo->save($initial);

        // Partial update
        $partial = [
            'status' => 'success',
            'llmUsage' => [
                'promptTokens' => 100,
                'completionTokens' => 50,
                'totalTokens' => 150,
                'estimatedCost' => 0.005,
            ],
            'duration' => 2.0,
        ];

        $result = $this->repo->savePartial($executionId, $partial);
        if (!$result) {
            throw new \Exception('Failed to savePartial');
        }

        // Verify partial update
        $stmt = $this->pdo->prepare("SELECT * FROM execution_reports WHERE execution_id = ?");
        $stmt->execute([$executionId]);
        $row = $stmt->fetch();

        if ($row['status'] !== 'success') {
            throw new \Exception('Partial update failed: status not updated');
        }
        if ($row['prompt_tokens'] !== 100) {
            throw new \Exception('Partial update failed: prompt_tokens not updated');
        }
        if (is_null($row['duration_ms'])) {
            throw new \Exception('Partial update failed: duration_ms not set');
        }

        // Clean up
        $this->pdo->prepare("DELETE FROM execution_reports WHERE execution_id = ?")->execute([$executionId]);
    }

    private function testReportPersistence(): void
    {
        $executionId = 'exec_persist_' . uniqid();

        $tools = [
            ['name' => 'search', 'invocations' => 3],
            ['name' => 'calculator', 'invocations' => 1],
        ];

        $memory = [
            'reads' => 10,
            'writes' => 5,
            'vectorQueries' => 2,
            'summaries' => 1,
        ];

        $report = [
            'executionId' => $executionId,
            'status' => 'success',
            'tools' => $tools,
            'memory' => $memory,
            'metadata' => ['execution_id' => $executionId],
        ];

        $this->repo->save($report);

        // Retrieve and verify JSON persistence
        $stmt = $this->pdo->prepare("SELECT tools, memory FROM execution_reports WHERE execution_id = ?");
        $stmt->execute([$executionId]);
        $row = $stmt->fetch();

        $persistedTools = json_decode($row['tools'], true) ?: [];
        $persistedMemory = json_decode($row['memory'], true) ?: [];

        if ($persistedTools !== $tools) {
            throw new \Exception('Tools JSON not properly persisted');
        }
        if ($persistedMemory !== $memory) {
            throw new \Exception('Memory JSON not properly persisted');
        }

        // Clean up
        $this->pdo->prepare("DELETE FROM execution_reports WHERE execution_id = ?")->execute([$executionId]);
    }

    private function testUsageSourceAndCostSource(): void
    {
        $executionId = 'exec_sources_' . uniqid();

        $report = [
            'executionId' => $executionId,
            'usageSource' => 'estimated',
            'costSource' => 'provider_pricing',
            'metadata' => ['execution_id' => $executionId],
        ];

        $this->repo->save($report);

        // Verify source tracking
        $stmt = $this->pdo->prepare("SELECT usage_source, cost_source FROM execution_reports WHERE execution_id = ?");
        $stmt->execute([$executionId]);
        $row = $stmt->fetch();

        if ($row['usage_source'] !== 'estimated') {
            throw new \Exception('usage_source not properly saved');
        }
        if ($row['cost_source'] !== 'provider_pricing') {
            throw new \Exception('cost_source not properly saved');
        }

        // Clean up
        $this->pdo->prepare("DELETE FROM execution_reports WHERE execution_id = ?")->execute([$executionId]);
    }
}

$test = new PostgresExecutionReportRepositoryTest();
exit($test->run() ? 0 : 1);
