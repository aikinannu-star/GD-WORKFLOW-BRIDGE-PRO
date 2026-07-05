<?php
require_once __DIR__ . '/ActionInterface.php';
require_once __DIR__ . '/../plugin/PermissionEnforcer.php';

class ActionRegistry
{
    private $actions = [];
    private $actionMetadata = [];
    private $permissionEnforcer;

    public function __construct(?PermissionEnforcer $permissionEnforcer = null)
    {
        $this->permissionEnforcer = $permissionEnforcer ?? new PermissionEnforcer();
    }

    public function setPermissionEnforcer(PermissionEnforcer $permissionEnforcer): void
    {
        $this->permissionEnforcer = $permissionEnforcer;
    }

    public function register(string $name, ActionInterface $action, array $permissions = [], ?string $pluginId = null): void
    {
        $this->actions[$name] = $action;
        $this->actionMetadata[$name] = [
            'pluginId' => $pluginId,
            'permissions' => $permissions,
        ];
    }

    public function hasAction(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    public function execute(string $name, array $payload, ExecutionContext $context): ActionResult
    {
        if (!$this->hasAction($name)) {
            return ActionResult::failure('unknown_action', ['action' => $name]);
        }

        $metadata = $this->actionMetadata[$name] ?? [];
        $pluginId = $metadata['pluginId'] ?? null;
        $permissions = $metadata['permissions'] ?? [];

        if ($pluginId !== null && !empty($permissions)) {
            try {
                $this->permissionEnforcer->assert($pluginId, $permissions);
            } catch (PermissionDeniedException $e) {
                return ActionResult::failure('permission_denied', ['action' => $name, 'plugin' => $pluginId, 'message' => $e->getMessage()]);
            }
        }

        $started = microtime(true);
        $result = $this->actions[$name]->execute($payload, $context);
        $result->setDuration((float) (microtime(true) - $started));
        return $result;
    }
}
