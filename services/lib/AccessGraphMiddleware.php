<?php

require_once __DIR__ . '/ServiceHelpers.php';
require_once __DIR__ . '/AccessGraph.php';
require_once __DIR__ . '/PermissionService.php';

class AccessGraphMiddleware
{
    public static function authorizeFromHeaders(string $projectId, string $action): bool
    {
        $userId = ServiceHelpers::getHeader('X-User-Id') 
            ?? (isset($_GET['user_id']) ? trim($_GET['user_id']) : null);
        $tenantId = ServiceHelpers::normalizeTenantId([]);
        $isAdmin = in_array('admin', array_map('trim', explode(',', strtolower(ServiceHelpers::getHeader('X-User-Roles') ?? ''))), true);
        if (!$userId) {
            return false;
        }
        $permission = PermissionService::normalizeAction($action);
        return AccessGraph::canUserPerform($userId, $projectId, $permission, $tenantId, $isAdmin);
    }

    public static function enforceFromHeaders(string $projectId, string $action): void
    {
        if (!self::authorizeFromHeaders($projectId, $action)) {
            ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
        }
    }
}
