<?php

class PermissionDeniedException extends RuntimeException
{
}

class PermissionEnforcer
{
    private array $grantedPermissions = [];

    public function __construct(array $grantedPermissions = [])
    {
        $this->grantedPermissions = $grantedPermissions;
    }

    public function grant(string $pluginId, array $permissions): void
    {
        $existing = $this->grantedPermissions[$pluginId] ?? [];
        $this->grantedPermissions[$pluginId] = array_values(array_unique(array_merge($existing, $permissions)));
    }

    public function revoke(string $pluginId): void
    {
        unset($this->grantedPermissions[$pluginId]);
    }

    public function hasPermission(string $pluginId, string $permission): bool
    {
        $permissions = $this->grantedPermissions[$pluginId] ?? [];
        return in_array($permission, $permissions, true);
    }

    public function assert(string $pluginId, array $requiredPermissions): void
    {
        if (empty($requiredPermissions)) {
            return;
        }

        $granted = $this->grantedPermissions[$pluginId] ?? [];
        $missing = [];
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $granted, true)) {
                $missing[] = $permission;
            }
        }

        if (!empty($missing)) {
            throw new PermissionDeniedException(
                'permission_denied: Plugin ' . $pluginId . ' is missing permissions: ' . implode(', ', $missing)
            );
        }
    }

    public function getGrantedPermissions(string $pluginId): array
    {
        return $this->grantedPermissions[$pluginId] ?? [];
    }
}
