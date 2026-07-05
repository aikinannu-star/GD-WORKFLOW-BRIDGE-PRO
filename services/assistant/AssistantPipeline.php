<?php

require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/AssistantContext.php';
require_once __DIR__ . '/memory/MemoryStore.php';
require_once __DIR__ . '/memory/MemoryRetrievalService.php';
require_once __DIR__ . '/context/ContextAssembler.php';
require_once __DIR__ . '/context/PromptAssembler.php';
require_once __DIR__ . '/context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/context/RuntimeExecutionContext.php';
require_once __DIR__ . '/execution/AssistantExecutionPipeline.php';

class AssistantPipeline
{
    private ToolRegistry $toolRegistry;
    private ModelProviderInterface $provider;
    private RuntimeEventEmitter $eventEmitter;
    private ?MemoryStore $memoryStore;
    private ?MemoryRetrievalService $memoryRetrievalService;
    private ContextAssembler $contextAssembler;
    private PromptAssembler $promptAssembler;
    private ?PromptOptimizationPipeline $promptOptimizer;
    private AssistantExecutionPipeline $executionPipeline;

    public function __construct(ToolRegistry $toolRegistry, ModelProviderInterface $provider, RuntimeEventEmitter $eventEmitter, ?MemoryStore $memoryStore = null, ?MemoryRetrievalService $memoryRetrievalService = null, ?ContextAssembler $contextAssembler = null, ?PromptAssembler $promptAssembler = null)
    {
        $this->toolRegistry = $toolRegistry;
        $this->provider = $provider;
        $this->eventEmitter = $eventEmitter;
        $this->memoryStore = $memoryStore;
        $this->memoryRetrievalService = $memoryRetrievalService ?? ($memoryStore !== null ? new MemoryRetrievalService($memoryStore, $provider) : null);
        $this->contextAssembler = $contextAssembler ?? new ContextAssembler($this->memoryRetrievalService);
        $this->promptOptimizer = new PromptOptimizationPipeline();
        $this->promptAssembler = $promptAssembler ?? new PromptAssembler(null, $this->promptOptimizer);
        $this->executionPipeline = new AssistantExecutionPipeline($this->provider, $this->eventEmitter, null, $this->toolRegistry, $this->contextAssembler, $this->promptAssembler, $this->promptOptimizer, $this->memoryStore);
    }

    public function execute(AssistantContext $context, string $message): array
    {
        $runtimeContext = new RuntimeExecutionContext($context, null, null, ['message' => $message], [], [], [], [], $this->eventEmitter, $this->provider);
        $result = $this->executionPipeline->execute($runtimeContext);

        return [
            'success' => $result->isSuccessful(),
            'assistantText' => $result->getFinalResponse(),
            'tool' => $result->getPayload()['toolPlan']['toolId'] ?? null,
            'toolResult' => $result->getPayload()['toolResults'] ?? null,
            'raw' => $result->toArray(),
        ];
    }
}
