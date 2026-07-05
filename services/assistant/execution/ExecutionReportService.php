<?php

require_once __DIR__ . '/FileExecutionReportRepository.php';
require_once __DIR__ . '/ExecutionReportRepositoryInterface.php';
require_once __DIR__ . '/ExecutionReport.php';
require_once __DIR__ . '/AIUsageServiceInterface.php';
require_once __DIR__ . '/DefaultAIUsageService.php';
require_once __DIR__ . '/ProviderMetadata.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class ExecutionReportService
{
    private ExecutionReportRepositoryInterface $repo;
    private RuntimeEventEmitter $emitter;
    private array $active = [];
    private AIUsageServiceInterface $aiUsageService;

    public function __construct(?ExecutionReportRepositoryInterface $repo = null, ?RuntimeEventEmitter $emitter = null, ?AIUsageServiceInterface $aiUsageService = null)
    {
        $this->repo = $repo ?? new FileExecutionReportRepository();
        $this->emitter = $emitter ?? new RuntimeEventEmitter();
        $this->aiUsageService = $aiUsageService ?? new DefaultAIUsageService(new DefaultUsageEstimator(), new DefaultCostCalculator(), new ProviderMetadataRegistry());
    }

    public function start(ExecutionReport $report): void
    {
        $executionId = $report->getExecutionId();
        $this->active[$executionId] = $report;
        // persist initial
        $this->repo->savePartial($executionId, $report->toArray());

        // bind to emitter events for streaming updates
        $emitter = $this->emitter;
        $repo = $this->repo;
        $self = $this;

        $emitter->on('assistant.provider.selected', function ($payload) use ($executionId, $repo, $self) {
            if (($payload['executionId'] ?? null) !== $executionId) return;
            $report = $self->active[$executionId] ?? null;
            if ($report) {
                $report->setProviderInfo(['provider' => $payload['provider'] ?? null, 'model' => $payload['model'] ?? null]);
                $repo->savePartial($executionId, $report->toArray());
            }
        });

        $emitter->on('tool.invocation.started', function ($payload) use ($executionId, $repo, $self) {
            if (($payload['executionId'] ?? null) !== $executionId) return;
            $report = $self->active[$executionId] ?? null;
            if ($report) {
                $report->addToolEvent(['toolId' => $payload['toolId'] ?? null, 'status' => 'started', 'arguments' => $payload['arguments'] ?? null, 'timestamp' => microtime(true)]);
                $repo->savePartial($executionId, $report->toArray());
            }
        });

        $emitter->on('tool.invocation.completed', function ($payload) use ($executionId, $repo, $self) {
            if (($payload['executionId'] ?? null) !== $executionId) return;
            $report = $self->active[$executionId] ?? null;
            if ($report) {
                $report->addToolEvent(['toolId' => $payload['toolId'] ?? null, 'status' => 'completed', 'result' => $payload['result'] ?? null, 'duration_ms' => $payload['duration_ms'] ?? null, 'timestamp' => microtime(true)]);
                $repo->savePartial($executionId, $report->toArray());
            }
        });

        $emitter->on('assistant.provider.request.completed', function ($payload) use ($executionId, $repo, $self) {
            if (($payload['executionId'] ?? null) !== $executionId) return;
            $report = $self->active[$executionId] ?? null;
            if ($report) {
                $providerMeta = ProviderMetadata::fromArray($payload['provider_metadata'] ?? []);
                $report->setProviderInfo(['provider' => $providerMeta->providerName, 'model' => $providerMeta->model]);
                $promptTokens = intval($payload['prompt_tokens'] ?? 0);
                $completionTokens = intval($payload['completion_tokens'] ?? 0);
                $usageSource = $payload['usage_source'] ?? '';
                $costSource = $payload['cost_source'] ?? '';

                if ($promptTokens === 0 && $completionTokens === 0) {
                    $est = $self->aiUsageService->estimateUsage($providerMeta->providerName, $providerMeta->model, $payload['prompt'] ?? '', $payload['completion'] ?? null);
                    $promptTokens = intval($est['promptTokens']);
                    $completionTokens = intval($est['completionTokens']);
                    $usageSource = $usageSource ?: ($est['source'] ?? 'estimated');
                } else {
                    $usageSource = $usageSource ?: 'provider';
                }

                $cost = $self->aiUsageService->calculateCost($providerMeta->toArray(), $providerMeta->model, $promptTokens, $completionTokens, $report->toArray()['tenantId'] ?? null);
                $report->addLLMUsage($promptTokens, $completionTokens, floatval($cost['estimatedCost'] ?? 0.0), $cost['currency'] ?? 'USD');
                $report->setCostSource($costSource ?: ($cost['source'] ?? 'estimated'));
                $report->setUsageSource($usageSource);
                $repo->savePartial($executionId, $report->toArray());
            }
        });
    }

    public function update(ExecutionReport $report): void
    {
        $executionId = $report->getExecutionId();
        $this->active[$executionId] = $report;
        $this->repo->savePartial($executionId, $report->toArray());
    }

    public function finish(ExecutionReport $report): void
    {
        $executionId = $report->getExecutionId();
        $report->finish();
        $this->repo->save($report->toArray());
        unset($this->active[$executionId]);
    }

    public function saveExecutionReport(ExecutionReport $report): bool
    {
        return $this->repo->save($report->toArray());
    }
}

