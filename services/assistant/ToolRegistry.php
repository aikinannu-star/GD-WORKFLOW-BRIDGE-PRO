<?php

require_once __DIR__ . '/ToolInterface.php';
require_once __DIR__ . '/../lib/ServiceHelpers.php';

class ToolNotAllowedException extends Exception {}

class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function registerTool(ToolInterface $tool): void
    {
        $this->tools[$tool->id()] = $tool;
    }

    public function has(string $id): bool
    {
        return isset($this->tools[$id]);
    }

    public function invoke(string $id, array $args = []): array
    {
        if (!$this->has($id)) {
            throw new Exception("Tool not found: $id");
        }

        // Enforce tenant-level tool permissions when configured.
        $tenantId = ServiceHelpers::normalizeTenantId($_SERVER) ?: null;
        $permissions = ServiceHelpers::loadJson('assistant', 'tool_permissions.json');
        if (!empty($tenantId) && is_array($permissions) && isset($permissions[$tenantId])) {
            $allowed = $permissions[$tenantId];
            if (!is_array($allowed)) {
                $allowed = [];
            }
            if (!in_array($id, $allowed, true)) {
                throw new Exception("ToolNotAllowed: Tenant {$tenantId} is not allowed to invoke tool {$id}");
            }
        }

        $tool = $this->tools[$id];
        return $tool->execute($args);
    }

    public function get(string $id): ?ToolInterface
    {
        return $this->tools[$id] ?? null;
    }

    public function listTools(): array
    {
        return array_keys($this->tools);
    }
}
