<?php

require_once __DIR__ . '/memory/MemoryRecord.php';
require_once __DIR__ . '/memory/MemoryRepositoryInterface.php';
require_once __DIR__ . '/memory/MemoryStore.php';
require_once __DIR__ . '/memory/MemoryExtractor.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/context/RuntimeExecutionContext.php';
require_once __DIR__ . '/execution/AssistantExecutionPipeline.php';
require_once __DIR__ . '/execution/RuntimeExecutionResult.php';
require_once __DIR__ . '/execution/ExecutionReport.php';
require_once __DIR__ . '/RuntimeServiceRegistry.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';

class AssistantRuntime
{
    public ?AssistantExecutionPipeline $executionPipeline = null;
    public ?ToolRegistry $toolRegistry = null;
    public ?ModelProviderInterface $modelProvider = null;
    public ?RuntimeServiceRegistry $serviceRegistry = null;
    public RuntimeEventEmitter $eventEmitter;
    public $assistantManager = null;
    public $assistantRegistry = null;
    public $conversationManager = null;
    public $registrar = null;
    public $pluginManager = null;
    public $assistantLifecycle = null;
    public $memoryRepository = null;
    public $memoryStore = null;
    public $memoryExtractor = null;

    public function __construct($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null, $arg6 = null, $arg7 = null, $arg8 = null, $arg9 = null, $arg10 = null, $arg11 = null, $arg12 = null)
    {
        if ($arg1 instanceof RuntimeServiceRegistry) {
            $this->serviceRegistry = $arg1;
            $this->modelProvider = $arg1->getProvider();
            $this->eventEmitter = $arg1->getEventEmitter();
            $this->toolRegistry = $arg1->getToolRegistry();
            $this->executionPipeline = new AssistantExecutionPipeline($this->modelProvider, $this->eventEmitter, null, $this->toolRegistry, null, null, null, null, $this->serviceRegistry);
            return;
        }

        // Bootstrap-style constructor: (AssistantManager, AssistantRegistry, ToolRegistry, ConversationManager, ModelProvider, EventEmitter, PluginManager, Registrar, AssistantLifecycle, MemoryRepository, MemoryStore, MemoryExtractor)
        if (is_object($arg1) && isset($arg1) && (get_class($arg1) === 'AssistantManager' || method_exists($arg1, 'handle'))) {
            $this->assistantManager = $arg1;
            $this->assistantRegistry = $arg2;
            $this->toolRegistry = $arg3;
            $this->conversationManager = $arg4;
            $this->modelProvider = $arg5;
            $this->eventEmitter = $arg6 instanceof RuntimeEventEmitter ? $arg6 : ($this->serviceRegistry?->getEventEmitter() ?? new RuntimeEventEmitter());
            $this->pluginManager = $arg7;
            $this->registrar = $arg8;
            $this->assistantLifecycle = $arg9;
            $this->memoryRepository = $arg10;
            $this->memoryStore = $arg11;
            $this->memoryExtractor = $arg12;

            $this->serviceRegistry = new RuntimeServiceRegistry($this->modelProvider, $this->eventEmitter, $this->toolRegistry, null, null, null, null, $this->memoryStore);
            $this->executionPipeline = new AssistantExecutionPipeline($this->modelProvider, $this->eventEmitter, null, $this->toolRegistry, null, null, null, $this->memoryStore, $this->serviceRegistry);
            return;
        }

        if ($arg1 instanceof ModelProviderInterface) {
            $this->modelProvider = $arg1;
            $this->eventEmitter = $arg2 instanceof RuntimeEventEmitter ? $arg2 : new RuntimeEventEmitter();
            $this->toolRegistry = $arg3 instanceof ToolRegistry ? $arg3 : new ToolRegistry();
            $this->serviceRegistry = new RuntimeServiceRegistry($this->modelProvider, $this->eventEmitter, $this->toolRegistry);
            $this->executionPipeline = new AssistantExecutionPipeline($this->modelProvider, $this->eventEmitter, null, $this->toolRegistry, null, null, null, null, $this->serviceRegistry);
            return;
        }

        $this->serviceRegistry = new RuntimeServiceRegistry();
        $this->eventEmitter = $this->serviceRegistry->getEventEmitter();
        $this->toolRegistry = $this->serviceRegistry->getToolRegistry();
        $this->modelProvider = $this->serviceRegistry->getProvider();
        $this->executionPipeline = new AssistantExecutionPipeline($this->modelProvider, $this->eventEmitter, null, $this->toolRegistry, null, null, null, null, $this->serviceRegistry);
    }

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionResult
    {
        if ($this->executionPipeline === null) {
            $this->executionPipeline = new AssistantExecutionPipeline($this->modelProvider, $this->eventEmitter, null, $this->toolRegistry);
        }

        return $this->executionPipeline->execute($context);
    }

    public function stream(RuntimeExecutionContext $context): iterable
    {
        yield $this->execute($context);
    }

    public function cancel(string $executionId): void
    {
        $this->eventEmitter->emit('assistant.execution.cancelled', ['executionId' => $executionId]);
    }

    public function diagnostics(): array
    {
        return [
            'provider' => $this->modelProvider !== null ? get_class($this->modelProvider) : null,
            'tools' => $this->toolRegistry?->listTools() ?? [],
            'executionPipeline' => $this->executionPipeline !== null ? 'ready' : 'not-ready',
            'serviceRegistry' => $this->serviceRegistry !== null ? 'ready' : 'not-ready',
        ];
    }
}
