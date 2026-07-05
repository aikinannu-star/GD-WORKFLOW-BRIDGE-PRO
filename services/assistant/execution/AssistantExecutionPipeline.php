<?php

require_once __DIR__ . '/AssistantExecutionStageInterface.php';
require_once __DIR__ . '/AssistantExecutionStageRegistry.php';
require_once __DIR__ . '/ExecutionReport.php';
require_once __DIR__ . '/ExecutionReportService.php';
require_once __DIR__ . '/FileExecutionReportRepository.php';
require_once __DIR__ . '/RuntimeExecutionResult.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../context/ModelProfile.php';
require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/ContextAssembler.php';
require_once __DIR__ . '/../context/PromptAssembler.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/ProviderMetadata.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/../../lib/ServiceHelpers.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../RuntimeServiceRegistry.php';

class AssistantExecutionPipeline
{
    private AssistantExecutionStageRegistry $stageRegistry;
    private RuntimeServiceRegistry $serviceRegistry;
    private RuntimeContextServices $contextServices;
    private RuntimePromptServices $promptServices;
    private RuntimeProviderServices $providerServices;
    private RuntimeToolServices $toolServices;
    private RuntimeMemoryServices $memoryServices;
    private RuntimeResponseServices $responseServices;

    public function __construct(
        ?ModelProviderInterface $provider = null,
        ?RuntimeEventEmitter $eventEmitter = null,
        ?AssistantExecutionStageRegistry $stageRegistry = null,
        ?ToolRegistry $toolRegistry = null,
        ?ContextAssembler $contextAssembler = null,
        ?PromptAssembler $promptAssembler = null,
        ?PromptOptimizationPipeline $promptOptimizer = null,
        ?MemoryStore $memoryStore = null,
        ?RuntimeServiceRegistry $serviceRegistry = null
    ) {
        $this->serviceRegistry = $serviceRegistry ?? new RuntimeServiceRegistry($provider, $eventEmitter, $toolRegistry, $contextAssembler, $promptAssembler, $promptOptimizer, null, $memoryStore);
        $this->contextServices = $this->serviceRegistry->contextServices();
        $this->promptServices = $this->serviceRegistry->promptServices();
        $this->providerServices = $this->serviceRegistry->providerServices();
        $this->toolServices = $this->serviceRegistry->toolServices();
        $this->memoryServices = $this->serviceRegistry->memoryServices();
        $this->responseServices = $this->serviceRegistry->responseServices();
        $this->stageRegistry = $stageRegistry ?? $this->buildDefaultRegistry();
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionResult
    {
        $report = new ExecutionReport($context->getExecutionId());
        $startTime = microtime(true);
        $this->emit('assistant.execution.started', ['executionId' => $context->getExecutionId()]);

        // start streaming execution report
        $reportService = new ExecutionReportService(
            null,
            $this->serviceRegistry->getEventEmitter(),
            $this->serviceRegistry->getAIUsageService()
        );
        $reportService->start($report);

        try {
            $stages = $this->stageRegistry->getAllStages();
            foreach ($stages as $entry) {
                if (!$entry['stage']->supports($context, $this->providerServices->getProvider())) {
                    continue;
                }
                $stageStart = microtime(true);
                $this->emit('assistant.execution.stage.started', ['stage' => $entry['name'], 'executionId' => $context->getExecutionId()]);
                $context = $entry['stage']->execute($context);
                $report->addStageExecuted($entry['name'], ['priority' => $entry['stage']->priority()]);
                // persist stage-level progress
                try { $reportService->update($report); } catch (Throwable $_) {}
                $this->emit('assistant.execution.stage.completed', [
                    'stage' => $entry['name'],
                    'executionId' => $context->getExecutionId(),
                    'duration_ms' => round((microtime(true) - $stageStart) * 1000, 2),
                ]);
            }

            $finalResponse = (string)($context->getFinalResponse() !== '' ? $context->getFinalResponse() : ($context->getProviderResponse()['text'] ?? ''));
            $context->setReport($report);
            $report->addMetadata('execution_id', $context->getExecutionId());
            $report->addMetadata('tenant_id', $context->getTenantId());
            $report->addMetadata('assistant_id', $context->getAssistantId());
            $report->addMetadata('conversation_id', $context->getConversationId());
            $report->addMetadata('workflow_id', $context->getWorkflow()['id'] ?? null);
            $report->addMetadata('provider', is_object($context->getProvider()) ? get_class($context->getProvider()) : null);
            $report->addMetadata('model', $context->getProviderInfo() ? $context->getProviderInfo()->getModel() : null);
            // finalize
            try { $reportService->finish($report); } catch (Throwable $_) { /* non-fatal */ }
            $this->emit('assistant.execution.completed', [
                'executionId' => $context->getExecutionId(),
                'response' => $finalResponse,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'toolPlan' => $context->getToolPlan(),
            ]);

            return new RuntimeExecutionResult(true, $finalResponse, $context->getMetadata(), $report, [
                'executionId' => $context->getExecutionId(),
                'toolPlan' => $context->getToolPlan(),
                'toolResults' => $context->getToolResults(),
            ]);
        } catch (Throwable $e) {
            $report->addError($e->getMessage());
            $report->addMetadata('execution_id', $context->getExecutionId());
            $report->addMetadata('tenant_id', $context->getTenantId());
            $report->addMetadata('assistant_id', $context->getAssistantId());
            $report->addMetadata('conversation_id', $context->getConversationId());
            try { $reportService->finish($report); } catch (Throwable $_) { /* non-fatal */ }
            $this->emit('assistant.execution.failed', [
                'executionId' => $context->getExecutionId(),
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
            return new RuntimeExecutionResult(false, '', ['error' => $e->getMessage()], $report, []);
        }
    }

    public function getStageRegistry(): AssistantExecutionStageRegistry
    {
        return $this->stageRegistry;
    }

    public function setStageRegistry(AssistantExecutionStageRegistry $stageRegistry): self
    {
        $this->stageRegistry = $stageRegistry;
        return $this;
    }

    public function registerStage(AssistantExecutionStageInterface $stage, ?string $name = null): self
    {
        if ($this->stageRegistry === null) {
            $this->stageRegistry = new AssistantExecutionStageRegistry();
        }

        $this->stageRegistry->register($stage, $name);
        return $this;
    }

    private function buildDefaultRegistry(): AssistantExecutionStageRegistry
    {
        $registry = new AssistantExecutionStageRegistry();
        $registry->register(new PlanningStage($this->toolServices), 'planning');
        $registry->register(new MemoryStage($this->memoryServices), 'memory');
        $registry->register(new ContextStage($this->contextServices), 'context');
        $registry->register(new PromptStage($this->promptServices), 'prompt');
        $registry->register(new ProviderRoutingStage($this->providerServices), 'providerRouting');
        $registry->register(new ToolExecutionStage($this->toolServices), 'toolExecution');
        $registry->register(new ModelExecutionStage($this->providerServices), 'modelExecution');
        $registry->register(new ResponseStage($this->responseServices), 'response');
        $registry->register(new PersistenceStage($this->memoryServices), 'persistence');
        return $registry;
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->serviceRegistry !== null) {
            $this->serviceRegistry->getEventEmitter()->emit($event, $payload);
        }
    }
}

class PlanningStage implements AssistantExecutionStageInterface
{
    private RuntimeToolServices $toolServices;

    public function __construct(RuntimeToolServices $toolServices)
    {
        $this->toolServices = $toolServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $message = (string)$context->getPayload()['message'] ?? '';
        $existingToolPlan = $context->getToolPlan();
        if (is_array($existingToolPlan) && !empty($existingToolPlan['toolId'])) {
            return $context;
        }

        $normalized = strtolower($message);
        $toolPlan = ['toolId' => null, 'arguments' => []];

        if (preg_match('/\b(?:execute|run)\s+workflow(?:\s+([a-z0-9._-]+))?(?:\s+(.*))?/i', $message, $m)) {
            $capturedWorkflowId = $m[1] ?? '';
            $workflowId = !empty($capturedWorkflowId) && !in_array(strtolower($capturedWorkflowId), ['for', 'me', 'please', 'the', 'a', 'an', 'and', 'this', 'that', 'with'], true)
                ? $capturedWorkflowId
                : 'default';
            $query = isset($m[2]) ? trim($m[2]) : $message;
            $toolPlan = ['toolId' => 'workflow_execute', 'arguments' => ['workflowId' => $workflowId, 'input' => ['query' => $query]]];
            $context = $context->withWorkflow(['id' => $workflowId]);
        } elseif (str_contains($normalized, 'log ') || str_contains($normalized, 'action ')) {
            $toolPlan = ['toolId' => 'dispatcher_action', 'arguments' => ['action' => 'log', 'payload' => ['message' => $message]]];
        }

        $context->setToolPlan($toolPlan);
        return $context;
    }

    public function priority(): int { return 200; }
}

class MemoryStage implements AssistantExecutionStageInterface
{
    private ?RuntimeMemoryServices $memoryServices;

    public function __construct(?RuntimeMemoryServices $memoryServices = null)
    {
        $this->memoryServices = $memoryServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $context->addMetadata('memory', ($this->memoryServices !== null && $this->memoryServices->getMemoryStore() !== null) ? 'available' : 'not-available');
        return $context;
    }

    public function priority(): int { return 180; }
}

class ContextStage implements AssistantExecutionStageInterface
{
    private RuntimeContextServices $contextServices;

    public function __construct(RuntimeContextServices $contextServices)
    {
        $this->contextServices = $contextServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $assistantContext = $context->getAssistantContext();
        $message = (string)($context->getPayload()['message'] ?? '');
        $assembledContext = $this->contextServices->getContextAssembler()->assemble($assistantContext, $message);
        $context->setAssembledContext($assembledContext);
        return $context;
    }

    public function priority(): int { return 160; }
}

class PromptStage implements AssistantExecutionStageInterface
{
    private RuntimePromptServices $promptServices;

    public function __construct(RuntimePromptServices $promptServices)
    {
        $this->promptServices = $promptServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $assistantContext = $context->getAssistantContext();
        $message = (string)($context->getPayload()['message'] ?? '');
        $provider = $context->getProvider() ?? null;
        $assembledContext = $context->getAssembledContext();
        $toolResult = $context->getToolResults() !== [] ? $context->getToolResults() : null;

        $promptAssembler = $this->promptServices->getPromptAssembler();
        $prompt = $promptAssembler->assemble($assistantContext, $message, $assembledContext, $toolResult, $provider);
        $context->setPrompt($prompt);

        return $context;
    }

    public function priority(): int { return 140; }
}

class ProviderRoutingStage implements AssistantExecutionStageInterface
{
    private RuntimeProviderServices $providerServices;

    public function __construct(RuntimeProviderServices $providerServices)
    {
        $this->providerServices = $providerServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $provider = $context->getProvider() ?? $this->providerServices->getProvider();
        $router = $this->providerServices->getProviderRouter();

        $toolPlan = $context->getToolPlan();
        $requiresToolSupport = is_array($toolPlan) && !empty($toolPlan['toolId']);

        if ($provider === null) {
            $providerInfo = $context->getProviderInfo();
            if ($providerInfo !== null) {
                $provider = $router->route($context);
            } elseif (!empty($router->listProviders())) {
                $providers = $router->listProviders();
                $provider = reset($providers) ?: null;
            }
        }

        // If a tool plan requires tool execution, prefer providers that declare tool support.
        if ($requiresToolSupport) {
            $candidate = $provider;
            $supports = $this->providerSupportsToolCalling($candidate);

            if ($candidate === null || !$supports) {
                foreach ($router->listProviders() as $p) {
                    if ($this->providerSupportsToolCalling($p)) {
                        $provider = $p;
                        break;
                    }
                }
            }

            if ($requiresToolSupport && ($provider === null || !$this->providerSupportsToolCalling($provider))) {
                ServiceHelpers::emitStructuredLog('assistant', 'warning', 'assistant.provider.tool_support_missing', [
                    'execution_id' => $context->getExecutionId(),
                    'tool_plan' => $toolPlan,
                ]);
                $context->addMetadata('provider_routing', ['tool_support_required' => true, 'tool_support_available' => false]);
            }
        }

        $trace = ServiceHelpers::getTraceMetadata();
        $requestId = ServiceHelpers::getOrCreateRequestId();

        if ($provider !== null) {
            $context->setProvider($provider);
            $capabilities = $this->resolveProviderCapabilities($provider);
            if (!empty($capabilities)) {
                $context->setProviderInfo(new ProviderInfo($capabilities));
                $context->setModelProfile(new ModelProfile($capabilities));
            }

            $emitter = $context->getEventEmitter();
            if ($emitter !== null) {
                $emitter->emit('assistant.provider.selected', [
                    'executionId' => $context->getExecutionId(),
                    'provider' => is_object($provider) ? get_class($provider) : null,
                    'model' => $capabilities['model'] ?? null,
                    'tenantId' => $context->getTenantId(),
                    'assistantId' => $context->getAssistantId(),
                    'trace' => $trace,
                    'requestId' => $requestId,
                ]);
            }
            ServiceHelpers::emitStructuredLog('assistant', 'info', 'assistant.provider.selected', [
                'event' => 'assistant.provider.selected',
                'execution_id' => $context->getExecutionId(),
                'provider' => is_object($provider) ? get_class($provider) : null,
                'model' => $capabilities['model'] ?? null,
                'tenant_id' => $context->getTenantId(),
                'assistant_id' => $context->getAssistantId(),
                'trace_id' => $trace['trace_id'] ?? null,
                'request_id' => $requestId,
            ]);
            ServiceHelpers::incrementMetric('assistant', 'assistant_provider_selected_total', ['model' => $capabilities['model'] ?? 'unknown']);
        }

        return $context;
    }

    private function resolveProviderCapabilities($provider): array
    {
        if (is_object($provider) && method_exists($provider, 'capabilities')) {
            $caps = $provider->capabilities();
            return is_array($caps) ? $caps : [];
        }

        if (is_array($provider) && isset($provider['capabilities']) && is_array($provider['capabilities'])) {
            return $provider['capabilities'];
        }

        return is_array($provider) ? $provider : [];
    }

    private function providerSupportsToolCalling($provider): bool
    {
        $caps = $this->resolveProviderCapabilities($provider);
        return (bool)($caps['supportsToolCalling'] ?? $caps['supportsTools'] ?? $caps['supports_tool_calling'] ?? $caps['supports_tools'] ?? false);
    }

    public function priority(): int { return 120; }
}

class ToolExecutionStage implements AssistantExecutionStageInterface
{
    private RuntimeToolServices $toolServices;

    public function __construct(RuntimeToolServices $toolServices)
    {
        $this->toolServices = $toolServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        $toolPlan = $context->getToolPlan();
        if (!is_array($toolPlan) || (($toolPlan['toolId'] ?? null) === null)) {
            return false;
        }

        $toolRegistry = $this->toolServices->getToolRegistry();
        if ($toolRegistry === null) {
            return false;
        }

        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $trace = ServiceHelpers::getTraceMetadata();
        $requestId = ServiceHelpers::getOrCreateRequestId();

        $toolPlan = $context->getToolPlan();
        $toolRegistry = $this->toolServices->getToolRegistry();
        if (!is_array($toolPlan) || $toolRegistry === null) {
            return $context;
        }

        $toolId = $toolPlan['toolId'] ?? null;
        if ($toolId !== null && $toolRegistry->has($toolId)) {
            $tool = $toolRegistry->get($toolId);
            $arguments = $toolPlan['arguments'] ?? [];
            if ($tool !== null) {
                $allowed = $this->toolServices->isToolAllowed($tool, $context);
                if (empty($allowed['allowed'])) {
                    $toolResult = [
                        'success' => false,
                        'error' => $allowed['error'] ?? 'tool_forbidden',
                    ];
                    $context->setToolResults($toolResult);
                    return $context;
                }

                $validation = $this->toolServices->validateToolArguments($tool, $arguments);
                if (!$validation['valid']) {
                    $toolResult = [
                        'success' => false,
                        'error' => 'tool_validation_failed',
                        'errors' => $validation['errors'],
                    ];
                    $context->setToolResults($toolResult);
                    return $context;
                }
            }

            try {
                // Emit invocation started
                $emitter = null;
                if (method_exists($this->toolServices, 'getEventEmitter')) {
                    $emitter = $this->toolServices->getEventEmitter();
                }
                if ($emitter !== null) {
                    $emitter->emit('tool.invocation.started', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'arguments' => $arguments, 'trace' => $trace, 'requestId' => $requestId]);
                    $emitter->emit('assistant.tool.invoked', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'arguments' => $arguments, 'trace' => $trace, 'requestId' => $requestId]);
                }

                $toolStart = microtime(true);
                // Attach trace/request/execution metadata to tool args so implementations
                // that make outbound calls can forward tracing headers.
                $arguments['__trace'] = $trace;
                $arguments['__request_id'] = $requestId;
                $arguments['__execution_id'] = $context->getExecutionId();
                $toolResult = $toolRegistry->invoke($toolId, $arguments);
                $toolDurationMs = round((microtime(true) - $toolStart) * 1000, 2);

                    if ($emitter !== null) {
                        $emitter->emit('tool.invocation.completed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'result' => $toolResult, 'trace' => $trace, 'requestId' => $requestId]);
                        $emitter->emit('assistant.tool.completed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'result' => $toolResult, 'duration_ms' => $toolDurationMs, 'trace' => $trace, 'requestId' => $requestId]);
                    }
                ServiceHelpers::emitStructuredLog('assistant', 'info', 'assistant.tool.completed', [
                    'event' => 'assistant.tool.completed',
                    'execution_id' => $context->getExecutionId(),
                    'tool_id' => $toolId,
                    'duration_ms' => $toolDurationMs,
                    'tenant_id' => $context->getTenantId(),
                    'trace_id' => $trace['trace_id'] ?? null,
                    'request_id' => $requestId,
                ]);
                ServiceHelpers::incrementMetric('assistant', 'assistant_tool_invocations_total', ['tool_id' => $toolId]);
                ServiceHelpers::observeMetric('assistant', 'assistant_tool_invocation_duration_seconds', ['tool_id' => $toolId], $toolDurationMs / 1000.0);
            } catch (Throwable $e) {
                $toolError = 'tool_invocation_error';
                if ($e instanceof ToolNotAllowedException || strpos($e->getMessage(), 'ToolNotAllowed:') === 0) {
                    $toolError = 'tool_forbidden';
                }
                $toolResult = [
                    'success' => false,
                    'error' => $toolError,
                    'exception' => $e->getMessage(),
                ];
                // Emit failed event and attach metadata
                if (isset($emitter) && $emitter !== null) {
                    $emitter->emit('tool.invocation.failed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'error' => $e->getMessage(), 'trace' => $e->__toString(), 'traceMeta' => $trace, 'requestId' => $requestId]);
                    $emitter->emit('assistant.tool.failed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'error' => $e->getMessage(), 'traceMeta' => $trace, 'requestId' => $requestId]);
                }
                ServiceHelpers::emitStructuredLog('assistant', 'error', 'assistant.tool.failed', [
                    'event' => 'assistant.tool.failed',
                    'execution_id' => $context->getExecutionId(),
                    'tool_id' => $toolId,
                    'error' => $e->getMessage(),
                    'tenant_id' => $context->getTenantId(),
                    'trace_id' => $trace['trace_id'] ?? null,
                    'request_id' => $requestId,
                ]);
                ServiceHelpers::incrementMetric('assistant', 'assistant_tool_errors_total', ['tool_id' => $toolId]);
                $context->addMetadata('tool_invocation_error', ['toolId' => $toolId, 'error' => $e->getMessage()]);
            }

            $context->setToolResults($toolResult);
        }

        return $context;
    }

    public function priority(): int { return 100; }
}

class ModelExecutionStage implements AssistantExecutionStageInterface
{
    private RuntimeProviderServices $providerServices;

    public function __construct(RuntimeProviderServices $providerServices)
    {
        $this->providerServices = $providerServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        $toolPlan = $context->getToolPlan();
        $toolResults = $context->getToolResults();

        if (is_array($toolPlan) && (($toolPlan['toolId'] ?? null) !== null) && is_array($toolResults) && !empty($toolResults) && !empty($toolResults['error'])) {
            return false;
        }

        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $prompt = $context->getPrompt();
        $provider = $context->getProvider() ?? $this->providerServices->getProvider();
        if ($provider !== null && $prompt !== '') {
            $providerClass = is_object($provider) ? get_class($provider) : null;
            $model = null;
            $providerInfo = $context->getProviderInfo();
            if ($providerInfo !== null) {
                $model = $providerInfo->getModel() ?? null;
            }

            $emitter = $context->getEventEmitter();
            $trace = ServiceHelpers::getTraceMetadata();
            $requestId = ServiceHelpers::getOrCreateRequestId();

            if ($emitter !== null) {
                $emitter->emit('assistant.provider.request.started', [
                    'executionId' => $context->getExecutionId(),
                    'provider' => $providerClass,
                    'model' => $model,
                    'tenantId' => $context->getTenantId(),
                    'assistantId' => $context->getAssistantId(),
                    'trace' => $trace,
                    'requestId' => $requestId,
                ]);
            }

            $providerStart = microtime(true);
            // Pass trace/request metadata to provider so it can forward headers on outbound calls
            $options = [
                'trace' => $trace,
                'request_id' => $requestId,
                'execution_id' => $context->getExecutionId(),
                'tenant_id' => $context->getTenantId(),
                'assistant_id' => $context->getAssistantId(),
                'conversation_id' => $context->getConversationId(),
                'workflow_id' => $context->getWorkflow()['id'] ?? null,
            ];
            $response = $provider->chat($prompt, $options);
            $durationMs = round((microtime(true) - $providerStart) * 1000, 2);
            $context->setProviderResponse($response);

            $providerMetadata = ProviderMetadata::fromArray([
                'providerName' => $providerClass ?: 'unknown',
                'model' => $model,
                'capabilities' => $providerInfo ? array_merge($providerInfo->getOptions(), [
                    'provider' => $providerInfo->getProvider(),
                    'model' => $providerInfo->getModel(),
                    'modelFamily' => $providerInfo->getModelFamily(),
                    'contextWindow' => $providerInfo->getContextWindow(),
                    'supportsTools' => $providerInfo->supportsTools(),
                    'supportsVision' => $providerInfo->supportsVision(),
                    'supportsJson' => $providerInfo->supportsJson(),
                    'supportsEmbeddings' => $providerInfo->supportsEmbeddings(),
                ]) : [],
                'pricingProfile' => $context->getMetadata()['pricing_profile'] ?? [],
                'endpoint' => $context->getMetadata()['provider_endpoint'] ?? null,
            ]);

            $promptTokens = intval($response['usage']['prompt_tokens'] ?? $response['prompt_tokens'] ?? 0);
            $completionTokens = intval($response['usage']['completion_tokens'] ?? $response['completion_tokens'] ?? 0);
            if ($promptTokens === 0 && $completionTokens === 0) {
                $estimation = $this->providerServices->getAIUsageService()->estimateUsage($providerMetadata->providerName, $providerMetadata->model, (string)$prompt, (string)($response['text'] ?? ''));
                $promptTokens = intval($estimation['promptTokens'] ?? 0);
                $completionTokens = intval($estimation['completionTokens'] ?? 0);
                $usageSource = $estimation['source'] ?? 'estimated';
            } else {
                $usageSource = 'provider';
            }

            $costResult = $this->providerServices->getAIUsageService()->calculateCost($providerMetadata->toArray(), $providerMetadata->model, $promptTokens, $completionTokens, $context->getTenantId());
            $estimatedCost = floatval($costResult['estimatedCost'] ?? 0.0);
            $currency = $costResult['currency'] ?? 'USD';
            $costSource = $costResult['source'] ?? 'estimated';

            if ($emitter !== null) {
                $emitter->emit('assistant.provider.request.completed', [
                    'executionId' => $context->getExecutionId(),
                    'provider' => $providerClass,
                    'model' => $model,
                    'tenantId' => $context->getTenantId(),
                    'assistantId' => $context->getAssistantId(),
                    'duration_ms' => $durationMs,
                    'response_length' => strlen((string)($response['text'] ?? '')),
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => $promptTokens + $completionTokens,
                    'estimated_cost' => $estimatedCost,
                    'currency' => $currency,
                    'usage_source' => $usageSource,
                    'cost_source' => $costSource,
                    'provider_metadata' => $providerMetadata->toArray(),
                    'trace' => $trace,
                    'requestId' => $requestId,
                ]);
            }
            ServiceHelpers::emitStructuredLog('assistant', 'info', 'assistant.provider.request.completed', [
                'event' => 'assistant.provider.request.completed',
                'execution_id' => $context->getExecutionId(),
                'provider' => $providerClass,
                'model' => $model,
                'duration_ms' => $durationMs,
                'tenant_id' => $context->getTenantId(),
                'assistant_id' => $context->getAssistantId(),
                'trace_id' => $trace['trace_id'] ?? null,
                'request_id' => $requestId,
                'response_length' => strlen((string)($response['text'] ?? '')),
            ]);
            ServiceHelpers::incrementMetric('assistant', 'assistant_provider_request_total', ['provider' => $providerClass ?? 'unknown', 'model' => $model ?? 'unknown']);
            ServiceHelpers::observeMetric('assistant', 'assistant_provider_request_duration_seconds', ['provider' => $providerClass ?? 'unknown', 'model' => $model ?? 'unknown'], $durationMs / 1000.0);
        }

        return $context;
    }

    public function priority(): int { return 80; }
}

class ResponseStage implements AssistantExecutionStageInterface
{
    private RuntimeResponseServices $responseServices;

    public function __construct(RuntimeResponseServices $responseServices)
    {
        $this->responseServices = $responseServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $response = $context->getProviderResponse();
        $toolResults = $context->getToolResults();

        // Prefer tool results when available
        if (is_array($toolResults) && !empty($toolResults)) {
            if (!empty($toolResults['success']) && array_key_exists('result', $toolResults)) {
                $result = $toolResults['result'];
                $final = is_array($result) ? json_encode($result) : (string)$result;
                $context->setFinalResponse($final);
                return $context;
            }

            if (!empty($toolResults['success']) && isset($toolResults['result'])) {
                $context->setFinalResponse((string)$toolResults['result']);
                return $context;
            }

            if (!empty($toolResults['error'])) {
                $context->setFinalResponse('Tool error: ' . (string)$toolResults['error']);
                return $context;
            }
        }

        // Otherwise, sanitize provider response text
        $text = (string)($response['text'] ?? '');
        $trimmed = trim($text);
        if ($trimmed === '') {
            $context->setFinalResponse('');
            return $context;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Prefer structured payload if available
            if (isset($decoded['payload'])) {
                $payload = $decoded['payload'];
                $context->setFinalResponse(is_array($payload) ? json_encode($payload) : (string)$payload);
                return $context;
            }
            // Otherwise, return JSON string
            $context->setFinalResponse(json_encode($decoded));
            return $context;
        }

        $context->setFinalResponse($trimmed);
        return $context;
    }

    public function priority(): int { return 60; }
}

class PersistenceStage implements AssistantExecutionStageInterface
{
    private ?RuntimeMemoryServices $memoryServices;

    public function __construct(?RuntimeMemoryServices $memoryServices = null)
    {
        $this->memoryServices = $memoryServices;
    }

    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool
    {
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        if ($this->memoryServices !== null && $this->memoryServices->getMemoryStore() !== null) {
            $context->addMetadata('persisted', true);
        }

        return $context;
    }

    public function priority(): int { return 40; }
}
