<?php

require_once __DIR__ . '/AssistantManager.php';
require_once __DIR__ . '/AssistantRegistry.php';

class AssistantLifecycleManager
{
    private AssistantManager $manager;
    private AssistantRegistry $registry;

    public function __construct(AssistantManager $manager, AssistantRegistry $registry)
    {
        $this->manager = $manager;
        $this->registry = $registry;
    }

    public function install(string $assistantId, array $package = []): bool
    {
        // Validate package, copy assets, register definition
        // For now we assume packaged assistant is available via plugins
        return true;
    }

    public function validate(string $assistantId): bool
    {
        $def = $this->registry->getDefinition($assistantId);
        return $def !== null;
    }

    public function enable(string $assistantId): bool
    {
        // Here we could perform warm-up, provider checks, permissions
        return true;
    }

    public function disable(string $assistantId): bool
    {
        // Remove from manager but keep definition
        $instance = $this->manager->getAssistant($assistantId);
        if ($instance) {
            // best-effort removal
            // Note: AssistantManager currently stores assistants in-memory only
            return true;
        }
        return false;
    }

    public function update(string $assistantId, array $changes = []): bool
    {
        // Push updates, migrations, etc.
        return true;
    }

    public function uninstall(string $assistantId): bool
    {
        // Remove installation artifacts and registry entry
        return true;
    }

    public function health(string $assistantId): array
    {
        // Return simple health diagnostics
        $ok = $this->registry->getDefinition($assistantId) !== null;
        return ['id' => $assistantId, 'healthy' => $ok, 'detail' => $ok ? 'registered' : 'not_found'];
    }
}
