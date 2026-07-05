<?php

require_once __DIR__ . '/../../../assistant/plugins/support-assistant/SupportAssistant.php';

class SupportAssistantEntry implements PluginInterface
{
    public function getName(): string
    {
        return 'support-assistant-wrapper';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function register(RuntimeRegistrar $registrar): void
    {
        $toolRegistry = $registrar->get('tool_registry');
        $modelProvider = $registrar->get('model_provider');
        $conversationManager = $registrar->get('conversation_manager');
        $eventEmitter = $registrar->get('event_emitter');
        $memoryStore = $registrar->get('memory_store');
        $memoryPolicy = $registrar->get('memory_policy');

        if (!$toolRegistry || !$modelProvider) {
            throw new Exception('Required assistant runtime dependencies are not available');
        }

        $assistant = new SupportAssistant($toolRegistry, $modelProvider, $conversationManager, $eventEmitter, $memoryStore, $memoryPolicy);
        $registrar->registerAssistant($assistant);
    }
}
