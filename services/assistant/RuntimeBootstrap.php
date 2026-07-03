<?php

require_once __DIR__ . '/../dispatcher/plugin/PluginManager.php';
require_once __DIR__ . '/../dispatcher/plugin/PluginLoader.php';
require_once __DIR__ . '/../dispatcher/plugin/RuntimeRegistrar.php';
require_once __DIR__ . '/../dispatcher/actions/ActionRegistry.php';
require_once __DIR__ . '/../dispatcher/actions/LogAction.php';
require_once __DIR__ . '/../dispatcher/actions/DelayAction.php';
require_once __DIR__ . '/../dispatcher/actions/SetVariableAction.php';
require_once __DIR__ . '/../dispatcher/actions/HttpAction.php';
require_once __DIR__ . '/../dispatcher/actions/ConditionAction.php';
require_once __DIR__ . '/../dispatcher/middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../dispatcher/workers/WorkerRegistry.php';
require_once __DIR__ . '/../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/repositories/FileConversationRepository.php';
require_once __DIR__ . '/AssistantRuntime.php';

require_once __DIR__ . '/AssistantManager.php';
require_once __DIR__ . '/AssistantRegistry.php';
require_once __DIR__ . '/AssistantLifecycleManager.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/LocalModelProvider.php';
require_once __DIR__ . '/tools/DispatcherActionTool.php';
require_once __DIR__ . '/tools/WorkflowTool.php';
require_once __DIR__ . '/../dispatcher/services/WorkflowExecutionService.php';
require_once __DIR__ . '/SessionRestorer.php';
require_once __DIR__ . '/ConversationMetadata.php';
require_once __DIR__ . '/context/TokenEstimator.php';
require_once __DIR__ . '/execution/UsageEstimatorInterface.php';
require_once __DIR__ . '/execution/DefaultUsageEstimator.php';
require_once __DIR__ . '/execution/CostCalculatorInterface.php';
require_once __DIR__ . '/execution/DefaultCostCalculator.php';
require_once __DIR__ . '/execution/AIUsageServiceInterface.php';
require_once __DIR__ . '/execution/DefaultAIUsageService.php';
require_once __DIR__ . '/execution/ProviderMetadataRegistry.php';
require_once __DIR__ . '/execution/ProviderMetadata.php';
require_once __DIR__ . '/context/ContextPolicy.php';
require_once __DIR__ . '/context/ConversationSummaryRepository.php';
require_once __DIR__ . '/context/ConversationSummarizer.php';
require_once __DIR__ . '/context/ContextWindowManager.php';
require_once __DIR__ . '/memory/MemoryRecord.php';
require_once __DIR__ . '/memory/MemoryRepositoryInterface.php';
require_once __DIR__ . '/memory/FileMemoryRepository.php';
require_once __DIR__ . '/memory/SqlMemoryRepository.php';
require_once __DIR__ . '/memory/HybridMemoryRepository.php';
require_once __DIR__ . '/memory/VectorMemoryRepository.php';require_once __DIR__ . '/memory/MemoryRepositoryFactory.php';require_once __DIR__ . '/memory/MemoryStore.php';
require_once __DIR__ . '/memory/MemoryExtractor.php';
require_once __DIR__ . '/memory/MemoryPolicy.php';
require_once __DIR__ . '/memory/MemoryExtractionListener.php';
require_once __DIR__ . '/memory/MemoryRetrievalService.php';
require_once __DIR__ . '/execution/ExecutionReportRepositoryInterface.php';
require_once __DIR__ . '/execution/FileExecutionReportRepository.php';
require_once __DIR__ . '/execution/PostgresExecutionReportRepository.php';
require_once __DIR__ . '/execution/ProviderCapabilities.php';

class RuntimeBootstrap
{
    public static function bootstrap(array $options = []): array
    {
        $dispatcherPluginsPath = $options['dispatcher_plugins_path'] ?? __DIR__ . '/../dispatcher/plugins';

        $actionRegistry = new ActionRegistry();
        $actionRegistry->register('log', new LogAction());
        $actionRegistry->register('delay', new DelayAction());
        $actionRegistry->register('set_variable', new SetVariableAction());
        $actionRegistry->register('http_request', new HttpAction());
        $actionRegistry->register('condition', new ConditionAction());

        $middlewarePipeline = new MiddlewarePipeline();
        $workerRegistry = new WorkerRegistry();
        $eventEmitter = new RuntimeEventEmitter();

        $registrar = new RuntimeRegistrar($actionRegistry, $middlewarePipeline, $workerRegistry, $eventEmitter);

        $assistantRegistry = new AssistantRegistry();
        $assistantManager = new AssistantManager(null, $assistantRegistry);
        $toolRegistry = new ToolRegistry();
        $conversationPath = $options['conversation_path'] ?? null;
        $conversationManager = new ConversationManager(new FileConversationRepository($conversationPath), null, $eventEmitter);
        $assistantLifecycle = new AssistantLifecycleManager($assistantManager, $assistantRegistry);

        $provider = $options['model_provider'] ?? new LocalModelProvider();

        // Register built-in dispatcher-backed assistant tools
        $toolRegistry->registerTool(new DispatcherActionTool($actionRegistry));
        $toolRegistry->registerTool(new WorkflowTool(new WorkflowExecutionService()));

        $memoryRepository = MemoryRepositoryFactory::create($options);
        $memoryPolicy = isset($options['memory_policy'])
            ? MemoryPolicy::fromArray($options['memory_policy'])
            : new MemoryPolicy();
        $memoryStore = new MemoryStore($memoryRepository, $eventEmitter);
        $memoryRetrievalService = new MemoryRetrievalService($memoryStore, $provider);
        $memoryExtractor = new MemoryExtractor($memoryRepository, $memoryPolicy, $memoryStore, $provider);
        $memoryExtractionListener = new MemoryExtractionListener($memoryExtractor, $memoryPolicy);
        $eventEmitter->on('conversation.completed', $memoryExtractionListener);

        // Initialize usage and cost services early for registrar binding
        $usageEstimator = new DefaultUsageEstimator($options['tokens_per_word'] ?? 1.3);
        $costCalculator = new DefaultCostCalculator();
        $providerMetadataRegistry = new ProviderMetadataRegistry();

        // Register default provider metadata with capabilities
        $providerMetadataRegistry->register(new ProviderMetadata(
            'openai',
            null,
            ProviderCapabilities::openai(),
            [
                'currency' => 'USD',
                'prompt_per_1k' => 0.03,
                'completion_per_1k' => 0.06,
            ],
            'https://api.openai.com/v1/chat/completions'
        ));

        $providerMetadataRegistry->register(new ProviderMetadata(
            'anthropic',
            null,
            ProviderCapabilities::anthropic(),
            [
                'currency' => 'USD',
                'prompt_per_1k' => 0.0008,
                'completion_per_1k' => 0.0024,
            ],
            'https://api.anthropic.com/v1/messages'
        ));

        $providerMetadataRegistry->register(new ProviderMetadata(
            'azure_openai',
            null,
            ProviderCapabilities::azureOpenai(),
            [
                'currency' => 'USD',
                'prompt_per_1k' => 0.03,
                'completion_per_1k' => 0.06,
            ],
            null // Endpoint configured via environment
        ));

        $providerMetadataRegistry->register(new ProviderMetadata(
            'cohere',
            null,
            ProviderCapabilities::cohere(),
            [
                'currency' => 'USD',
                'prompt_per_1k' => 0.0015,
                'completion_per_1k' => 0.0015,
            ],
            'https://api.cohere.ai/v1/generate'
        ));

        $providerMetadataRegistry->register(new ProviderMetadata(
            'local',
            null,
            // Local provider: enable tool calling by default so built-in
            // dispatcher-backed tools (workflows, actions) are available
            // during test and dev environments.
            ProviderCapabilities::local()->withToolCalling(true),
            [],
            null
        ));

        $aiUsageService = new DefaultAIUsageService($usageEstimator, $costCalculator, $providerMetadataRegistry);

        // Bind managers into the registrar so plugins can register assistants/tools
        $registrar->bind('assistant_manager', $assistantManager);
        $registrar->bind('tool_registry', $toolRegistry);
        $registrar->bind('conversation_manager', $conversationManager);
        $registrar->bind('assistant_lifecycle', $assistantLifecycle);
        $registrar->bind('model_provider', $provider);
        $registrar->bind('event_emitter', $eventEmitter);
        $registrar->bind('memory_repository', $memoryRepository);
        $registrar->bind('memory_store', $memoryStore);
        $registrar->bind('memory_extractor', $memoryExtractor);
        $registrar->bind('memory_policy', $memoryPolicy);
        $registrar->bind('memory_listener', $memoryExtractionListener);
        $registrar->bind('memory_retrieval_service', $memoryRetrievalService);
        $registrar->bind('ai_usage_service', $aiUsageService);

        // Set up plugin manager to discover dispatcher plugins (which may delegate to assistant plugins)
        $pluginManager = new PluginManager();
        $loader = new PluginLoader($dispatcherPluginsPath);
        $pluginManager->setLoader($loader);

        // Discover and load all plugins (this will invoke registrar callbacks)
        $pluginManager->discoverFromManifests();
        $pluginManager->loadAll($registrar);

        $runtime = new AssistantRuntime(
            $assistantManager,
            $assistantRegistry,
            $toolRegistry,
            $conversationManager,
            $provider,
            $eventEmitter,
            $pluginManager,
            $registrar,
            $assistantLifecycle,
            $memoryRepository,
            $memoryStore,
            $memoryExtractor
        );

        $sessionRestorer = new SessionRestorer($runtime);

        // Initialize context management components
        $tokenEstimator = new TokenEstimator($options['tokens_per_word'] ?? 1.3);
        $summaryRepository = new FileConversationSummaryRepository($options['summaries_path'] ?? null);
        $conversationSummarizer = new ConversationSummarizer($provider, $summaryRepository, $tokenEstimator);
        $contextPolicy = isset($options['context_policy'])
            ? ContextPolicy::fromArray($options['context_policy'])
            : ContextPolicy::balanced();
        $contextWindowManager = new ContextWindowManager($tokenEstimator, $conversationSummarizer, $contextPolicy);

        // Initialize execution report repository (file-backed by default, Postgres optional)
        $executionReportRepository = null;
        if (isset($options['execution_report_repository']) && $options['execution_report_repository'] === 'postgres') {
            if (isset($options['db_pdo'])) {
                $executionReportRepository = new PostgresExecutionReportRepository($options['db_pdo']);
            }
        }
        // Fall back to file-backed repository
        if (!$executionReportRepository) {
            $executionReportRepository = new FileExecutionReportRepository($options['execution_reports_path'] ?? null);
        }

        $registrar->bind('execution_report_repository', $executionReportRepository);

        return [
            'registrar' => $registrar,
            'assistantManager' => $assistantManager,
            'assistantRegistry' => $assistantRegistry,
            'assistantLifecycle' => $assistantLifecycle,
            'toolRegistry' => $toolRegistry,
            'modelProvider' => $provider,
            'pluginManager' => $pluginManager,
            'runtime' => $runtime,
            'sessionRestorer' => $sessionRestorer,
            'tokenEstimator' => $tokenEstimator,
            'usageEstimator' => $usageEstimator,
            'costCalculator' => $costCalculator,
            'providerMetadataRegistry' => $providerMetadataRegistry,
            'aiUsageService' => $aiUsageService,
            'contextWindowManager' => $contextWindowManager,
            'conversationSummarizer' => $conversationSummarizer,
            'summaryRepository' => $summaryRepository,
            'contextPolicy' => $contextPolicy,
            'actionRegistry' => $actionRegistry,
            'memoryRepository' => $memoryRepository,
            'memoryStore' => $memoryStore,
            'memoryExtractor' => $memoryExtractor,
            'memoryPolicy' => $memoryPolicy,
            'memoryListener' => $memoryExtractionListener,
            'memoryRetrievalService' => $memoryRetrievalService,
            'executionReportRepository' => $executionReportRepository,
        ];
    }
}
