<?php

require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ToolInterface.php';
require_once __DIR__ . '/context/ContextAssembler.php';
require_once __DIR__ . '/context/PromptAssembler.php';
require_once __DIR__ . '/context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/context/ProviderRouter.php';
require_once __DIR__ . '/memory/MemoryStore.php';
require_once __DIR__ . '/memory/MemoryLifecyclePipeline.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';

class RuntimeServiceRegistry
{
    private ?ModelProviderInterface $provider;
    private ?RuntimeEventEmitter $eventEmitter;
    private ?ToolRegistry $toolRegistry;
    private ?ContextAssembler $contextAssembler;
    private ?PromptAssembler $promptAssembler;
    private ?PromptOptimizationPipeline $promptOptimizationPipeline;
    private ?ProviderRouter $providerRouter;
    private ?MemoryStore $memoryStore;
    private ?MemoryLifecyclePipeline $memoryLifecyclePipeline;
    private ?RuntimeContextServices $contextServices = null;
    private ?RuntimePromptServices $promptServices = null;
    private ?RuntimeProviderServices $providerServices = null;
    private ?RuntimeToolServices $toolServices = null;
    private ?RuntimeMemoryServices $memoryServices = null;
    private ?RuntimeResponseServices $responseServices = null;

    public function __construct(
        ?ModelProviderInterface $provider = null,
        ?RuntimeEventEmitter $eventEmitter = null,
        ?ToolRegistry $toolRegistry = null,
        ?ContextAssembler $contextAssembler = null,
        ?PromptAssembler $promptAssembler = null,
        ?PromptOptimizationPipeline $promptOptimizationPipeline = null,
        ?ProviderRouter $providerRouter = null,
        ?MemoryStore $memoryStore = null,
        ?MemoryLifecyclePipeline $memoryLifecyclePipeline = null
    ) {
        $this->provider = $provider;
        $this->eventEmitter = $eventEmitter;
        $this->toolRegistry = $toolRegistry;
        $this->contextAssembler = $contextAssembler;
        $this->promptAssembler = $promptAssembler;
        $this->promptOptimizationPipeline = $promptOptimizationPipeline;
        $this->providerRouter = $providerRouter;
        $this->memoryStore = $memoryStore;
        $this->memoryLifecyclePipeline = $memoryLifecyclePipeline;
    }

    public function getProvider(): ?ModelProviderInterface
    {
        return $this->provider;
    }

    public function contextServices(): RuntimeContextServices
    {
        return $this->contextServices ??= new RuntimeContextServices($this);
    }

    public function promptServices(): RuntimePromptServices
    {
        return $this->promptServices ??= new RuntimePromptServices($this);
    }

    public function providerServices(): RuntimeProviderServices
    {
        return $this->providerServices ??= new RuntimeProviderServices($this);
    }

    public function toolServices(): RuntimeToolServices
    {
        return $this->toolServices ??= new RuntimeToolServices($this);
    }

    public function memoryServices(): RuntimeMemoryServices
    {
        return $this->memoryServices ??= new RuntimeMemoryServices($this);
    }

    public function responseServices(): RuntimeResponseServices
    {
        return $this->responseServices ??= new RuntimeResponseServices($this);
    }

    public function getEventEmitter(): RuntimeEventEmitter
    {
        return $this->eventEmitter ??= new RuntimeEventEmitter();
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry ??= new ToolRegistry();
    }

    public function getContextAssembler(): ContextAssembler
    {
        return $this->contextAssembler ??= new ContextAssembler();
    }

    public function getPromptAssembler(): PromptAssembler
    {
        return $this->promptAssembler ??= new PromptAssembler(null, $this->getPromptOptimizationPipeline());
    }

    public function getPromptOptimizationPipeline(): PromptOptimizationPipeline
    {
        return $this->promptOptimizationPipeline ??= new PromptOptimizationPipeline([], $this->provider, null, null, null, $this->getProviderRouter(), null, $this->getEventEmitter());
    }

    public function getProviderRouter(): ProviderRouter
    {
        return $this->providerRouter ??= new ProviderRouter();
    }

    public function getMemoryStore(): ?MemoryStore
    {
        return $this->memoryStore;
    }

    public function getMemoryLifecyclePipeline(): ?MemoryLifecyclePipeline
    {
        if ($this->memoryLifecyclePipeline !== null) {
            return $this->memoryLifecyclePipeline;
        }

        if ($this->memoryStore !== null) {
            $this->memoryLifecyclePipeline = new MemoryLifecyclePipeline($this->memoryStore);
        }

        return $this->memoryLifecyclePipeline;
    }

    public function withProvider(?ModelProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function withEventEmitter(RuntimeEventEmitter $eventEmitter): self
    {
        $this->eventEmitter = $eventEmitter;
        return $this;
    }

    public function withToolRegistry(ToolRegistry $toolRegistry): self
    {
        $this->toolRegistry = $toolRegistry;
        return $this;
    }

    public function withContextAssembler(ContextAssembler $contextAssembler): self
    {
        $this->contextAssembler = $contextAssembler;
        return $this;
    }

    public function withPromptAssembler(PromptAssembler $promptAssembler): self
    {
        $this->promptAssembler = $promptAssembler;
        return $this;
    }

    public function withPromptOptimizationPipeline(PromptOptimizationPipeline $promptOptimizationPipeline): self
    {
        $this->promptOptimizationPipeline = $promptOptimizationPipeline;
        return $this;
    }

    public function withProviderRouter(ProviderRouter $providerRouter): self
    {
        $this->providerRouter = $providerRouter;
        return $this;
    }

    public function withMemoryStore(?MemoryStore $memoryStore): self
    {
        $this->memoryStore = $memoryStore;
        return $this;
    }

    public function withMemoryLifecyclePipeline(?MemoryLifecyclePipeline $memoryLifecyclePipeline): self
    {
        $this->memoryLifecyclePipeline = $memoryLifecyclePipeline;
        return $this;
    }
}

class RuntimeContextServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getContextAssembler(): ContextAssembler
    {
        return $this->registry->getContextAssembler();
    }
}

class RuntimePromptServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getPromptAssembler(): PromptAssembler
    {
        return $this->registry->getPromptAssembler();
    }

    public function getPromptOptimizationPipeline(): PromptOptimizationPipeline
    {
        return $this->registry->getPromptOptimizationPipeline();
    }
}

class RuntimeProviderServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getProvider(): ?ModelProviderInterface
    {
        return $this->registry->getProvider();
    }

    public function getProviderRouter(): ProviderRouter
    {
        return $this->registry->getProviderRouter();
    }
}

class RuntimeToolServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->registry->getToolRegistry();
    }

    public function validateToolArguments(ToolInterface $tool, array $arguments): array
    {
        $schema = $tool->inputSchema();
        $errors = [];

        if (!empty($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $arguments)) {
                    $errors[] = 'Missing required key: ' . $requiredKey;
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public function isToolAllowed(ToolInterface $tool, RuntimeExecutionContext $context): array
    {
        $opts = $context->getExecutionOptions();

        if (isset($opts['allowTools']) && $opts['allowTools'] === false) {
            return ['allowed' => false, 'error' => 'tools_disabled'];
        }

        if (!empty($opts['allowedToolIds']) && is_array($opts['allowedToolIds'])) {
            if (!in_array($tool->id(), $opts['allowedToolIds'], true)) {
                return ['allowed' => false, 'error' => 'tool_not_allowed'];
            }
        }

        return ['allowed' => true];
    }

    public function getEventEmitter(): RuntimeEventEmitter
    {
        return $this->registry->getEventEmitter();
    }
}

class RuntimeMemoryServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getMemoryStore(): ?MemoryStore
    {
        return $this->registry->getMemoryStore();
    }

    public function getMemoryLifecyclePipeline(): ?MemoryLifecyclePipeline
    {
        return $this->registry->getMemoryLifecyclePipeline();
    }
}

class RuntimeResponseServices
{
    private RuntimeServiceRegistry $registry;

    public function __construct(RuntimeServiceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getEventEmitter(): RuntimeEventEmitter
    {
        return $this->registry->getEventEmitter();
    }
}
