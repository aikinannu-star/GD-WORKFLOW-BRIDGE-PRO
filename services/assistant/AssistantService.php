<?php

require_once __DIR__ . '/AssistantPipeline.php';
require_once __DIR__ . '/AssistantContext.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';

require_once __DIR__ . '/../lib/ServiceHelpers.php';

class AssistantService
{
    private AssistantPipeline $pipeline;
    private ?RuntimeEventEmitter $eventEmitter;

    public function __construct(AssistantPipeline $pipeline, ?RuntimeEventEmitter $eventEmitter = null)
    {
        $this->pipeline = $pipeline;
        $this->eventEmitter = $eventEmitter;
    }

    public function handleMessage(AssistantContext $context, string $message): array
    {
        $requestId = ServiceHelpers::getOrCreateRequestId();
        $traceContext = ServiceHelpers::getTraceContext();
        $startTime = microtime(true);

        ServiceHelpers::emitStructuredLog('assistant', 'info', 'assistant.execution.started', [
            'event' => 'assistant.execution.started',
            'request_id' => $requestId,
            'trace_id' => $traceContext['trace_id'],
            'span_id' => $traceContext['span_id'],
            'assistant_id' => $context->assistantId,
            'conversation_id' => $context->conversationId,
            'session_id' => $context->sessionId,
            'tenant_id' => $context->tenantId,
            'user_id' => $context->userId,
            'message_length' => strlen($message),
        ]);
        ServiceHelpers::incrementMetric('assistant', 'assistant_requests_total', ['tenant_id' => $context->tenantId ?? 'unknown']);

        if ($this->eventEmitter) {
            $this->eventEmitter->emit('assistant.execution.started', [
                'assistantId' => $context->assistantId,
                'conversationId' => $context->conversationId,
                'sessionId' => $context->sessionId,
                'tenantId' => $context->tenantId,
                'userId' => $context->userId,
                'message' => $message,
            ]);
        }

        try {
            $result = $this->pipeline->execute($context, $message);
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            ServiceHelpers::emitStructuredLog('assistant', 'info', 'assistant.execution.completed', [
                'event' => 'assistant.execution.completed',
                'request_id' => $requestId,
                'trace_id' => $traceContext['trace_id'],
                'span_id' => $traceContext['span_id'],
                'assistant_id' => $context->assistantId,
                'conversation_id' => $context->conversationId,
                'session_id' => $context->sessionId,
                'tenant_id' => $context->tenantId,
                'user_id' => $context->userId,
                'success' => $result['success'] ?? false,
                'tool_id' => $result['tool'] ?? null,
                'response_length' => strlen((string)($result['assistantText'] ?? '')),
                'duration_ms' => $durationMs,
            ]);
            ServiceHelpers::observeMetric('assistant', 'assistant_execution_latency_seconds', ['tenant_id' => $context->tenantId ?? 'unknown'], $durationMs / 1000.0);
            if (!empty($result['success'])) {
                ServiceHelpers::incrementMetric('assistant', 'assistant_execution_success_total', ['tenant_id' => $context->tenantId ?? 'unknown']);
            } else {
                ServiceHelpers::incrementMetric('assistant', 'assistant_execution_failed_total', ['tenant_id' => $context->tenantId ?? 'unknown']);
            }

            ServiceHelpers::exportOtlpTrace('assistant', [[
                'trace_id' => $traceContext['trace_id'],
                'span_id' => $traceContext['span_id'],
                'name' => 'assistant.execution',
                'start_time' => $startTime,
                'end_time' => microtime(true),
                'status_code' => !empty($result['success']) ? 0 : 2,
                'attributes' => [
                    'assistant_id' => $context->assistantId,
                    'conversation_id' => $context->conversationId,
                    'tenant_id' => $context->tenantId,
                    'user_id' => $context->userId,
                    'success' => !empty($result['success']),
                    'tool_id' => $result['tool'] ?? null,
                ],
            ]]);

            if ($this->eventEmitter) {
                $this->eventEmitter->emit('assistant.execution.completed', [
                    'assistantId' => $context->assistantId,
                    'conversationId' => $context->conversationId,
                    'sessionId' => $context->sessionId,
                    'tenantId' => $context->tenantId,
                    'userId' => $context->userId,
                    'result' => $result,
                    'durationMs' => $durationMs,
                ]);
            }
            return $result;
        } catch (Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            ServiceHelpers::emitStructuredLog('assistant', 'error', 'assistant.execution.failed', [
                'event' => 'assistant.execution.failed',
                'request_id' => $requestId,
                'trace_id' => $traceContext['trace_id'],
                'span_id' => $traceContext['span_id'],
                'assistant_id' => $context->assistantId,
                'conversation_id' => $context->conversationId,
                'session_id' => $context->sessionId,
                'tenant_id' => $context->tenantId,
                'user_id' => $context->userId,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);
            ServiceHelpers::incrementMetric('assistant', 'assistant_execution_failed_total', ['tenant_id' => $context->tenantId ?? 'unknown']);
            ServiceHelpers::observeMetric('assistant', 'assistant_execution_latency_seconds', ['tenant_id' => $context->tenantId ?? 'unknown'], $durationMs / 1000.0);
            if ($this->eventEmitter) {
                $this->eventEmitter->emit('assistant.execution.failed', [
                    'assistantId' => $context->assistantId,
                    'conversationId' => $context->conversationId,
                    'sessionId' => $context->sessionId,
                    'tenantId' => $context->tenantId,
                    'userId' => $context->userId,
                    'error' => $e->getMessage(),
                    'durationMs' => $durationMs,
                ]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
