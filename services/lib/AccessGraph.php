<?php

require_once __DIR__ . '/ServiceHelpers.php';
require_once __DIR__ . '/PermissionService.php';

class AccessGraph
{
    public const NODE_TYPES = ['user', 'project', 'order', 'tenant'];

    public const EDGE_TYPES = [
        'CREATED_BY',
        'COLLABORATOR',
        'ORDER_PROJECT',
        'ORDER_CUSTOMER',
        'MEMBER_OF',
        'BELONGS_TO',
    ];

    public const GRAPH_FILE = 'graph.json';
    public const AUTHORIZE_CACHE_FILE = 'auth_cache.json';
    public const AUTHORIZE_CACHE_TTL = 15; // seconds
    public const MAX_TRAVERSAL_HOPS = 8;

    public static function loadGraph(): array
    {
        $graph = ServiceHelpers::loadJson('cms', self::GRAPH_FILE);
        if (!is_array($graph)) {
            $graph = [];
        }
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            $graph['nodes'] = [];
        }
        if (!isset($graph['edges']) || !is_array($graph['edges'])) {
            $graph['edges'] = [];
        }
        return self::buildIndexes($graph);
    }

    public static function saveGraph(array $graph): bool
    {
        unset($graph['outgoing'], $graph['incoming']);
        return ServiceHelpers::saveJson('cms', self::GRAPH_FILE, $graph);
    }

    private static function buildIndexes(array $graph): array
    {
        $graph['outgoing'] = [];
        $graph['incoming'] = [];

        foreach ($graph['edges'] as $edgeId => $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if ($from) {
                $graph['outgoing'][$from][] = $edgeId;
            }
            if ($to) {
                $graph['incoming'][$to][] = $edgeId;
            }
        }
        return $graph;
    }

    public static function getNode(array $graph, string $nodeId): ?array
    {
        return $graph['nodes'][$nodeId] ?? null;
    }

    public static function getOutgoingEdges(array $graph, string $nodeId, ?string $type = null): array
    {
        $edges = [];
        foreach ($graph['outgoing'][$nodeId] ?? [] as $edgeId) {
            $edge = $graph['edges'][$edgeId] ?? null;
            if (!$edge) {
                continue;
            }
            if ($type !== null && $edge['type'] !== $type) {
                continue;
            }
            $edges[] = $edge;
        }
        return $edges;
    }

    public static function getIncomingEdges(array $graph, string $nodeId, ?string $type = null): array
    {
        $edges = [];
        foreach ($graph['incoming'][$nodeId] ?? [] as $edgeId) {
            $edge = $graph['edges'][$edgeId] ?? null;
            if (!$edge) {
                continue;
            }
            if ($type !== null && $edge['type'] !== $type) {
                continue;
            }
            $edges[] = $edge;
        }
        return $edges;
    }

    public static function nodeId(string $type, string $id): string
    {
        return sprintf('%s:%s', $type, trim($id));
    }

    public static function addNode(array $node): bool
    {
        $graph = self::loadGraph();
        if (empty($node['id']) || empty($node['type'])) {
            return false;
        }
        $graph['nodes'][$node['id']] = $node;
        $saved = self::saveGraph($graph);
        if ($saved) {
            // Invalidate cache entries related to this node
            self::invalidateAuthorizeCacheForNodes([$node['id']]);
        }
        return $saved;
    }

    public static function addEdge(array $edge): bool
    {
        $graph = self::loadGraph();
        if (empty($edge['id']) || empty($edge['type']) || empty($edge['from']) || empty($edge['to'])) {
            return false;
        }
        $graph['edges'][$edge['id']] = $edge;
        $saved = self::saveGraph($graph);
        if ($saved) {
            // Invalidate cache entries related to affected nodes
            self::invalidateAuthorizeCacheForNodes([$edge['from'], $edge['to']]);
        }
        return $saved;
    }

    public static function removeEdgeByFromToType(string $from, string $to, string $type): bool
    {
        $graph = self::loadGraph();
        $removed = false;
        foreach ($graph['edges'] as $edgeId => $edge) {
            if (($edge['from'] ?? null) === $from && ($edge['to'] ?? null) === $to && ($edge['type'] ?? null) === $type) {
                unset($graph['edges'][$edgeId]);
                $removed = true;
            }
        }
        if ($removed) {
            $saved = self::saveGraph($graph);
            if ($saved) {
                self::invalidateAuthorizeCacheForNodes([$from, $to]);
            }
            return $saved;
        }
        return false;
    }

    private static function cachePath(): string
    {
        return ServiceHelpers::dataPath('cms', self::AUTHORIZE_CACHE_FILE);
    }

    private static function loadAuthorizeCache(): array
    {
        $cache = ServiceHelpers::loadJson('cms', self::AUTHORIZE_CACHE_FILE);
        if (!is_array($cache)) {
            $cache = [];
        }
        $now = time();
        $changed = false;
        foreach ($cache as $k => $v) {
            if (empty($v['expires_at']) || $v['expires_at'] < $now) {
                unset($cache[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            ServiceHelpers::saveJson('cms', self::AUTHORIZE_CACHE_FILE, $cache);
        }
        return $cache;
    }

    private static function saveAuthorizeCache(array $cache): bool
    {
        return ServiceHelpers::saveJson('cms', self::AUTHORIZE_CACHE_FILE, $cache);
    }

    private static function makeCacheKey(string $userId, string $projectId, string $permission, ?string $tenantId, bool $isAdmin): string
    {
        return sha1(json_encode([$userId, $projectId, $permission, $tenantId, $isAdmin]));
    }

    public static function invalidateAuthorizeCacheForNodes(array $nodeIds): bool
    {
        $cache = self::loadAuthorizeCache();
        $changed = false;
        $toInvalidate = [];
        foreach ($cache as $k => $v) {
            $userNode = $v['user'] ?? null;
            $projectNode = $v['project'] ?? null;
            foreach ($nodeIds as $nid) {
                if ($nid && ($nid === $userNode || $nid === $projectNode)) {
                    // record pair for external invalidation (gateway Redis)
                    if ($userNode && $projectNode) {
                        $toInvalidate[] = ['user' => $userNode, 'project' => $projectNode];
                    }
                    unset($cache[$k]);
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            $saved = self::saveAuthorizeCache($cache);
            // propagate invalidation to gateway Redis (if available)
            foreach ($toInvalidate as $pair) {
                $userNode = $pair['user'];
                $projectNode = $pair['project'];
                $userId = $userNode;
                $projectId = $projectNode;
                if (strpos($userNode, 'user:') === 0) {
                    $userId = substr($userNode, strlen('user:'));
                }
                if (strpos($projectNode, 'project:') === 0) {
                    $projectId = substr($projectNode, strlen('project:'));
                }
                if (!empty($userId) && !empty($projectId)) {
                    ServiceHelpers::invalidateGatewayAuthCache($userId, $projectId);
                }
            }
            return $saved;
        }
        return true;
    }

    public static function resolveProjectRoles(string $userId, string $projectId, ?string $tenantId = null, bool $isAdmin = false): array
    {
        if ($isAdmin) {
            return ['admin'];
        }

        $graph = self::loadGraph();
        $projectNodeId = self::nodeId('project', $projectId);
        $userNodeId = self::nodeId('user', $userId);
        $projectNode = self::getNode($graph, $projectNodeId);
        if (!$projectNode) {
            return [];
        }

        $roles = [];

        if (self::hasDirectUserEdge($graph, $projectNodeId, $userNodeId, 'CREATED_BY')) {
            $roles[] = 'project_owner';
        }

        if (self::hasDirectUserEdge($graph, $projectNodeId, $userNodeId, 'COLLABORATOR')) {
            $roles[] = 'collaborator';
        }

        if (self::hasOrderCustomerRelation($graph, $projectNodeId, $userNodeId)) {
            $roles[] = 'order_customer';
        }

        if (self::isTenantMember($graph, $projectNodeId, $userNodeId, $tenantId)) {
            $roles[] = 'tenant_member';
        }

        return array_values(array_unique($roles));
    }

    public static function canUserPerform(string $userId, string $projectId, string $permission, ?string $tenantId = null, bool $isAdmin = false): bool
    {
        $permission = PermissionService::normalizeAction($permission);

        // Try cache first
        $key = self::makeCacheKey($userId, $projectId, $permission, $tenantId, $isAdmin);
        $cache = self::loadAuthorizeCache();
        if (isset($cache[$key]) && !empty($cache[$key]['expires_at']) && $cache[$key]['expires_at'] >= time()) {
            return !empty($cache[$key]['allowed']);
        }

        $roles = self::resolveProjectRoles($userId, $projectId, $tenantId, $isAdmin);
        $permissions = PermissionService::getProjectPermissions($roles, $isAdmin);
        $allowed = !empty($permissions[$permission]);

        // Store in cache
        $cache[$key] = [
            'allowed' => $allowed,
            'permissions' => $permissions,
            'expires_at' => time() + self::AUTHORIZE_CACHE_TTL,
            'user' => self::nodeId('user', $userId),
            'project' => self::nodeId('project', $projectId),
        ];
        self::saveAuthorizeCache($cache);

        return $allowed;
    }

    private static function hasDirectUserEdge(array $graph, string $projectNodeId, string $userNodeId, string $edgeType): bool
    {
        foreach (self::getOutgoingEdges($graph, $projectNodeId, $edgeType) as $edge) {
            if ($edge['to'] === $userNodeId) {
                return true;
            }
        }
        return false;
    }

    private static function hasOrderCustomerRelation(array $graph, string $projectNodeId, string $userNodeId): bool
    {
        foreach (self::getOutgoingEdges($graph, $projectNodeId, 'ORDER_PROJECT') as $edge) {
            $orderNodeId = $edge['to'];
            foreach (self::getOutgoingEdges($graph, $orderNodeId, 'ORDER_CUSTOMER') as $customerEdges) {
                if ($customerEdges['to'] === $userNodeId) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function isTenantMember(array $graph, string $projectNodeId, string $userNodeId, ?string $tenantId): bool
    {
        $tenantNodeId = null;
        foreach (self::getOutgoingEdges($graph, $projectNodeId, 'BELONGS_TO') as $edge) {
            $tenantNodeId = $edge['to'];
            break;
        }

        if (!$tenantNodeId && $tenantId) {
            $tenantNodeId = self::nodeId('tenant', $tenantId);
        }

        if (!$tenantNodeId) {
            return false;
        }

        foreach (self::getOutgoingEdges($graph, $userNodeId, 'MEMBER_OF') as $edge) {
            if ($edge['to'] === $tenantNodeId) {
                return true;
            }
        }

        return false;
    }
}
