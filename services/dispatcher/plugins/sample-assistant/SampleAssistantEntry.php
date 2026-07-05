<?php

require_once __DIR__ . '/../../plugin/PluginInterface.php';

class SampleAssistantEntry implements PluginInterface
{
    public function getName(): string
    {
        return 'sample-assistant-wrapper';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function register(RuntimeRegistrar $registrar): void
    {
        // Delegate to the assistant plugin implementation
        $assistantEntry = __DIR__ . '/../../../assistant/plugins/sample-assistant-plugin/Entry.php';
        if (file_exists($assistantEntry)) {
            require_once $assistantEntry;
            // The assistant AssistantEntry::register accepts the registrar and will register the assistant
            if (class_exists('AssistantEntry') && method_exists('AssistantEntry', 'register')) {
                AssistantEntry::register($registrar);
                return;
            }
        }

        // If delegation failed, no-op registration
    }
}
