<?php

require_once __DIR__ . '/ExecutionReportRepositoryInterface.php';

class PostgresExecutionReportRepository implements ExecutionReportRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(array $report): bool
    {
        try {
            $executionId = $report['executionId'] ?? $report['metadata']['execution_id'] ?? null;
            if (!$executionId) {
                throw new \InvalidArgumentException('execution_id is required');
            }

            // Extract scalar fields
            $traceId = $report['traceId'] ?? $report['metadata']['trace_id'] ?? null;
            $requestId = $report['requestId'] ?? $report['metadata']['request_id'] ?? null;
            $tenantId = $report['tenantId'] ?? $report['metadata']['tenant_id'] ?? null;
            $assistantId = $report['assistantId'] ?? $report['metadata']['assistant_id'] ?? null;
            $conversationId = $report['conversationId'] ?? $report['metadata']['conversation_id'] ?? null;
            $workflowId = $report['workflowId'] ?? $report['metadata']['workflow_id'] ?? null;
            $userId = $report['userId'] ?? $report['metadata']['user_id'] ?? null;

            // Timing
            $startedAt = $report['startedAt'] ?? null;
            $finishedAt = $report['finishedAt'] ?? null;
            $durationMs = isset($report['duration']) ? round($report['duration'] * 1000) : null;

            // Status
            $status = 'success';
            if (!empty($report['errors'])) {
                $status = 'failure';
            } elseif (is_null($finishedAt)) {
                $status = 'running';
            }

            $failureReason = null;
            if (!empty($report['errors'])) {
                $failureReason = implode('; ', (array)$report['errors']);
            }

            // Provider info
            $provider = $report['provider']['provider'] ?? $report['metadata']['provider']['provider'] ?? null;
            $model = $report['provider']['model'] ?? $report['metadata']['provider']['model'] ?? null;
            $endpoint = $report['provider']['endpoint'] ?? $report['metadata']['provider']['endpoint'] ?? null;

            // Performance metrics
            $latencyMs = $report['provider']['latencyMs'] ?? null;
            $requestCount = count($report['stagesExecuted'] ?? []);

            // LLM usage
            $llmUsage = $report['llmUsage'] ?? $report['metadata']['llm_usage'] ?? [];
            $promptTokens = $llmUsage['promptTokens'] ?? 0;
            $completionTokens = $llmUsage['completionTokens'] ?? 0;
            $totalTokens = $llmUsage['totalTokens'] ?? 0;
            $estimatedCost = $llmUsage['estimatedCost'] ?? 0.0;
            $currency = $llmUsage['currency'] ?? 'USD';

               // Analytics: Token accounting
               $cachedTokens = $report['cachedTokens'] ?? $report['metadata']['cached_tokens'] ?? 0;
               $embeddingTokens = $report['embeddingTokens'] ?? $report['metadata']['embedding_tokens'] ?? 0;

               // Analytics: Cost reconciliation
               $actualCost = $report['actualCost'] ?? $report['metadata']['actual_cost'] ?? null;

               // Analytics: Performance metrics
               $queueTimeMs = $report['queueTimeMs'] ?? $report['metadata']['queue_time_ms'] ?? null;
               $toolCount = $report['toolCount'] ?? $report['metadata']['tool_count'] ?? 0;

               // Analytics: Response characteristics
               $streamed = $report['streamed'] ?? $report['metadata']['streamed'] ?? false;
               $providerVersion = $report['providerVersion'] ?? $report['metadata']['provider_version'] ?? null;
               $retryCount = $report['retryCount'] ?? $report['metadata']['retry_count'] ?? 0;

            // Usage and cost attribution
            $usageSource = $report['usageSource'] ?? $report['metadata']['usage_source'] ?? 'unknown';
            $costSource = $report['costSource'] ?? $report['metadata']['cost_source'] ?? 'none';

            // JSON fields
            $tools = json_encode($report['tools'] ?? $report['metadata']['tools'] ?? [], JSON_UNESCAPED_SLASHES);
            $memory = json_encode($report['memory'] ?? $report['metadata']['memory'] ?? [], JSON_UNESCAPED_SLASHES);
            $observability = json_encode($report['observability'] ?? $report['metadata']['observability'] ?? [], JSON_UNESCAPED_SLASHES);
            $output = json_encode($report['output'] ?? $report['metadata']['output'] ?? [], JSON_UNESCAPED_SLASHES);

            // Upsert (insert or update)
            $sql = <<<SQL
                INSERT INTO execution_reports (
                    execution_id, trace_id, request_id, tenant_id, assistant_id, conversation_id,
                    workflow_id, user_id, started_at, finished_at, duration_ms, status,
                       failure_reason, provider, model, endpoint, latency_ms,
                       request_count, prompt_tokens, completion_tokens, total_tokens,
                       cached_tokens, embedding_tokens, estimated_cost, actual_cost,
                       queue_time_ms, tool_count, streamed, provider_version, retry_count,
                       currency, usage_source, cost_source,
                    tools, memory, observability, output, created_at, updated_at
                ) VALUES (
                    :execution_id, :trace_id, :request_id, :tenant_id, :assistant_id, :conversation_id,
                       :workflow_id, :user_id, :started_at, :finished_at, :duration_ms, :status,
                       :failure_reason, :provider, :model, :endpoint, :latency_ms,
                       :request_count, :prompt_tokens, :completion_tokens, :total_tokens,
                       :cached_tokens, :embedding_tokens, :estimated_cost, :actual_cost,
                       :queue_time_ms, :tool_count, :streamed, :provider_version, :retry_count,
                       :currency, :usage_source, :cost_source,
                    :tools, :memory, :observability, :output, NOW(), NOW()
                )
                ON CONFLICT (execution_id) DO UPDATE SET
                    trace_id = :trace_id,
                    request_id = :request_id,
                    tenant_id = :tenant_id,
                    assistant_id = :assistant_id,
                    conversation_id = :conversation_id,
                    workflow_id = :workflow_id,
                    user_id = :user_id,
                    started_at = :started_at,
                    finished_at = :finished_at,
                    duration_ms = :duration_ms,
                    status = :status,
                    failure_reason = :failure_reason,
                    provider = :provider,
                    model = :model,
                    endpoint = :endpoint,
                    latency_ms = :latency_ms,
                    request_count = :request_count,
                    prompt_tokens = :prompt_tokens,
                    completion_tokens = :completion_tokens,
                    total_tokens = :total_tokens,
                       cached_tokens = :cached_tokens,
                       embedding_tokens = :embedding_tokens,
                    estimated_cost = :estimated_cost,
                       actual_cost = :actual_cost,
                       queue_time_ms = :queue_time_ms,
                       tool_count = :tool_count,
                       streamed = :streamed,
                       provider_version = :provider_version,
                       retry_count = :retry_count,
                    currency = :currency,
                    usage_source = :usage_source,
                    cost_source = :cost_source,
                    tools = :tools,
                    memory = :memory,
                    observability = :observability,
                    output = :output,
                    updated_at = NOW()
            SQL;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':execution_id' => $executionId,
                ':trace_id' => $traceId,
                ':request_id' => $requestId,
                ':tenant_id' => $tenantId,
                ':assistant_id' => $assistantId,
                ':conversation_id' => $conversationId,
                ':workflow_id' => $workflowId,
                ':user_id' => $userId,
                ':started_at' => $startedAt ? $this->formatTimestamp($startedAt) : null,
                ':finished_at' => $finishedAt ? $this->formatTimestamp($finishedAt) : null,
                ':duration_ms' => $durationMs,
                ':status' => $status,
                ':failure_reason' => $failureReason,
                ':provider' => $provider,
                ':model' => $model,
                ':endpoint' => $endpoint,
                ':latency_ms' => $latencyMs,
                ':request_count' => $requestCount,
                ':prompt_tokens' => $promptTokens,
                ':completion_tokens' => $completionTokens,
                ':total_tokens' => $totalTokens,
                   ':cached_tokens' => (int)$cachedTokens,
                   ':embedding_tokens' => (int)$embeddingTokens,
                ':estimated_cost' => (float)$estimatedCost,
                   ':actual_cost' => $actualCost !== null ? (float)$actualCost : null,
                   ':queue_time_ms' => $queueTimeMs,
                   ':tool_count' => (int)$toolCount,
                   ':streamed' => $streamed,
                   ':provider_version' => $providerVersion,
                   ':retry_count' => (int)$retryCount,
                ':currency' => $currency,
                ':usage_source' => $usageSource,
                ':cost_source' => $costSource,
                ':tools' => $tools,
                ':memory' => $memory,
                ':observability' => $observability,
                ':output' => $output,
            ]);

            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log("PostgresExecutionReportRepository save error: " . $e->getMessage());
            return false;
        }
    }

    public function savePartial(string $executionId, array $partial): bool
    {
        try {
            // For partial updates, fetch existing record and merge
            $fetchSql = "SELECT * FROM execution_reports WHERE execution_id = :execution_id";
            $fetchStmt = $this->pdo->prepare($fetchSql);
            $fetchStmt->execute([':execution_id' => $executionId]);
            $existing = $fetchStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$existing) {
                // If no existing record, treat as new save
                $partial['executionId'] = $executionId;
                return $this->save($partial);
            }

            // Merge partial updates
            $updateData = [];
            $updateFields = [];

            // Map partial array keys to DB columns
            $fieldMap = [
                'status' => 'status',
                'llmUsage' => 'prompt_tokens,completion_tokens,total_tokens,estimated_cost',
                'failure_reason' => 'failure_reason',
                'tools' => 'tools',
                'memory' => 'memory',
                'observability' => 'observability',
                'output' => 'output',
                'duration' => 'duration_ms',
            ];

            if (isset($partial['status'])) {
                $updateFields[] = "status = :status";
                $updateData[':status'] = $partial['status'];
            }

            if (isset($partial['llmUsage'])) {
                $llmUsage = $partial['llmUsage'];
                $updateFields[] = "prompt_tokens = :prompt_tokens";
                $updateFields[] = "completion_tokens = :completion_tokens";
                $updateFields[] = "total_tokens = :total_tokens";
                $updateFields[] = "estimated_cost = :estimated_cost";
                $updateData[':prompt_tokens'] = $llmUsage['promptTokens'] ?? 0;
                $updateData[':completion_tokens'] = $llmUsage['completionTokens'] ?? 0;
                $updateData[':total_tokens'] = $llmUsage['totalTokens'] ?? 0;
                $updateData[':estimated_cost'] = $llmUsage['estimatedCost'] ?? 0.0;
            }

            if (isset($partial['failure_reason'])) {
                $updateFields[] = "failure_reason = :failure_reason";
                $updateData[':failure_reason'] = $partial['failure_reason'];
            }

            if (isset($partial['tools'])) {
                $updateFields[] = "tools = :tools";
                $updateData[':tools'] = json_encode($partial['tools'], JSON_UNESCAPED_SLASHES);
            }

            if (isset($partial['memory'])) {
                $updateFields[] = "memory = :memory";
                $updateData[':memory'] = json_encode($partial['memory'], JSON_UNESCAPED_SLASHES);
            }

            if (isset($partial['observability'])) {
                $updateFields[] = "observability = :observability";
                $updateData[':observability'] = json_encode($partial['observability'], JSON_UNESCAPED_SLASHES);
            }

            if (isset($partial['output'])) {
                $updateFields[] = "output = :output";
                $updateData[':output'] = json_encode($partial['output'], JSON_UNESCAPED_SLASHES);
            }

            if (isset($partial['duration'])) {
                $updateFields[] = "duration_ms = :duration_ms";
                $updateData[':duration_ms'] = round($partial['duration'] * 1000);
            }

               // Analytics fields
               if (isset($partial['cachedTokens'])) {
                   $updateFields[] = "cached_tokens = :cached_tokens";
                   $updateData[':cached_tokens'] = (int)$partial['cachedTokens'];
               }

               if (isset($partial['embeddingTokens'])) {
                   $updateFields[] = "embedding_tokens = :embedding_tokens";
                   $updateData[':embedding_tokens'] = (int)$partial['embeddingTokens'];
               }

               if (isset($partial['actualCost'])) {
                   $updateFields[] = "actual_cost = :actual_cost";
                   $updateData[':actual_cost'] = $partial['actualCost'] !== null ? (float)$partial['actualCost'] : null;
               }

               if (isset($partial['queueTimeMs'])) {
                   $updateFields[] = "queue_time_ms = :queue_time_ms";
                   $updateData[':queue_time_ms'] = $partial['queueTimeMs'];
               }

               if (isset($partial['toolCount'])) {
                   $updateFields[] = "tool_count = :tool_count";
                   $updateData[':tool_count'] = (int)$partial['toolCount'];
               }

               if (isset($partial['streamed'])) {
                   $updateFields[] = "streamed = :streamed";
                   $updateData[':streamed'] = (bool)$partial['streamed'];
               }

               if (isset($partial['providerVersion'])) {
                   $updateFields[] = "provider_version = :provider_version";
                   $updateData[':provider_version'] = $partial['providerVersion'];
               }

               if (isset($partial['retryCount'])) {
                   $updateFields[] = "retry_count = :retry_count";
                   $updateData[':retry_count'] = (int)$partial['retryCount'];
               }

            if (empty($updateFields)) {
                return true; // Nothing to update
            }

            $updateFields[] = "updated_at = NOW()";
            $updateData[':execution_id'] = $executionId;

            $sql = "UPDATE execution_reports SET " . implode(', ', $updateFields) . " WHERE execution_id = :execution_id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($updateData);
        } catch (\Exception $e) {
            error_log("PostgresExecutionReportRepository savePartial error: " . $e->getMessage());
            return false;
        }
    }

    private function formatTimestamp(float $microtime): string
    {
        return date('Y-m-d H:i:s', (int)$microtime);
    }
}
