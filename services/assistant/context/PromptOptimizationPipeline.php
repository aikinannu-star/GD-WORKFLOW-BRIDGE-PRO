<?php

require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/PromptOptimizationResult.php';
require_once __DIR__ . '/PromptOptimizationStrategy.php';
require_once __DIR__ . '/PromptOptimizationStageInterface.php';
require_once __DIR__ . '/PromptOptimizationStageRegistry.php';
require_once __DIR__ . '/PipelineReport.php';
require_once __DIR__ . '/OptimizationReport.php';
require_once __DIR__ . '/ProviderRegistry.php';
require_once __DIR__ . '/ModelRegistry.php';
require_once __DIR__ . '/ProviderRouter.php';
require_once __DIR__ . '/RuntimeExecutionContext.php';
require_once __DIR__ . '/PipelineEvents.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/PromptContext.php';
require_once __DIR__ . '/ProviderInfo.php';
require_once __DIR__ . '/ModelProfile.php';
require_once __DIR__ . '/ContextBudgeter.php';
require_once __DIR__ . '/TokenEstimator.php';
require_once __DIR__ . '/optimizers/GenericPromptOptimizer.php';
require_once __DIR__ . '/optimizers/LlamaPromptOptimizer.php';
require_once __DIR__ . '/optimizers/QwenPromptOptimizer.php';
require_once __DIR__ . '/optimizers/DeepSeekPromptOptimizer.php';
require_once __DIR__ . '/optimizers/OllamaProviderPromptOptimizer.php';
require_once __DIR__ . '/optimizers/VllmProviderPromptOptimizer.php';

class PromptOptimizationPipeline
{
    private PromptOptimizationStageRegistry $stageRegistry;
    private ?ModelProviderInterface $provider;
    private ?OptimizationReport $optimizationReport = null;
    private ?ContextBudgeter $budgeter;
    private ?ProviderRegistry $providerRegistry;
    private ?ModelRegistry $modelRegistry;
    private ProviderRouter $providerRouter;
    private ?RuntimeExecutionContext $executionContext;
    private RuntimeEventEmitter $eventEmitter;
    private TokenEstimator $estimator;

    public function __construct(array $stages = [], ?ModelProviderInterface $provider = null, ?PromptOptimizationStageRegistry $stageRegistry = null, ?ProviderRegistry $providerRegistry = null, ?ModelRegistry $modelRegistry = null, ?ProviderRouter $providerRouter = null, ?ContextBudgeter $budgeter = null, ?RuntimeEventEmitter $eventEmitter = null, ?RuntimeExecutionContext $executionContext = null)
    {
        $this->provider = $provider;
        $this->stageRegistry = $stageRegistry ?? $this->buildDefaultRegistry($stages);
        $this->providerRegistry = $providerRegistry ?? $this->buildDefaultProviderRegistry();
        $this->modelRegistry = $modelRegistry ?? $this->buildDefaultModelRegistry();
        $this->providerRouter = $providerRouter ?? new ProviderRouter($this->providerRegistry);
        $this->budgeter = $budgeter ?? new ContextBudgeter();
        $this->eventEmitter = $eventEmitter ?? new RuntimeEventEmitter();
        $this->executionContext = $executionContext;
        $this->estimator = new TokenEstimator();
    }

    public function registerStage(PromptOptimizationStageInterface $stage): void
    {
        $this->stageRegistry->register($stage);
    }

    public function getStageRegistry(): PromptOptimizationStageRegistry
    {
        return $this->stageRegistry;
    }

    public function unregisterStage(string $name): bool
    {
        return $this->stageRegistry->unregister($name);
    }

    public function report(): ?OptimizationReport
    {
        return $this->optimizationReport;
    }

    public function getOptimizationReport(): ?OptimizationReport
    {
        return $this->report();
    }

    public function diagnostics(): array
    {
        return $this->stageRegistry->getDiagnostics();
    }

    public function getProviderRouter(): ProviderRouter
    {
        return $this->providerRouter;
    }

    public function optimize(array $data, ModelProviderInterface $provider, $runtimeContext = null): PromptOptimizationResult
    {
        if ($runtimeContext instanceof RuntimeExecutionContext) {
            $executionContext = $runtimeContext;
        } elseif ($runtimeContext instanceof AssistantContext) {
            $executionContext = new RuntimeExecutionContext($runtimeContext);
        } else {
            $executionContext = new RuntimeExecutionContext(new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'));
        }

        $prompt = new PromptContext($data, null, null, $provider);
        $optimized = $this->optimizeContext($prompt, $executionContext, $provider);

        return new PromptOptimizationResult(
            $optimized->getContent(),
            $optimized->getMetadata()['format'] ?? 'plain',
            array_merge($optimized->getMetadata(), [
                'provider' => $optimized->getMetadata()['provider'] ?? $this->detectProviderName($provider),
                'audit' => $optimized->getAuditEntries(),
            ]),
            $this->optimizationReport
        );
    }

    public function optimizeContext(PromptContext $prompt, $executionContext, ?ModelProviderInterface $provider = null): PromptContext
    {
        if ($executionContext instanceof AssistantContext) {
            $executionContext = new RuntimeExecutionContext($executionContext);
        } elseif (!($executionContext instanceof RuntimeExecutionContext)) {
            $executionContext = new RuntimeExecutionContext(new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'));
        }

        $this->executionContext = $executionContext;

        $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_PIPELINE_STARTED, [
            'provider' => $provider ? $this->detectProviderName($provider) : null,
            'assistantId' => $executionContext->getAssistantContext()->assistantId,
            'sessionId' => $executionContext->getAssistantContext()->sessionId,
        ]);

        $effectiveProvider = $provider ?? $this->provider;
        $providerInfo = $this->resolveProviderInfo($effectiveProvider);
        $modelProfile = $this->resolveModelProfile($effectiveProvider);
        $this->optimizationReport = new OptimizationReport();

        $runtimeContext = $executionContext;
        if ($providerInfo !== null) {
            $runtimeContext = $runtimeContext->withProviderInfo($providerInfo);
        }
        if ($modelProfile !== null) {
            $runtimeContext = $runtimeContext->withModelProfile($modelProfile);
        }

        $prompt->setProvider($effectiveProvider);
        $prompt->setProviderInfo($providerInfo);
        $prompt->setModelProfile($modelProfile);

        if ($prompt->getContent() === '') {
            $prompt->setContent($this->renderFallback($prompt->getData()));
        }

        if ($prompt->getMetadata() === []) {
            $prompt->addMetadata('provider', $providerInfo?->getProvider() ?? $this->detectProviderName($effectiveProvider));
            $prompt->addMetadata('format', 'plain');
        }

        $sections = $prompt->getDataValue('sections', []);
        if (is_array($sections) && $this->budgeter !== null) {
            $maxTokens = $modelProfile !== null ? min(4000, max(256, $modelProfile->getContextWindow())) : 4000;
            $budgetResult = $this->budgeter->budget($sections, (string)$prompt->getDataValue('message', ''), ['maxTokens' => $maxTokens]);
            $prompt->setDataValue('sections', $budgetResult['sections']);
            $prompt->addMetadata('budget', $budgetResult['metadata']);
            $this->optimizationReport->addMessage('info', 'Applied context budgeting before optimization');
        }

        $activeStages = $this->stageRegistry->getActiveStages($runtimeContext->getAssistantContext(), $effectiveProvider, $providerInfo, $modelProfile);
        if ($activeStages === []) {
            $prompt->recordChange('pipeline', 'no matching optimization stage applied');
            $this->optimizationReport->addSkippedStage('pipeline', ['reason' => 'no active stages']);
            $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_PIPELINE_STAGE_SKIPPED, ['reason' => 'no active stages']);
        }

        $prompt = $this->applyStages($prompt, $activeStages, $runtimeContext);

        $estimatedTokens = $this->estimatePromptTokens($prompt->getContent());
        if ($modelProfile !== null && $estimatedTokens > $modelProfile->getContextWindow()) {
            $prompt = $this->validateBudgetAfterOptimization($prompt, $runtimeContext, $effectiveProvider, $activeStages, $modelProfile);
            $estimatedTokens = $this->estimatePromptTokens($prompt->getContent());
        }

        $this->optimizationReport->setStatistics([
            'originalChars' => strlen($this->renderFallback($prompt->getData())),
            'optimizedChars' => strlen($prompt->getContent()),
            'estimatedTokens' => $estimatedTokens,
            'activeStageCount' => count($activeStages),
            'contextWindow' => $modelProfile?->getContextWindow() ?? null,
        ]);

        $this->optimizationReport->finish();
        $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_PIPELINE_COMPLETED, [
            'provider' => $effectiveProvider ? $this->detectProviderName($effectiveProvider) : null,
            'estimatedTokens' => $estimatedTokens,
            'activeStageCount' => count($activeStages),
        ]);

        return $prompt;
    }

    private function buildDefaultRegistry(array $stages = []): PromptOptimizationStageRegistry
    {
        if ($stages !== []) {
            return new PromptOptimizationStageRegistry($stages);
        }

        return new PromptOptimizationStageRegistry([
            new LegacyPromptOptimizationStageAdapter(new GenericPromptOptimizer()),
            new LegacyPromptOptimizationStageAdapter(new LlamaPromptOptimizer()),
            new LegacyPromptOptimizationStageAdapter(new QwenPromptOptimizer()),
            new LegacyPromptOptimizationStageAdapter(new DeepSeekPromptOptimizer()),
            new LegacyPromptOptimizationStageAdapter(new OllamaProviderPromptOptimizer()),
            new LegacyPromptOptimizationStageAdapter(new VllmProviderPromptOptimizer()),
        ]);
    }

    private function buildDefaultProviderRegistry(): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->register('ollama', ['provider' => 'ollama', 'modelFamily' => 'llama', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true]);
        $registry->register('vllm', ['provider' => 'vllm', 'modelFamily' => 'qwen', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true]);
        $registry->register('openai', ['provider' => 'openai', 'modelFamily' => 'gpt', 'contextWindow' => 128000, 'supportsTools' => true, 'supportsVision' => true, 'supportsJson' => true, 'supportsEmbeddings' => true]);
        $registry->register('anthropic', ['provider' => 'anthropic', 'modelFamily' => 'claude', 'contextWindow' => 200000, 'supportsTools' => true, 'supportsVision' => true, 'supportsJson' => true, 'supportsEmbeddings' => false]);
        $registry->register('gemini', ['provider' => 'gemini', 'modelFamily' => 'gemini', 'contextWindow' => 1000000, 'supportsTools' => true, 'supportsVision' => true, 'supportsJson' => true, 'supportsEmbeddings' => true]);
        return $registry;
    }

    private function buildDefaultModelRegistry(): ModelRegistry
    {
        $registry = new ModelRegistry();
        $registry->register('llama', ['modelFamily' => 'llama', 'provider' => 'ollama', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        $registry->register('qwen', ['modelFamily' => 'qwen', 'provider' => 'vllm', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        $registry->register('deepseek', ['modelFamily' => 'deepseek', 'provider' => 'vllm', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        $registry->register('mistral', ['modelFamily' => 'mistral', 'provider' => 'ollama', 'contextWindow' => 32768, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        $registry->register('gemma', ['modelFamily' => 'gemma', 'provider' => 'ollama', 'contextWindow' => 8192, 'supportsTools' => false, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => false, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        $registry->register('phi', ['modelFamily' => 'phi', 'provider' => 'ollama', 'contextWindow' => 16384, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true, 'preferredInstructionStyle' => 'plain', 'preferredOutputStyle' => 'plain']);
        return $registry;
    }

    private function resolveProviderInfo(?ModelProviderInterface $provider): ?ProviderInfo
    {
        if ($provider === null) {
            return null;
        }

        $capabilities = $provider->capabilities();
        if (!is_array($capabilities)) {
            return null;
        }

        $providerName = (string)($capabilities['provider'] ?? '');
        if ($providerName !== '' && $this->providerRegistry !== null && $this->providerRegistry->has($providerName)) {
            return $this->providerRegistry->get($providerName);
        }

        return new ProviderInfo($capabilities);
    }

    private function resolveModelProfile(?ModelProviderInterface $provider): ?ModelProfile
    {
        if ($provider === null) {
            return null;
        }

        $capabilities = $provider->capabilities();
        if (!is_array($capabilities)) {
            return null;
        }

        $modelName = (string)($capabilities['model'] ?? '');
        if ($modelName !== '' && $this->modelRegistry !== null) {
            $profile = $this->modelRegistry->get($modelName);
            if ($profile !== null) {
                return $profile;
            }
        }

        $family = isset($capabilities['modelFamily']) ? (string)$capabilities['modelFamily'] : $this->inferModelFamily($modelName);
        $capabilities['modelFamily'] = $family;
        return new ModelProfile($capabilities);
    }

    private function inferModelFamily(string $modelName): string
    {
        $name = strtolower($modelName);
        if (str_contains($name, 'llama')) { return 'llama'; }
        if (str_contains($name, 'qwen')) { return 'qwen'; }
        if (str_contains($name, 'deepseek')) { return 'deepseek'; }
        if (str_contains($name, 'mistral')) { return 'mistral'; }
        if (str_contains($name, 'gemma')) { return 'gemma'; }
        if (str_contains($name, 'phi')) { return 'phi'; }
        return 'generic';
    }

    private function applyStages(PromptContext $prompt, array $activeStages, RuntimeExecutionContext $runtimeContext): PromptContext
    {
        foreach ($activeStages as $entry) {
            $stage = $entry['stage'];
            $stageName = $entry['name'] ?? get_class($stage);
            $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_STAGE_STARTED, ['stage' => $stageName]);
            $prompt = $stage->optimize($prompt);
            $this->optimizationReport->addAppliedStage($stageName, [
                'priority' => $stage->priority(),
            ]);
            $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_STAGE_COMPLETED, ['stage' => $stageName]);
        }

        return $prompt;
    }

    private function estimatePromptTokens(string $prompt): int
    {
        return $this->estimator->estimateTokens($prompt);
    }

    private function validateBudgetAfterOptimization(PromptContext $prompt, RuntimeExecutionContext $runtimeContext, ?ModelProviderInterface $provider, array $activeStages, ModelProfile $modelProfile): PromptContext
    {
        $this->optimizationReport->addMessage('warning', 'Prompt exceeded model context window during validation');
        $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_VALIDATION_STARTED, ['contextWindow' => $modelProfile->getContextWindow()]);

        $sections = $prompt->getDataValue('sections', []);
        $message = (string)$prompt->getDataValue('message', '');
        $targetTokens = max(256, $modelProfile->getContextWindow() - 256);
        $budgetResult = $this->budgeter->budget($sections, $message, ['maxTokens' => $targetTokens, 'reserveTokens' => 256]);

        $prompt->setDataValue('sections', $budgetResult['sections']);
        $prompt->addMetadata('budget', $budgetResult['metadata']);
        $prompt->recordChange('budget', 're-budgeted after validation overflow');
        $this->optimizationReport->addMessage('info', 'Re-budgeted sections after overflow validation');

        $resetPrompt = new PromptContext($prompt->getData(), $prompt->getProviderInfo(), $prompt->getModelProfile(), $provider, $this->renderFallback($prompt->getData()));
        $newPrompt = $this->applyStages($resetPrompt, $activeStages, $runtimeContext);
        $this->emit(PipelineEvents::PROMPT_OPTIMIZATION_VALIDATION_COMPLETED, ['estimatedTokens' => $this->estimatePromptTokens($newPrompt->getContent())]);

        return $newPrompt;
    }

    private function renderFallback(array $data): string
    {
        $lines = [
            $data['instructions'] ?? 'Assistant: process a user message using available tools.',
            'AssistantId: ' . ($data['assistantId'] ?? ''),
            'SessionId: ' . ($data['sessionId'] ?? ''),
            'UserId: ' . ($data['userId'] ?? ''),
            'Message: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $lines[] = ($section['label'] ?? 'Context') . ': ' . $content;
        }

        if (!empty($data['toolResult'])) {
            $lines[] = 'Tool result: ' . json_encode($data['toolResult']);
        }

        return implode("\n", $lines);
    }

    private function emit(string $event, array $payload = []): void
    {
        $emitter = $this->eventEmitter;
        if ($emitter === null && $this->executionContext !== null) {
            $emitter = $this->executionContext->getEventEmitter();
        }

        if ($emitter !== null) {
            $emitter->emit($event, $payload);
        }
    }

    private function detectProviderName(?ModelProviderInterface $provider): string
    {
        if ($provider === null) {
            return 'local';
        }

        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return (string)$capabilities['provider'];
        }

        $class = get_class($provider);
        if (str_contains($class, 'Ollama')) {
            return 'ollama';
        }
        if (str_contains($class, 'Claude')) {
            return 'claude';
        }
        if (str_contains($class, 'Gemini')) {
            return 'gemini';
        }
        if (str_contains($class, 'GPT')) {
            return 'gpt';
        }
        return 'local';
    }
}

class LegacyPromptOptimizationStageAdapter implements PromptOptimizationStageInterface
{
    private PromptOptimizationStrategy $strategy;

    public function __construct(PromptOptimizationStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    public function supports(AssistantContext $context, ModelProviderInterface $provider, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): bool
    {
        return $this->strategy->supports($provider);
    }

    public function optimize(PromptContext $prompt): PromptContext
    {
        $provider = $prompt->getProvider();
        $previous = $this->buildPreviousResult($prompt);
        $result = $this->strategy->optimize($prompt->getData(), $provider ?? new class implements ModelProviderInterface {
            public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => '']; }
            public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => '']; }
            public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
            public function health(): array { return ['success' => true]; }
            public function capabilities(): array { return []; }
        }, $previous);

        $prompt->setContent($result->getPrompt());
        $prompt->setMetadata(array_merge($prompt->getMetadata(), $result->getMetadata()));
        $prompt->addMetadata('format', $result->getFormat());
        $prompt->recordChange($this->stageName(), 'applied legacy optimizer');

        return $prompt;
    }

    public function priority(): int
    {
        $class = get_class($this->strategy);
        if (str_contains($class, 'Generic')) {
            return 100;
        }
        if (str_contains($class, 'Provider')) {
            return 300;
        }

        return 200;
    }

    private function buildPreviousResult(PromptContext $prompt): ?PromptOptimizationResult
    {
        if ($prompt->getContent() === '') {
            return null;
        }

        return new PromptOptimizationResult(
            $prompt->getContent(),
            $prompt->getMetadata()['format'] ?? 'plain',
            $prompt->getMetadata()
        );
    }

    private function stageName(): string
    {
        $class = get_class($this->strategy);
        if (str_contains($class, 'Generic')) {
            return 'generic';
        }
        if (str_contains($class, 'Provider')) {
            return 'provider';
        }
        return 'model';
    }
}
