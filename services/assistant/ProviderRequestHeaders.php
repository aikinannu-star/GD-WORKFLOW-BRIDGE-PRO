<?php

require_once __DIR__ . '/context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../lib/ServiceHelpers.php';

class ProviderRequestHeaders
{
    public static function buildFromContext(RuntimeExecutionContext $context, array $options = []): array
    {
        $meta = [
            'trace' => $options['trace'] ?? ServiceHelpers::getTraceContext(),
            'request_id' => $options['request_id'] ?? ServiceHelpers::getOrCreateRequestId(),
            'tenant_id' => $options['tenant_id'] ?? $context->getTenantId(),
            'assistant_id' => $options['assistant_id'] ?? $context->getAssistantId(),
            'conversation_id' => $options['conversation_id'] ?? $context->getConversationId(),
            'workflow_id' => $options['workflow_id'] ?? ($context->getWorkflow()['id'] ?? null),
            'execution_id' => $options['execution_id'] ?? $context->getExecutionId(),
        ];
        return self::build($meta);
    }

    public static function build(array $meta): array
    {
        $headers = [];
        $trace = $meta['trace'] ?? [];
        if (!is_array($trace)) {
            $trace = [];
        }

        $traceId = trim((string)($trace['trace_id'] ?? ''));
        $spanId = trim((string)($trace['span_id'] ?? ''));
        $parentSpanId = trim((string)($trace['parent_span_id'] ?? ''));

        if ($traceId !== '' && $spanId !== '') {
            $headers['traceparent'] = self::formatTraceParent($traceId, $spanId);
        }
        if ($traceId !== '') {
            $headers['X-Trace-Id'] = $traceId;
        }
        if ($spanId !== '') {
            $headers['X-Span-Id'] = $spanId;
        }
        if ($parentSpanId !== '') {
            $headers['X-Parent-Span-Id'] = $parentSpanId;
        }

        if (!empty($meta['request_id'])) {
            $headers['X-Request-Id'] = (string)$meta['request_id'];
        }
        if (!empty($meta['tenant_id'])) {
            $headers['X-Tenant-Id'] = (string)$meta['tenant_id'];
        }
        if (!empty($meta['assistant_id'])) {
            $headers['X-Assistant-Id'] = (string)$meta['assistant_id'];
        }
        if (!empty($meta['conversation_id'])) {
            $headers['X-Conversation-Id'] = (string)$meta['conversation_id'];
        }
        if (!empty($meta['workflow_id'])) {
            $headers['X-Workflow-Id'] = (string)$meta['workflow_id'];
        }
        if (!empty($meta['execution_id'])) {
            $headers['X-Execution-Id'] = (string)$meta['execution_id'];
        }

        return $headers;
    }

    private static function formatTraceParent(string $traceId, string $spanId): string
    {
        $traceId = preg_replace('/[^a-fA-F0-9]/', '', $traceId);
        $spanId = preg_replace('/[^a-fA-F0-9]/', '', $spanId);
        if (strlen($traceId) === 32 && strlen($spanId) === 16) {
            return sprintf('00-%s-%s-01', strtolower($traceId), strtolower($spanId));
        }
        return sprintf('00-%s-%s-01', substr(str_pad($traceId, 32, '0'), 0, 32), substr(str_pad($spanId, 16, '0'), 0, 16));
    }
}
