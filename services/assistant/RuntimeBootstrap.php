<?php

require_once __DIR__ . '/AssistantRuntime.php';
require_once __DIR__ . '/SessionRestorer.php';
require_once __DIR__ . '/AssistantManager.php';
require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/AssistantRegistry.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/OllamaProvider.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/../dispatcher/plugin/RuntimeRegistrar.php';
require_once __DIR__ . '/../dispatcher/actions/ActionRegistry.php';
require_once __DIR__ . '/../dispatcher/middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../dispatcher/workers/WorkerRegistry.php';
require_once __DIR__ . '/plugins/support-assistant/SupportAssistant.php';
require_once __DIR__ . '/memory/MemoryStore.php';
require_once __DIR__ . '/memory/MemoryPolicy.php';
require_once __DIR__ . '/memory/FileMemoryRepository.php';
require_once __DIR__ . '/tools/WorkflowTool.php';
require_once __DIR__ . '/tools/DispatcherActionTool.php';

class RuntimeBootstrap
{
    public static function bootstrap(array $config = []): array
    {
        // Initialize core services
        $eventEmitter = new RuntimeEventEmitter();
        $toolRegistry = new ToolRegistry();
        
        // Initialize model provider
        $modelProvider = self::createModelProvider($config);
        
        // Initialize conversation manager
        $conversationManager = new ConversationManager(
            null,
            $config['conversation_path'] ?? __DIR__ . '/../data/assistant/conversations',
            $eventEmitter
        );
        
        // Initialize memory store if configured
        $memoryStore = null;
        $memoryPolicy = null;
        if (($config['memory_repository'] ?? 'file') !== 'none') {
            $memoryStore = self::createMemoryStore($config);
            $memoryPolicy = self::createMemoryPolicy($config);
        }
        
        // Create assistant registry and manager
        $assistantRegistry = new AssistantRegistry();
        $assistantManager = new AssistantManager(null, $assistantRegistry);
        
        // Create dispatcher registrar for plugins
        $actionRegistry = new ActionRegistry();
        $middlewarePipeline = new MiddlewarePipeline();
        $workerRegistry = new WorkerRegistry();
        $dispatcherRegistrar = new RuntimeRegistrar($actionRegistry, $middlewarePipeline, $workerRegistry, $eventEmitter);
        
        // Register core assistant tools
        self::registerCoreTools($toolRegistry, $actionRegistry);
        
        // Bind assistant services to registrar for plugin access
        $dispatcherRegistrar->bind('tool_registry', $toolRegistry);
        $dispatcherRegistrar->bind('model_provider', $modelProvider);
        $dispatcherRegistrar->bind('conversation_manager', $conversationManager);
        $dispatcherRegistrar->bind('event_emitter', $eventEmitter);
        $dispatcherRegistrar->bind('assistant_manager', $assistantManager);
        if ($memoryStore) {
            $dispatcherRegistrar->bind('memory_store', $memoryStore);
            $dispatcherRegistrar->bind('memory_policy', $memoryPolicy);
        }
        
        // Load dispatcher plugins if path provided
        if (!empty($config['dispatcher_plugins_path'])) {
            self::loadDispatcherPlugins($config['dispatcher_plugins_path'], $dispatcherRegistrar, $assistantManager);
        }
        
        // Create and register support assistant
        $supportAssistant = new SupportAssistant(
            $toolRegistry,
            $modelProvider,
            $conversationManager,
            $eventEmitter,
            $memoryStore,
            $memoryPolicy
        );
        $assistantManager->registerAssistant($supportAssistant->id(), $supportAssistant);
        
        // Create runtime with full bootstrap configuration
        $runtime = new AssistantRuntime(
            $assistantManager,
            $assistantRegistry,
            $toolRegistry,
            $conversationManager,
            $modelProvider,
            $eventEmitter,
            null, // pluginManager
            $dispatcherRegistrar,
            null, // assistantLifecycle
            null, // memoryRepository
            $memoryStore,
            null  // memoryExtractor
        );
        
        // Create session restorer
        $sessionRestorer = new SessionRestorer($runtime);
        
        return [
            'runtime' => $runtime,
            'sessionRestorer' => $sessionRestorer,
            'toolRegistry' => $toolRegistry,
            'assistantManager' => $assistantManager,
            'assistantRegistry' => $assistantRegistry,
            'conversationManager' => $conversationManager,
            'eventEmitter' => $eventEmitter,
            'modelProvider' => $modelProvider,
            'memoryStore' => $memoryStore,
            'dispatcherRegistrar' => $dispatcherRegistrar,
        ];
    }
    
    private static function createModelProvider(array $config): ModelProviderInterface
    {
        if (!empty($config['model_provider']) && $config['model_provider'] instanceof ModelProviderInterface) {
            return $config['model_provider'];
        }

        $providerConfig = [
            'api_url' => $config['model_api_url'] ?? 'http://ollama:11434/v1/completions',
            'model' => $config['model_name'] ?? 'mistral',
            'max_tokens' => $config['max_tokens'] ?? 512,
            'temperature' => $config['temperature'] ?? 0.2,
            'timeout' => $config['timeout'] ?? 20,
        ];
        
        return new OllamaProvider($providerConfig);
    }
    
    private static function createMemoryStore(array $config): ?MemoryStore
    {
        $repositoryType = $config['memory_repository'] ?? 'file';
        $basePath = $config['memory_path'] ?? __DIR__ . '/../data/assistant/memory';
        
        if ($repositoryType === 'none') {
            return null;
        }
        
        // Create file memory repository
        $repository = new FileMemoryRepository($basePath);
        
        // Create memory store with the repository
        $memoryStore = new MemoryStore($repository);
        return $memoryStore;
    }
    
    private static function createMemoryPolicy(array $config): ?MemoryPolicy
    {
        $policy = new MemoryPolicy();
        
        // Configure retention
        if (!empty($config['memory_retention_days'])) {
            $policy->setRetentionDays($config['memory_retention_days']);
        }
        
        // Configure size limits
        if (!empty($config['memory_max_records'])) {
            $policy->setMaxRecords($config['memory_max_records']);
        }
        
        return $policy;
    }
    
    private static function registerCoreTools(ToolRegistry $toolRegistry, ActionRegistry $actionRegistry): void
    {
        // Register DispatcherActionTool
        $dispatcherActionTool = new DispatcherActionTool($actionRegistry);
        $toolRegistry->registerTool($dispatcherActionTool);
        
        // Register WorkflowTool - note: WorkflowExecutionService may not be available yet
        // This will be handled by the dispatcher plugins if needed
        try {
            require_once __DIR__ . '/../dispatcher/services/WorkflowExecutionService.php';
            $workflowExecutionService = new WorkflowExecutionService();
            $workflowTool = new WorkflowTool($workflowExecutionService);
            $toolRegistry->registerTool($workflowTool);
        } catch (Exception $e) {
            // WorkflowTool will be registered by dispatcher plugin if available
            error_log("WorkflowTool registration failed (will retry via plugin): " . $e->getMessage());
        }
    }
    
    private static function loadDispatcherPlugins(string $pluginsPath, RuntimeRegistrar $registrar, AssistantManager $assistantManager): void
    {
        if (!is_dir($pluginsPath)) {
            return;
        }
        
        $plugins = scandir($pluginsPath);
        foreach ($plugins as $plugin) {
            if ($plugin === '.' || $plugin === '..' || !is_dir($pluginsPath . '/' . $plugin)) {
                continue;
            }
            
            $entryFile = $pluginsPath . '/' . $plugin . '/' . ucfirst($plugin) . 'Entry.php';
            
            // Try alternate naming conventions
            if (!file_exists($entryFile)) {
                $entryFile = $pluginsPath . '/' . $plugin . '/Entry.php';
            }
            if (!file_exists($entryFile)) {
                $entryFile = $pluginsPath . '/' . $plugin . '/Plugin.php';
            }
            
            if (!file_exists($entryFile)) {
                continue;
            }
            
            try {
                require_once $entryFile;
                
                // Determine the class name
                $className = ucfirst($plugin) . 'Entry';
                if (!class_exists($className)) {
                    // Try alternatives
                    $classNameOptions = [
                        str_replace('-', '', ucwords($plugin, '-')) . 'Entry',
                        str_replace('-', '', ucwords($plugin, '-')) . 'Plugin',
                        'Entry',
                        'Plugin',
                    ];
                    
                    foreach ($classNameOptions as $option) {
                        if (class_exists($option)) {
                            $className = $option;
                            break;
                        }
                    }
                }
                
                if (!class_exists($className)) {
                    continue;
                }
                
                $pluginInstance = new $className();
                if (method_exists($pluginInstance, 'register')) {
                    $pluginInstance->register($registrar);
                }
            } catch (Exception $e) {
                // Log plugin load error but continue
                error_log("Failed to load plugin {$plugin}: " . $e->getMessage());
            }
        }
    }
}
