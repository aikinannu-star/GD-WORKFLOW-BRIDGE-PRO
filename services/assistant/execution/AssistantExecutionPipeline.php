<?php

require_once __DIR__ . '/AssistantExecutionStageInterface.php';
require_once __DIR__ . '/AssistantExecutionStageRegistry.php';
require_once __DIR__ . '/ExecutionReport.php';
require_once __DIR__ . '/RuntimeExecutionResult.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../context/ModelProfile.php';
require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/ContextAssembler.php';
require_once __DIR__ . '/../context/PromptAssembler.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';
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
        $report = new ExecutionReport('assistantExecution');
        $this->emit('assistant.execution.started', ['executionId' => $context->getExecutionId()]);

        try {
            $stages = $this->stageRegistry->getAllStages();
            foreach ($stages as $entry) {
                if (!$entry['stage']->supports($context, $this->providerServices->getProvider())) {
                    continue;
                }
                $this->emit('assistant.execution.stage.started', ['stage' => $entry['name']]);
                $context = $entry['stage']->execute($context);
                $report->addStageExecuted($entry['name'], ['priority' => $entry['stage']->priority()]);
                $this->emit('assistant.execution.stage.completed', ['stage' => $entry['name']]);
            }

            $finalResponse = (string)($context->getFinalResponse() !== '' ? $context->getFinalResponse() : ($context->getProviderResponse()['text'] ?? ''));
            $context->setReport($report);
            $report->finish();
            $this->emit('assistant.execution.completed', ['executionId' => $context->getExecutionId(), 'response' => $finalResponse]);

            return new RuntimeExecutionResult(true, $finalResponse, $context->getMetadata(), $report, [
                'executionId' => $context->getExecutionId(),
                'toolPlan' => $context->getToolPlan(),
                'toolResults' => $context->getToolResults(),
            ]);
        } catch (Throwable $e) {
            $report->addError($e->getMessage());
            $report->finish();
            $this->emit('assistant.execution.failed', ['executionId' => $context->getExecutionId(), 'error' => $e->getMessage()]);
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
            $workflowId = !empty($capturedWorkflowId) && !in_array(strtolower($capturedWorkflowId), ['for', 'me', 'please', 'the', 'a', 'an', 'this', 'that'], true)
                ? $capturedWorkflowId
                : 'default';
            $query = isset($m[2]) ? trim($m[2]) : $message;
            $toolPlan = ['toolId' => 'workflow_execute', 'arguments' => ['workflowId' => $workflowId, 'input' => ['query' => $query]]];
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
            $caps = is_object($candidate) && method_exists($candidate, 'capabilities') ? $candidate->capabilities() : null;
            $supports = is_array($caps) ? ($caps['supportsTools'] ?? true) : true;

            if ($candidate === null || !$supports) {
                foreach ($router->listProviders() as $p) {
                    $pcaps = is_object($p) && method_exists($p, 'capabilities') ? $p->capabilities() : null;
                    if (is_array($pcaps) && !empty($pcaps['supportsTools'])) {
                        $provider = $p;
                        break;
                    }
                }
            }
        }

        if ($provider !== null) {
            $context->setProvider($provider);
            $capabilities = is_array($provider) && isset($provider['capabilities']) ? $provider['capabilities'] : (is_object($provider) && method_exists($provider, 'capabilities') ? $provider->capabilities() : null);
            if (is_array($capabilities)) {
                $context->setProviderInfo(new ProviderInfo($capabilities));
                $context->setModelProfile(new ModelProfile($capabilities));
            }
        }

        return $context;
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
        return is_array($toolPlan) && (($toolPlan['toolId'] ?? null) !== null) && $this->toolServices->getToolRegistry() !== null;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
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
                    $emitter->emit('tool.invocation.started', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'arguments' => $arguments]);
                }

                $toolResult = $toolRegistry->invoke($toolId, $arguments);

                if ($emitter !== null) {
                    $emitter->emit('tool.invocation.completed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'result' => $toolResult]);
                }
            } catch (Throwable $e) {
                $toolResult = [
                    'success' => false,
                    'error' => 'tool_invocation_error',
                    'exception' => $e->getMessage(),
                ];
                // Emit failed event and attach metadata
                if (isset($emitter) && $emitter !== null) {
                    $emitter->emit('tool.invocation.failed', ['executionId' => $context->getExecutionId(), 'toolId' => $toolId, 'error' => $e->getMessage(), 'trace' => $e->__toString()]);
                }
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
        return true;
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext
    {
        $prompt = $context->getPrompt();
        $provider = $context->getProvider() ?? $this->providerServices->getProvider();
        if ($provider !== null && $prompt !== '') {
            $response = $provider->chat($prompt);
            $context->setProviderResponse($response);
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
