<?php
/**
 * CMS Service
 * Site builder and content management
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../lib/PermissionService.php';
require_once __DIR__ . '/../lib/AccessGraph.php';
require_once __DIR__ . '/../lib/AccessGraphMiddleware.php';

define('SERVICE_NAME', 'cms');
define('SERVICE_PORT', 8004);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function loadSites(): array
{
    return ServiceHelpers::loadJson('cms', 'sites.json');
}

function saveSites(array $sites): bool
{
    return ServiceHelpers::saveJson('cms', 'sites.json', $sites);
}

function loadPages(): array
{
    return ServiceHelpers::loadJson('cms', 'pages.json');
}

function savePages(array $pages): bool
{
    return ServiceHelpers::saveJson('cms', 'pages.json', $pages);
}

function loadProjects(): array
{
    return ServiceHelpers::loadJson('cms', 'projects.json');
}

function saveProjects(array $projects): bool
{
    return ServiceHelpers::saveJson('cms', 'projects.json', $projects);
}

function loadOrders(): array
{
    return ServiceHelpers::loadJson('cms', 'orders.json');
}

function saveOrders(array $orders): bool
{
    return ServiceHelpers::saveJson('cms', 'orders.json', $orders);
}

function findOrder(string $orderId): ?array
{
    foreach (loadOrders() as $order) {
        if ((string)($order['id'] ?? '') === (string)$orderId) {
            return $order;
        }
    }
    return null;
}

function findOrderByProjectId(string $projectId): ?array
{
    foreach (loadOrders() as $order) {
        if (($order['project_id'] ?? '') === $projectId) {
            return $order;
        }
    }
    return null;
}

function linkOrderToProject(string $orderId, string $projectId, ?string $customerId = null, array $meta = []): bool
{
    $orders = loadOrders();
    $found = false;
    foreach ($orders as &$order) {
        if ((string)($order['id'] ?? '') === (string)$orderId) {
            $order['project_id'] = $projectId;
            if ($customerId) {
                $order['customer_id'] = $customerId;
            }
            $order['meta'] = array_merge($order['meta'] ?? [], $meta);
            $found = true;
        }
    }
    if (!$found) {
        $orders[] = [
            'id' => (string)$orderId,
            'project_id' => $projectId,
            'customer_id' => $customerId,
            'meta' => $meta,
            'created_at' => gmdate('c'),
        ];
    }
    $saved = saveOrders($orders);
    if ($saved) {
        // Update project record with customer_id when provided
        if ($customerId) {
            $project = findProject($projectId);
            if ($project) {
                $project['customer_id'] = $customerId;
                saveProject($project);
            }
        }

        // Graph mutations: ensure nodes and edges reflect the order<->project<->customer linkage
        // Project -> ORDER_PROJECT -> Order and Order -> ORDER_CUSTOMER -> User
        $projectNodeId = AccessGraph::nodeId('project', $projectId);
        $orderNodeId = AccessGraph::nodeId('order', $orderId);
        AccessGraph::addNode(['id' => $orderNodeId, 'type' => 'order', 'data' => ['status' => 'linked']]);

        // Ensure project node exists and has basic metadata
        $project = findProject($projectId);
        if ($project) {
            AccessGraph::addNode(['id' => $projectNodeId, 'type' => 'project', 'data' => ['tenant_id' => $project['tenant_id'] ?? null, 'order_id' => $project['order_id'] ?? null, 'customer_id' => $project['customer_id'] ?? null]]);
        }

        // Add project -> order edge (ORDER_PROJECT)
        AccessGraph::addEdge(['id' => 'edge:' . ServiceHelpers::generateUuid(), 'type' => 'ORDER_PROJECT', 'from' => $projectNodeId, 'to' => $orderNodeId]);

        if ($customerId) {
            $userNodeId = AccessGraph::nodeId('user', $customerId);
            AccessGraph::addNode(['id' => $userNodeId, 'type' => 'user', 'data' => []]);
            // Add order -> customer edge (ORDER_CUSTOMER)
            AccessGraph::addEdge(['id' => 'edge:' . ServiceHelpers::generateUuid(), 'type' => 'ORDER_CUSTOMER', 'from' => $orderNodeId, 'to' => $userNodeId]);
            // Invalidate gateway auth cache for this customer/project (order linkage affects role derivation)
            ServiceHelpers::invalidateGatewayAuthCache($customerId, $projectId, PROJECT_ACTIONS);
        }
    }
    return $saved;
}

function isOrderCustomer(string $orderId, string $userId): bool
{
    $order = findOrder($orderId);
    return $order && (($order['customer_id'] ?? '') === $userId);
}

function getProjectOrderCustomerId(string $projectId): ?string
{
    $order = findOrderByProjectId($projectId);
    return $order['customer_id'] ?? null;
}

function findProject(string $projectId): ?array
{
    foreach (loadProjects() as $project) {
        if ($project['id'] === $projectId) {
            return $project;
        }
    }
    return null;
}

function saveProject(array $project): bool
{
    $projects = loadProjects();
    $updated = false;
    foreach ($projects as &$existing) {
        if ($existing['id'] === $project['id']) {
            $existing = $project;
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $projects[] = $project;
    }
    return saveProjects($projects);
}

function getProjectCustomerId(string $projectId): ?string
{
    $project = findProject($projectId);
    if ($project && !empty($project['customer_id'])) {
        return $project['customer_id'];
    }
    $order = findOrderByProjectId($projectId);
    return $order['customer_id'] ?? null;
}

function addProjectCollaborator(string $projectId, string $userId): bool
{
    $project = findProject($projectId);
    if (!$project) {
        return false;
    }
    $collaborators = array_values(array_unique(array_filter($project['collaborators'] ?? [])));
    if (in_array($userId, $collaborators, true)) {
        return true;
    }
    $collaborators[] = $userId;
    $project['collaborators'] = $collaborators;
    $project['updated_at'] = gmdate('c');
    return saveProject($project);
}

function removeProjectCollaborator(string $projectId, string $userId): bool
{
    $project = findProject($projectId);
    if (!$project || empty($project['collaborators']) || !is_array($project['collaborators'])) {
        return false;
    }
    $project['collaborators'] = array_values(array_filter($project['collaborators'], fn($collaborator) => $collaborator !== $userId));
    $project['updated_at'] = gmdate('c');
    return saveProject($project);
}

function grantProjectAccess(string $projectId, string $targetUserId, ?string $actorUserId = null): bool
{
    $granted = addProjectCollaborator($projectId, $targetUserId);
    if ($granted) {
        addTimelineEntry($projectId, 'project.access.granted', sprintf('Access granted to %s', $targetUserId), $actorUserId);

        // Ensure nodes and add collaborator edge in the AccessGraph (project -> user)
        $projectNodeId = AccessGraph::nodeId('project', $projectId);
        $userNodeId = AccessGraph::nodeId('user', $targetUserId);
        AccessGraph::addNode(['id' => $projectNodeId, 'type' => 'project', 'data' => []]);
        AccessGraph::addNode(['id' => $userNodeId, 'type' => 'user', 'data' => []]);

        // avoid duplicate collaborator edges
        $graph = AccessGraph::loadGraph();
        $exists = false;
        foreach (AccessGraph::getOutgoingEdges($graph, $projectNodeId, 'COLLABORATOR') as $edge) {
            if (($edge['to'] ?? null) === $userNodeId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            AccessGraph::addEdge(['id' => 'edge:' . ServiceHelpers::generateUuid(), 'type' => 'COLLABORATOR', 'from' => $projectNodeId, 'to' => $userNodeId]);
            // Invalidate gateway auth cache for this user/project for all project actions
            ServiceHelpers::invalidateGatewayAuthCache($targetUserId, $projectId, PROJECT_ACTIONS);
        }

        // Notify the target user (if actor is different)
        if ($actorUserId && $actorUserId !== $targetUserId) {
            triggerNotification($targetUserId, 'project_access_granted', $projectId, ['granted_by' => $actorUserId]);
        }
    }
    return $granted;
}

function revokeProjectAccess(string $projectId, string $targetUserId, ?string $actorUserId = null): bool
{
    $revoked = removeProjectCollaborator($projectId, $targetUserId);
    if ($revoked) {
        addTimelineEntry($projectId, 'project.access.revoked', sprintf('Access revoked for %s', $targetUserId), $actorUserId);

        // Remove collaborator edge from AccessGraph
        $projectNodeId = AccessGraph::nodeId('project', $projectId);
        $userNodeId = AccessGraph::nodeId('user', $targetUserId);
        AccessGraph::removeEdgeByFromToType($projectNodeId, $userNodeId, 'COLLABORATOR');
        // Invalidate gateway auth cache for this user/project for all project actions
        ServiceHelpers::invalidateGatewayAuthCache($targetUserId, $projectId, PROJECT_ACTIONS);

        // Notify the target user (if actor is different)
        if ($actorUserId && $actorUserId !== $targetUserId) {
            triggerNotification($targetUserId, 'project_access_revoked', $projectId, ['revoked_by' => $actorUserId]);
        }
    }
    return $revoked;
}

const PROJECT_ACTIONS = ['read', 'write', 'delete', 'manage', 'upload', 'comment', 'view'];
const MAX_PROJECT_UPLOAD_SIZE = 10485760; // 10 MB
const MAX_VAULT_UPLOAD_SIZE = 52428800; // 50 MB
const ALLOWED_PROJECT_FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/zip'];
const ALLOWED_VAULT_FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/zip', 'application/x-rar-compressed', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
const EVENT_TAXONOMY = [
    'project.created',
    'project.updated',
    'project.status_changed',
    'project.archived',
    'project.deleted',
    'file.uploaded',
    'file.deleted',
    'file.version.created',
    'chat.message.sent',
    'chat.message.deleted',
    'revision.requested',
    'revision.approved',
    'revision.rejected',
    'requirements.submitted',
    'project.access.granted',
    'project.access.revoked',
    'order.completed',
    'notification.created',
    'notification.read',
    'notification.dismissed',
];
const NOTIFICATION_TYPES = ['project_created', 'project_updated', 'file_uploaded', 'message_received', 'revision_requested', 'requirements_submitted', 'project_access_granted', 'project_access_revoked'];

function formatProject(array $project): array
{
    return [
        'id' => $project['id'],
        'title' => $project['title'],
        'status' => $project['status'],
        'order_id' => $project['order_id'] ?? null,
        'customer_id' => $project['customer_id'] ?? null,
        'tenant_id' => $project['tenant_id'] ?? null,
        'created_by' => $project['created_by'] ?? null,
        'file_count' => $project['file_count'] ?? 0,
        'created_at' => $project['created_at'] ?? null,
        'updated_at' => $project['updated_at'] ?? null,
    ];
}

function getRequestUserId(): ?string
{
    $userId = ServiceHelpers::getHeader('X-User-Id');
    if ($userId) {
        return trim($userId);
    }
    return !empty($_GET['user_id']) ? trim($_GET['user_id']) : null;
}

function getRequestRoles(): array
{
    $roles = ServiceHelpers::getHeader('X-User-Roles');
    if (!$roles) {
        return [];
    }
    return array_filter(array_map('trim', explode(',', strtolower($roles))));
}

function isAdminUser(): bool
{
    return in_array('admin', getRequestRoles(), true);
}

function getRequestTenantId(): ?string
{
    return ServiceHelpers::normalizeTenantId([]);
}

function getProjectRoles(?string $userId, ?array $project): array
{
    return PermissionService::getProjectRoles(
        $userId,
        $project,
        getRequestTenantId(),
        isAdminUser(),
        fn(string $orderId, string $customerId) => isOrderCustomer($orderId, $customerId)
    );
}

function getProjectPermissions(?string $userId, ?array $project): array
{
    if (!$userId || !$project) {
        return array_fill_keys(PermissionService::getAllPermissions(), false);
    }

    return PermissionService::getProjectPermissions(getProjectRoles($userId, $project), isAdminUser());
}

function canPerformProjectAction(?string $userId, string $projectId, string $action = 'project.read'): bool
{
    $project = findProject($projectId);
    if (!$project) {
        return false;
    }

    $permission = PermissionService::normalizeAction($action);
    $tenantId = getRequestTenantId();
    return AccessGraph::canUserPerform($userId ?? '', $projectId, $permission, $tenantId, isAdminUser());
}

function canAccessVault(string $projectId, ?string $userId): bool
{
    $project = findProject($projectId);
    if (!$project || !$userId) {
        return false;
    }
    $permissions = getProjectPermissions($userId, $project);
    return !empty($permissions['vault.access']);
}

function getVaultFileById(string $fileId): ?array
{
    foreach (loadVault() as $file) {
        if ($file['id'] === $fileId) {
            return $file;
        }
    }
    return null;
}

function canDeleteVaultFile(string $fileId, ?string $userId): bool
{
    $file = getVaultFileById($fileId);
    if (!$file || !$userId) {
        return false;
    }
    if ($file['uploaded_by'] === $userId) {
        return true;
    }
    return canAccessVault($file['project_id'], $userId);
}

function validateFileUpload(array $fileData, bool $vault = false): array
{
    $fileSize = intval($fileData['file_size'] ?? 0);
    $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';
    $allowedTypes = $vault ? ALLOWED_VAULT_FILE_TYPES : ALLOWED_PROJECT_FILE_TYPES;
    $maxSize = $vault ? MAX_VAULT_UPLOAD_SIZE : MAX_PROJECT_UPLOAD_SIZE;

    if ($fileSize <= 0) {
        return ['valid' => false, 'error' => 'invalid_size', 'message' => 'File size must be greater than zero'];
    }
    if ($fileSize > $maxSize) {
        return ['valid' => false, 'error' => 'file_too_large', 'message' => sprintf('File exceeds %dMB limit', $maxSize / 1048576)];
    }
    if (!in_array($mimeType, $allowedTypes, true)) {
        return ['valid' => false, 'error' => 'invalid_mime_type', 'message' => 'File type not allowed'];
    }

    return ['valid' => true];
}

function isValidEventType(string $eventType): bool
{
    return in_array($eventType, EVENT_TAXONOMY, true);
}

function isValidNotificationType(string $type): bool
{
    return in_array($type, NOTIFICATION_TYPES, true);
}

function triggerEvent(string $projectId, string $eventType, string $message, ?string $userId = null, array $metadata = []): array
{
    if (!isValidEventType($eventType)) {
        $eventType = 'project.updated';
    }
    $entry = addTimelineEntry($projectId, $eventType, $message, $userId);

    if (in_array($eventType, ['file.uploaded', 'chat.message.sent', 'revision.requested', 'requirements.submitted', 'project.updated', 'project.created'], true)) {
        $project = findProject($projectId);
        if ($project) {
            $recipient = $userId ?? ($project['created_by'] ?? null);
            if ($recipient) {
                triggerNotification($recipient, str_replace('.', '_', $eventType), $projectId, array_merge($metadata, ['message' => $message]));
            }
        }
    }

    return $entry;
}

function triggerNotification(string $userId, string $type, string $projectId, array $payload = []): ?array
{
    if (!isValidNotificationType($type)) {
        return null;
    }
    return createNotification($userId, $type, $projectId, $payload);
}

function generateProjectIdFromOrder(array $orderData): ?string
{
    if (empty($orderData['id']) || empty($orderData['items']) || !is_array($orderData['items'])) {
        return null;
    }

    $serviceItems = array_filter($orderData['items'], fn($item) => !empty($item['is_service']) || !empty($item['service_category']));
    if (empty($serviceItems)) {
        return null;
    }

    $projectId = ServiceHelpers::generateUuid();
    $projects = loadProjects();
    $newProject = [
        'id' => $projectId,
        'tenant_id' => $orderData['tenant_id'] ?? 'default',
        'title' => $orderData['title'] ?? ('Service Project - Order #' . $orderData['id']),
        'status' => 'draft',
        'order_id' => strval($orderData['id']),
        'customer_id' => $orderData['customer_id'] ?? null,
        'created_by' => $orderData['created_by'] ?? null,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'file_count' => 0,
    ];
    $projects[] = $newProject;
    saveProjects($projects);

    // Graph mutations: create project node, created_by edge, and tenant relation
    $projectNodeId = AccessGraph::nodeId('project', $projectId);
    $userNodeId = AccessGraph::nodeId('user', $userId);
    AccessGraph::addNode(['id' => $projectNodeId, 'type' => 'project', 'data' => ['tenant_id' => $tenantId, 'order_id' => $orderId, 'customer_id' => $newProject['customer_id'] ?? null]]);
    AccessGraph::addNode(['id' => $userNodeId, 'type' => 'user', 'data' => []]);
    AccessGraph::addEdge(['id' => 'edge:' . ServiceHelpers::generateUuid(), 'type' => 'CREATED_BY', 'from' => $projectNodeId, 'to' => $userNodeId]);
    if ($tenantId) {
        $tenantNodeId = AccessGraph::nodeId('tenant', $tenantId);
        AccessGraph::addNode(['id' => $tenantNodeId, 'type' => 'tenant', 'data' => []]);
        AccessGraph::addEdge(['id' => 'edge:' . ServiceHelpers::generateUuid(), 'type' => 'BELONGS_TO', 'from' => $projectNodeId, 'to' => $tenantNodeId]);
    }
    linkOrderToProject($orderData['id'], $projectId, $orderData['customer_id'] ?? null, [
        'service_items' => array_values(array_map(fn($item) => $item['id'] ?? null, $serviceItems)),
    ]);
    addTimelineEntry($projectId, 'project.created', 'Project created from order', $newProject['created_by']);
    return $projectId;
}

function loadChat(): array
{
    return ServiceHelpers::loadJson('cms', 'chat.json');
}

function saveChat(array $chat): bool
{
    return ServiceHelpers::saveJson('cms', 'chat.json', $chat);
}

function loadFiles(): array
{
    return ServiceHelpers::loadJson('cms', 'files.json');
}

function saveFiles(array $files): bool
{
    return ServiceHelpers::saveJson('cms', 'files.json', $files);
}

function loadTimeline(): array
{
    return ServiceHelpers::loadJson('cms', 'timeline.json');
}

function saveTimeline(array $timeline): bool
{
    return ServiceHelpers::saveJson('cms', 'timeline.json', $timeline);
}

function addTimelineEntry(string $projectId, string $eventType, string $message, ?string $userId = null): array
{
    $timeline = loadTimeline();
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'project_id' => $projectId,
        'event_type' => $eventType,
        'message' => $message,
        'user_id' => $userId,
        'created_at' => gmdate('c'),
    ];
    $timeline[] = $entry;
    saveTimeline($timeline);
    return $entry;
}

function addChatMessage(string $projectId, ?string $userId, string $message, bool $isPrivate): array
{
    $chat = loadChat();
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'project_id' => $projectId,
        'user_id' => $userId,
        'message' => $message,
        'is_private' => $isPrivate,
        'created_at' => gmdate('c'),
    ];
    $chat[] = $entry;
    saveChat($chat);
    addTimelineEntry($projectId, 'chat.message.sent', $message, $userId);

    $orderCustomerId = getProjectOrderCustomerId($projectId);
    if ($orderCustomerId && $orderCustomerId !== $userId) {
        triggerNotification($orderCustomerId, 'message_received', $projectId, ['author' => $userId]);
    }

    return $entry;
}

function addProjectFile(string $projectId, array $fileData, ?string $userId): array
{
    $files = loadFiles();
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'project_id' => $projectId,
        'uploaded_by' => $userId,
        'file_name' => $fileData['file_name'] ?? 'unknown',
        'file_size' => intval($fileData['file_size'] ?? 0),
        'mime_type' => $fileData['mime_type'] ?? 'application/octet-stream',
        'created_at' => gmdate('c'),
    ];
    $files[] = $entry;
    saveFiles($files);
    $project = findProject($projectId);
    if ($project) {
        $project['file_count'] = intval(($project['file_count'] ?? 0) + 1);
        $project['updated_at'] = gmdate('c');
        $projects = loadProjects();
        foreach ($projects as &$p) {
            if ($p['id'] === $projectId) {
                $p = $project;
            }
        }
        saveProjects($projects);
    }
    addTimelineEntry($projectId, 'file.uploaded', 'File uploaded: ' . $entry['file_name'], $userId);
    return $entry;
}

function loadFormSubmissions(string $projectId): array
{
    $timeline = loadTimeline();
    return array_values(array_filter($timeline, fn($entry) => in_array($entry['event_type'], ['revision.requested', 'requirements.submitted'], true) && $entry['project_id'] === $projectId));
}

function loadNotifications(): array
{
    return ServiceHelpers::loadJson('cms', 'notifications.json');
}

function saveNotifications(array $notifications): bool
{
    return ServiceHelpers::saveJson('cms', 'notifications.json', $notifications);
}

function createNotification(string $userId, string $type, string $projectId, array $payload = []): array
{
    $notifications = loadNotifications();
    $notification = [
        'id' => ServiceHelpers::generateUuid(),
        'user_id' => $userId,
        'type' => $type,
        'project_id' => $projectId,
        'payload' => $payload,
        'is_read' => false,
        'created_at' => gmdate('c'),
    ];
    $notifications[] = $notification;
    saveNotifications($notifications);
    return $notification;
}

function markNotificationRead(string $notificationId): bool
{
    $notifications = loadNotifications();
    foreach ($notifications as &$n) {
        if ($n['id'] === $notificationId) {
            $n['is_read'] = true;
        }
    }
    return saveNotifications($notifications);
}

function getUserNotifications(string $userId, ?bool $unreadOnly = null): array
{
    $notifications = loadNotifications();
    $filtered = array_filter($notifications, fn($n) => $n['user_id'] === $userId);
    if ($unreadOnly === true) {
        $filtered = array_filter($filtered, fn($n) => !$n['is_read']);
    } elseif ($unreadOnly === false) {
        $filtered = array_filter($filtered, fn($n) => $n['is_read']);
    }
    return array_values(array_reverse($filtered));
}

function getProjectTimeline(string $projectId, int $limit = 50): array
{
    $timeline = loadTimeline();
    $entries = array_filter($timeline, fn($t) => $t['project_id'] === $projectId);
    return array_values(array_slice(array_reverse($entries), 0, $limit));
}

function loadVault(): array
{
    return ServiceHelpers::loadJson('cms', 'vault.json');
}

function saveVault(array $vault): bool
{
    return ServiceHelpers::saveJson('cms', 'vault.json', $vault);
}

function addVaultFile(string $projectId, array $fileData, ?string $userId): array
{
    $vault = loadVault();
    $MAX_FILE_SIZE = 52428800; // 50MB
    $ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/zip', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    
    $fileSize = intval($fileData['file_size'] ?? 0);
    if ($fileSize > $MAX_FILE_SIZE) {
        return ['error' => 'file_too_large', 'message' => 'File exceeds 50MB limit'];
    }
    
    $mimeType = $fileData['mime_type'] ?? 'application/octet-stream';
    if (!in_array($mimeType, $ALLOWED_MIMES, true)) {
        return ['error' => 'invalid_mime_type', 'message' => 'File type not allowed'];
    }
    
    $entry = [
        'id' => ServiceHelpers::generateUuid(),
        'project_id' => $projectId,
        'file_name' => $fileData['file_name'] ?? 'unknown',
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
        'uploaded_by' => $userId,
        'created_at' => gmdate('c'),
    ];
    $vault[] = $entry;
    saveVault($vault);
    
    $project = findProject($projectId);
    if ($project) {
        $project['file_count'] = intval(($project['file_count'] ?? 0) + 1);
        $project['updated_at'] = gmdate('c');
        $projects = loadProjects();
        foreach ($projects as &$p) {
            if ($p['id'] === $projectId) {
                $p = $project;
            }
        }
        saveProjects($projects);
    }
    
    addTimelineEntry($projectId, 'file.uploaded', 'File uploaded: ' . $entry['file_name'], $userId);

    $orderCustomerId = getProjectOrderCustomerId($projectId);
    if ($orderCustomerId && $orderCustomerId !== $userId) {
        triggerNotification($orderCustomerId, 'file_uploaded', $projectId, ['file_name' => $entry['file_name'], 'file_id' => $entry['id']]);
    }
    
    return $entry;
}

function getVaultFiles(string $projectId): array
{
    $vault = loadVault();
    return array_values(array_filter($vault, fn($f) => $f['project_id'] === $projectId));
}

function deleteVaultFile(string $fileId, ?string $projectId = null): bool
{
    $vault = loadVault();
    $file = null;
    $updated = [];
    foreach ($vault as $f) {
        if ($f['id'] === $fileId) {
            $file = $f;
        } else {
            $updated[] = $f;
        }
    }
    if (!$file) {
        return false;
    }
    saveVault($updated);
    
    $project = findProject($file['project_id']);
    if ($project) {
        $project['file_count'] = max(0, intval($project['file_count'] ?? 1) - 1);
        $project['updated_at'] = gmdate('c');
        $projects = loadProjects();
        foreach ($projects as &$p) {
            if ($p['id'] === $file['project_id']) {
                $p = $project;
            }
        }
        saveProjects($projects);
    }
    
    return true;
}

function canAccessProject(string $projectId, ?string $userId): bool
{
    $project = findProject($projectId);
    if (!$project) {
        return false;
    }
    if (!$userId) {
        return false;
    }
    if (($project['created_by'] ?? '') === $userId) {
        return true;
    }
    return true;
}

function loadStats(array $filter = []): array
{
    $projects = loadProjects();
    $totalProjects = 0;
    $totalOrders = 0;
    $totalFiles = 0;

    foreach ($projects as $project) {
        if (!empty($filter['tenant_id']) && ($project['tenant_id'] ?? '') !== $filter['tenant_id']) {
            continue;
        }
        if (!empty($filter['user_id']) && ($project['created_by'] ?? '') !== $filter['user_id']) {
            continue;
        }
        $totalProjects++;
        if (!empty($project['order_id'])) {
            $totalOrders++;
        }
        if (!empty($project['file_count'])) {
            $totalFiles += intval($project['file_count']);
        }
    }

    return [
        'total_projects' => $totalProjects,
        'total_files' => $totalFiles,
        'total_orders' => $totalOrders,
        'total_revenue' => floatval($filter['revenue'] ?? 0.0),
        'this_month' => $totalProjects,
    ];
}

if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
    ]);
}

if ($method === 'POST' && $uri === '/api/v1/sites') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = $input['tenant_id'] ?? null;
    $name = trim($input['name'] ?? '');
    if (!$tenantId || !$name) {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id and name are required']);
    }

    $sites = loadSites();
    $siteId = ServiceHelpers::generateUuid();
    $newSite = [
        'id' => $siteId,
        'tenant_id' => $tenantId,
        'name' => $name,
        'domain' => $input['domain'] ?? null,
        'status' => 'draft',
        'published_at' => null,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $sites[] = $newSite;
    saveSites($sites);
    ServiceHelpers::sendJson(201, ['site' => $newSite]);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'PUT' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $sites = loadSites();
    foreach ($sites as &$site) {
        if ($site['id'] === $siteId) {
            $site['name'] = $input['name'] ?? $site['name'];
            $site['domain'] = $input['domain'] ?? $site['domain'];
            $site['updated_at'] = gmdate('c');
            saveSites($sites);
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/sites/([a-f0-9]+)$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    $updated = array_filter($sites, fn($site) => $site['id'] !== $siteId);
    if (count($updated) === count($sites)) {
        ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
    }
    saveSites($updated);
    ServiceHelpers::sendJson(200, ['success' => true]);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9]+)/pages$#', $uri, $matches)) {
    $siteId = $matches[1];
    $input = ServiceHelpers::getRequestBody();
    $sites = loadSites();
    $found = false;
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
    }

    $pageId = ServiceHelpers::generateUuid();
    $pages = loadPages();
    $newPage = [
        'id' => $pageId,
        'site_id' => $siteId,
        'title' => trim($input['title'] ?? 'Untitled Page'),
        'slug' => trim($input['slug'] ?? 'page-' . substr($pageId, 0, 8)),
        'content' => $input['content'] ?? '',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $pages[] = $newPage;
    savePages($pages);
    ServiceHelpers::sendJson(201, ['page' => $newPage]);
}

if ($method === 'POST' && preg_match('#^/api/v1/sites/([a-f0-9]+)/publish$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as &$site) {
        if ($site['id'] === $siteId) {
            $site['status'] = 'published';
            $site['published_at'] = gmdate('c');
            $site['updated_at'] = gmdate('c');
            saveSites($sites);
            ServiceHelpers::sendJson(200, ['site' => $site]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?projects$#', $uri)) {
    $query = $_GET;
    $userId = getRequestUserId();
    $tenantId = getRequestTenantId();
    $projects = array_map('formatProject', loadProjects());
    $filtered = [];

    foreach ($projects as $project) {
        if (!empty($query['tenant_id']) && ($project['tenant_id'] ?? '') !== trim($query['tenant_id'])) {
            continue;
        }
        if (!empty($query['user_id']) && ($project['created_by'] ?? '') !== trim($query['user_id'])) {
            continue;
        }
        if ($userId && !isAdminUser()) {
            if (!canPerformProjectAction($userId, $project['id'], 'read')) {
                continue;
            }
        } elseif (!$userId) {
            continue;
        }
        $filtered[] = $project;
    }

    ServiceHelpers::sendJson(200, ['projects' => array_values($filtered)]);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)$#', $uri, $matches)) {
    $projectId = $matches[1];
    $project = findProject($projectId);
    if (!$project) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'read')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    ServiceHelpers::sendJson(200, ['project' => formatProject($project)]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?projects$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $userId = getRequestUserId();
    if (!$userId) {
        ServiceHelpers::sendJson(401, ['error' => 'unauthorized']);
    }

    $tenantId = trim($input['tenant_id'] ?? getRequestTenantId() ?? '');
    $title = trim($input['title'] ?? 'Untitled Project');
    $orderId = !empty($input['order_id']) ? trim($input['order_id']) : null;

    if ($tenantId === '') {
        ServiceHelpers::sendJson(400, ['error' => 'tenant_id is required']);
    }

    $projectId = ServiceHelpers::generateUuid();
    $projects = loadProjects();
    $newProject = [
        'id' => $projectId,
        'tenant_id' => $tenantId,
        'title' => $title,
        'status' => 'draft',
        'order_id' => $orderId,
        'created_by' => $userId,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'file_count' => 0,
    ];
    $projects[] = $newProject;
    saveProjects($projects);

    if ($orderId) {
        linkOrderToProject($orderId, $projectId, $userId, ['created_from_project' => true]);
    }

    addTimelineEntry($projectId, 'project.created', 'Project created.', $userId);
    ServiceHelpers::sendJson(201, ['project' => formatProject($newProject)]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)/access/grant$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'manage')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $targetUserId = trim($input['user_id'] ?? '');
    if ($targetUserId === '') {
        ServiceHelpers::sendJson(400, ['error' => 'user_id is required']);
    }
    if (!grantProjectAccess($projectId, $targetUserId, $userId)) {
        ServiceHelpers::sendJson(400, ['error' => 'access_not_granted']);
    }
    ServiceHelpers::sendJson(200, ['success' => true, 'event' => 'project.access.granted']);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)/access/revoke$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'manage')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $targetUserId = trim($input['user_id'] ?? '');
    if ($targetUserId === '') {
        ServiceHelpers::sendJson(400, ['error' => 'user_id is required']);
    }
    if (!revokeProjectAccess($projectId, $targetUserId, $userId)) {
        ServiceHelpers::sendJson(400, ['error' => 'access_not_revoked']);
    }
    ServiceHelpers::sendJson(200, ['success' => true, 'event' => 'project.access.revoked']);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?chat/([a-f0-9]+)/messages$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'comment')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $messages = array_values(array_filter(loadChat(), fn($msg) => $msg['project_id'] === $projectId));
    ServiceHelpers::sendJson(200, ['messages' => $messages]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?chat/([a-f0-9]+)/send$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'comment')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $message = trim($input['message'] ?? '');
    if ($message === '') {
        ServiceHelpers::sendJson(400, ['error' => 'message cannot be empty']);
    }
    $isPrivate = !empty($input['is_private']);
    $entry = addChatMessage($projectId, $userId, $message, $isPrivate);
    ServiceHelpers::sendJson(201, ['message' => $entry]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)/upload$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'upload')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    if (empty($input['file_name']) || empty($input['file_size'])) {
        ServiceHelpers::sendJson(400, ['error' => 'file_name and file_size are required']);
    }
    $entry = addProjectFile($projectId, $input, $userId);
    ServiceHelpers::sendJson(201, ['file' => $entry]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?forms/([a-f0-9]+)/revision-request$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'submit')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    if ($title === '' || $description === '') {
        ServiceHelpers::sendJson(400, ['error' => 'title and description are required']);
    }
    $payload = [
        'title' => $title,
        'description' => $description,
        'priority' => trim($input['priority'] ?? 'medium'),
    ];
    addTimelineEntry($projectId, 'revision.requested', json_encode($payload), $userId);
    ServiceHelpers::sendJson(201, ['success' => true, 'message' => 'Revision request submitted']);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?forms/([a-f0-9]+)/requirements$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'submit')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $requirements = trim($input['requirements'] ?? '');
    if ($requirements === '') {
        ServiceHelpers::sendJson(400, ['error' => 'requirements are required']);
    }
    $payload = [
        'requirements' => $requirements,
        'deadline' => trim($input['deadline'] ?? ''),
    ];
    addTimelineEntry($projectId, 'requirements.submitted', json_encode($payload), $userId);
    ServiceHelpers::sendJson(201, ['success' => true, 'message' => 'Requirements submitted']);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?forms/([a-f0-9]+)/submissions$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'read')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $submissions = loadFormSubmissions($projectId);
    ServiceHelpers::sendJson(200, ['submissions' => $submissions]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?authorize$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $projectId = trim($input['project_id'] ?? '');
    $action = trim($input['action'] ?? 'read');
    $userId = getRequestUserId();

    if ($projectId === '') {
        ServiceHelpers::sendJson(400, ['error' => 'project_id is required']);
    }

    $project = findProject($projectId);
    if (!$project) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }

    $allowed = canPerformProjectAction($userId, $projectId, $action);
    ServiceHelpers::sendJson(200, ['allowed' => $allowed, 'permissions' => getProjectPermissions($userId, $project)]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?validate-file$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $context = trim($input['context'] ?? 'project');
    $result = validateFileUpload($input, $context === 'vault');
    ServiceHelpers::sendJson($result['valid'] ? 200 : 400, $result);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?events$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $projectId = trim($input['project_id'] ?? '');
    $eventType = trim($input['event_type'] ?? '');
    $message = trim($input['message'] ?? '');
    $metadata = $input['metadata'] ?? [];
    $userId = getRequestUserId();

    if ($projectId === '' || $eventType === '' || $message === '') {
        ServiceHelpers::sendJson(400, ['error' => 'project_id, event_type, and message are required']);
    }

    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }

    $entry = triggerEvent($projectId, $eventType, $message, $userId, $metadata);
    ServiceHelpers::sendJson(201, ['event' => $entry]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?notifications/trigger$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $userId = trim($input['user_id'] ?? getRequestUserId() ?? '');
    $type = trim($input['type'] ?? '');
    $projectId = trim($input['project_id'] ?? '');
    $payload = $input['payload'] ?? [];

    if ($userId === '' || $type === '' || $projectId === '') {
        ServiceHelpers::sendJson(400, ['error' => 'user_id, type, and project_id are required']);
    }

    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }

    $notification = triggerNotification($userId, $type, $projectId, $payload);
    if (!$notification) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_notification_type']);
    }
    ServiceHelpers::sendJson(201, ['notification' => $notification]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?orders/create-project$#', $uri)) {
    $input = ServiceHelpers::getRequestBody();
    $projectId = generateProjectIdFromOrder($input);
    if (!$projectId) {
        ServiceHelpers::sendJson(400, ['error' => 'invalid_order_data_or_not_a_service_order']);
    }
    ServiceHelpers::sendJson(201, ['project_id' => $projectId]);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)/timeline$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'read')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $limit = intval($_GET['limit'] ?? 50);
    $timeline = getProjectTimeline($projectId, $limit);
    ServiceHelpers::sendJson(200, ['timeline' => $timeline]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?projects/([a-f0-9]+)/timeline$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canPerformProjectAction($userId, $projectId, 'comment')) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    $eventType = trim($input['event_type'] ?? '');
    $message = trim($input['message'] ?? '');
    if ($eventType === '' || $message === '') {
        ServiceHelpers::sendJson(400, ['error' => 'event_type and message are required']);
    }
    $entry = addTimelineEntry($projectId, $eventType, $message, $userId);
    ServiceHelpers::sendJson(201, ['entry' => $entry]);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?notifications$#', $uri)) {
    $userId = getRequestUserId();
    if (!$userId) {
        ServiceHelpers::sendJson(401, ['error' => 'unauthorized', 'message' => 'User ID required']);
    }
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true' ? true : null;
    $notifications = getUserNotifications($userId, $unreadOnly);
    ServiceHelpers::sendJson(200, ['notifications' => $notifications]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?notifications/([a-f0-9]+)/mark-read$#', $uri, $matches)) {
    $notificationId = $matches[1];
    if (!markNotificationRead($notificationId)) {
        ServiceHelpers::sendJson(404, ['error' => 'notification_not_found']);
    }
    ServiceHelpers::sendJson(200, ['success' => true]);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?vault/([a-f0-9]+)/files$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canAccessVault($projectId, $userId)) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $files = getVaultFiles($projectId);
    ServiceHelpers::sendJson(200, ['files' => $files]);
}

if ($method === 'POST' && preg_match('#^/api/v1/(?:cms/)?vault/([a-f0-9]+)/upload$#', $uri, $matches)) {
    $projectId = $matches[1];
    if (!findProject($projectId)) {
        ServiceHelpers::sendJson(404, ['error' => 'project_not_found']);
    }
    $userId = getRequestUserId();
    if (!canAccessVault($projectId, $userId)) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    $input = ServiceHelpers::getRequestBody();
    if (empty($input['file_name']) || empty($input['file_size'])) {
        ServiceHelpers::sendJson(400, ['error' => 'file_name and file_size are required']);
    }
    $result = addVaultFile($projectId, $input, $userId);
    if (isset($result['error'])) {
        ServiceHelpers::sendJson(400, $result);
    }
    ServiceHelpers::sendJson(201, ['file' => $result]);
}

if ($method === 'DELETE' && preg_match('#^/api/v1/(?:cms/)?vault/([a-f0-9]+)/delete$#', $uri, $matches)) {
    $fileId = $matches[1];
    $userId = getRequestUserId();
    if (!canDeleteVaultFile($fileId, $userId)) {
        ServiceHelpers::sendJson(403, ['error' => 'forbidden']);
    }
    if (!deleteVaultFile($fileId)) {
        ServiceHelpers::sendJson(404, ['error' => 'file_not_found']);
    }
    ServiceHelpers::sendJson(200, ['success' => true]);
}

if ($method === 'GET' && preg_match('#^/api/v1/(?:cms/)?stats$#', $uri)) {
    $filter = [];
    if (!empty($_GET['tenant_id'])) {
        $filter['tenant_id'] = trim($_GET['tenant_id']);
    }
    if (!empty($_GET['user_id'])) {
        $filter['user_id'] = trim($_GET['user_id']);
    }
    ServiceHelpers::sendJson(200, ['stats' => loadStats($filter)]);
}

if ($method === 'GET' && preg_match('#^/api/v1/sites/([a-f0-9]+)/analytics$#', $uri, $matches)) {
    $siteId = $matches[1];
    $sites = loadSites();
    foreach ($sites as $site) {
        if ($site['id'] === $siteId) {
            ServiceHelpers::sendJson(200, [
                'analytics' => [
                    'page_views' => rand(100, 1200),
                    'unique_visitors' => rand(50, 680),
                    'bounce_rate' => rand(20, 70),
                    'published_at' => $site['published_at'],
                ],
            ]);
        }
    }
    ServiceHelpers::sendJson(404, ['error' => 'site_not_found']);
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
