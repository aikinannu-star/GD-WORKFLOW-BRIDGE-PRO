<?php

require_once __DIR__ . '/AssistantRegistry.php';
require_once __DIR__ . '/AssistantInterface.php';

class AssistantManager
{
    private array $assistants = [];
    private ?AssistantRegistry $registry;
    private $providerRegistry;

    public function __construct($providerRegistry = null, ?AssistantRegistry $registry = null)
    {
        $this->providerRegistry = $providerRegistry;
        $this->registry = $registry;
    }

    public function registerAssistant(string $id, AssistantInterface $assistant): void
    {
        $this->assistants[$id] = $assistant;
        if ($this->registry) {
            $this->registry->registerDefinition($id, [
                'id' => $assistant->id(),
                'name' => $assistant->name(),
                'description' => $assistant->description(),
                'capabilities' => $assistant->capabilities(),
                'tools' => $assistant->tools(),
            ]);
        }
    }

    public function getAssistant(string $id)
    {
        return $this->assistants[$id] ?? null;
    }

    public function listAssistants(): array
    {
        return array_keys($this->assistants);
    }

    public function handle(string $assistantId, array $request): array
    {
        $assistant = $this->getAssistant($assistantId);
        if (!$assistant) {
            return ['success' => false, 'error' => 'assistant_not_found'];
        }

        if (method_exists($assistant, 'handleConversation')) {
            return $assistant->handleConversation($request);
        }

        if (method_exists($assistant, 'handleRequest')) {
            return $assistant->handleRequest($request);
        }

        return ['success' => false, 'error' => 'invalid_assistant'];
    }
}
