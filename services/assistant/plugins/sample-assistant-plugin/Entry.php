<?php

require_once __DIR__ . '/../../AssistantManager.php';
require_once __DIR__ . '/../../AssistantInterface.php';

class SampleAssistant implements AssistantInterface
{
    public function id(): string
    {
        return 'sample-assistant';
    }

    public function name(): string
    {
        return 'Sample Assistant';
    }

    public function description(): string
    {
        return 'A minimal assistant that echoes input.';
    }

    public function capabilities(): array
    {
        return ['conversation'];
    }

    public function tools(): array
    {
        return [];
    }

    public function handleConversation(array $context): array
    {
        $input = $context['input'] ?? '';
        return ['success' => true, 'text' => 'Echo: ' . $input];
    }
}

class AssistantEntry
{
    public static function register($registrar)
    {
        $assistant = new SampleAssistant();

        // Prefer the registrar-level helper if available
        if (method_exists($registrar, 'registerAssistant')) {
            $registrar->registerAssistant($assistant);
            return true;
        }

        // Fallback: try bound assistant manager
        $manager = $registrar->get('assistant_manager');
        if ($manager && method_exists($manager, 'registerAssistant')) {
            $manager->registerAssistant($assistant->id(), $assistant);
            return true;
        }

        return false;
    }
}
