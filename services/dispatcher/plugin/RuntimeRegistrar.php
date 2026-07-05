<?php
require_once __DIR__ . '/../actions/ActionRegistry.php';
require_once __DIR__ . '/../middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../workers/WorkerRegistry.php';
require_once __DIR__ . '/../events/RuntimeEventEmitter.php';
require_once __DIR__ . '/../queue/QueueInterface.php';
require_once __DIR__ . '/../locking/LockProviderInterface.php';
require_once __DIR__ . '/../metrics/MetricsCollectorInterface.php';
require_once __DIR__ . '/PermissionEnforcer.php';

class RuntimeRegistrar
{
    private $actionRegistry;
    private $middlewarePipeline;
    private $workerRegistry;
    private $eventEmitter;
    private $bindings = [];
    private $activePluginId = null;
    private $activePluginPermissions = [];
    private $permissionEnforcer;

    public function __construct(
        ActionRegistry $actionRegistry,
        MiddlewarePipeline $middlewarePipeline,
        WorkerRegistry $workerRegistry,
        RuntimeEventEmitter $eventEmitter
    ) {
        $this->actionRegistry = $actionRegistry;
        $this->middlewarePipeline = $middlewarePipeline;
        $this->workerRegistry = $workerRegistry;
        $this->eventEmitter = $eventEmitter;
        $this->permissionEnforcer = new PermissionEnforcer();
        $this->actionRegistry->setPermissionEnforcer($this->permissionEnforcer);
    }

    public function setPermissionEnforcer(PermissionEnforcer $permissionEnforcer): void
    {
        $this->permissionEnforcer = $permissionEnforcer;
        $this->actionRegistry->setPermissionEnforcer($permissionEnforcer);
    }

    public function setActivePlugin(string $pluginId, array $permissions = []): void
    {
        $this->activePluginId = $pluginId;
        $this->activePluginPermissions = $permissions;
        if ($this->permissionEnforcer) {
            $this->permissionEnforcer->grant($pluginId, $permissions);
        }
    }

    public function clearActivePlugin(): void
    {
        $this->activePluginId = null;
        $this->activePluginPermissions = [];
    }

    public function registerAction(string $name, $action): void
    {
        $this->actionRegistry->register($name, $action, $this->activePluginPermissions, $this->activePluginId);
    }

    public function registerMiddleware($middleware): void
    {
        $this->middlewarePipeline->add($middleware);
    }

    public function registerWorker(string $name, $worker): void
    {
        $this->workerRegistry->register($name, $worker);
    }

    public function registerEventListener(string $event, callable $listener): void
    {
        $this->eventEmitter->on($event, $listener);
    }

    public function bind(string $key, $value): void
    {
        $this->bindings[$key] = $value;

        // Flush pending assistants when assistant manager becomes available
        if ($key === 'assistant_manager' && isset($this->bindings['pending_assistants'])) {
            foreach ($this->bindings['pending_assistants'] as $assistant) {
                if (method_exists($value, 'registerAssistant')) {
                    $value->registerAssistant($assistant->id(), $assistant);
                }
            }
            unset($this->bindings['pending_assistants']);
        }

        // Flush pending tools when tool registry becomes available
        if ($key === 'tool_registry' && isset($this->bindings['pending_tools'])) {
            foreach ($this->bindings['pending_tools'] as $t) {
                if (method_exists($value, 'registerTool')) {
                    $value->registerTool($t['tool']);
                }
            }
            unset($this->bindings['pending_tools']);
        }
    }

    public function get(string $key)
    {
        return $this->bindings[$key] ?? null;
    }

    /**
     * Register an assistant instance via the bound assistant manager if available.
     */
    public function registerAssistant($assistant): void
    {
        $manager = $this->get('assistant_manager');
        if ($manager && method_exists($manager, 'registerAssistant')) {
            $manager->registerAssistant($assistant->id(), $assistant);
            return;
        }

        // queue for later registration
        if (!isset($this->bindings['pending_assistants'])) {
            $this->bindings['pending_assistants'] = [];
        }
        $this->bindings['pending_assistants'][] = $assistant;
    }

    /**
     * Register a tool via the bound tool registry if available.
     */
    public function registerTool($tool): void
    {
        $toolRegistry = $this->get('tool_registry');
        if ($toolRegistry && method_exists($toolRegistry, 'registerTool')) {
            $toolRegistry->registerTool($tool);
            return;
        }

        if (!isset($this->bindings['pending_tools'])) {
            $this->bindings['pending_tools'] = [];
        }
        $this->bindings['pending_tools'][] = ['tool' => $tool];
    }
}
