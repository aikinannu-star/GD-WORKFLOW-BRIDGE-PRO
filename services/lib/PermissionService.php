<?php

class PermissionService
{
    public const PERMISSIONS = [
        'project.read' => true,
        'project.write' => true,
        'project.delete' => true,
        'project.manage' => true,
        'project.upload' => true,
        'project.comment' => true,
        'chat.read' => true,
        'chat.send' => true,
        'forms.read' => true,
        'forms.submit' => true,
        'notification.read' => true,
        'notification.mark_read' => true,
        'vault.access' => true,
        'vault.upload' => true,
        'vault.delete' => true,
    ];

    private const ROLE_PERMISSIONS = [
        'project_owner' => [
            'project.read',
            'project.write',
            'project.delete',
            'project.manage',
            'project.upload',
            'project.comment',
            'chat.read',
            'chat.send',
            'forms.read',
            'forms.submit',
            'notification.read',
            'notification.mark_read',
            'vault.access',
            'vault.upload',
            'vault.delete',
        ],
        'order_customer' => [
            'project.read',
            'project.upload',
            'project.comment',
            'chat.read',
            'chat.send',
            'forms.read',
            'forms.submit',
            'notification.read',
            'notification.mark_read',
            'vault.access',
            'vault.upload',
        ],
        'collaborator' => [
            'project.read',
            'project.comment',
            'forms.read',
            'chat.read',
            'chat.send',
        ],
        'tenant_member' => [
            'project.read',
        ],
        'viewer' => [
            'project.read',
        ],
    ];

    private const ACTION_MAP = [
        'view' => 'project.read',
        'read' => 'project.read',
        'send' => 'chat.send',
        'comment' => 'project.comment',
        'upload' => 'project.upload',
        'delete' => 'project.delete',
        'manage' => 'project.manage',
        'submit' => 'forms.submit',
    ];

    public static function getAllPermissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function getActionKeywords(): array
    {
        return array_keys(self::ACTION_MAP);
    }

    public static function normalizeAction(string $action): string
    {
        if (strpos($action, '.') !== false) {
            return $action;
        }
        return self::ACTION_MAP[$action] ?? 'project.' . $action;
    }

    public static function getProjectRoles(
        ?string $userId,
        ?array $project,
        ?string $tenantId,
        bool $isAdmin,
        ?callable $orderCustomerResolver = null
    ): array {
        $roles = [];
        if (!$userId || !$project) {
            return $roles;
        }

        if ($isAdmin) {
            return ['admin'];
        }

        if (($project['created_by'] ?? '') === $userId) {
            $roles[] = 'project_owner';
        }

        if (!empty($project['order_id']) && $orderCustomerResolver && $orderCustomerResolver($project['order_id'], $userId)) {
            $roles[] = 'order_customer';
        }

        if (!empty($project['customer_id']) && ($project['customer_id'] === $userId)) {
            $roles[] = 'order_customer';
        }

        if (!empty($project['collaborators']) && is_array($project['collaborators']) && in_array($userId, $project['collaborators'], true)) {
            $roles[] = 'collaborator';
        }

        if ($tenantId && ($project['tenant_id'] ?? '') === $tenantId) {
            $roles[] = 'tenant_member';
        }

        return array_values(array_unique($roles));
    }

    public static function getProjectPermissions(array $roles, bool $isAdmin = false): array
    {
        $permissions = array_fill_keys(array_keys(self::PERMISSIONS), false);
        if ($isAdmin) {
            return array_fill_keys(array_keys(self::PERMISSIONS), true);
        }

        foreach ($roles as $role) {
            foreach (self::ROLE_PERMISSIONS[$role] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }
        return $permissions;
    }
}
