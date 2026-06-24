<?php

class ServiceHelpers
{
    public static function dataPath(string $service, string $fileName): string
    {
        $base = __DIR__ . '/../../services/data';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        return $base . '/' . $service . '_' . $fileName;
    }

    public static function loadJson(string $service, string $fileName): array
    {
        $path = self::dataPath($service, $fileName);
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    }

    public static function saveJson(string $service, string $fileName, array $data): bool
    {
        $path = self::dataPath($service, $fileName);
        return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public static function sendJson(int $status, array $payload): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }

    public static function getRequestBody(): array
    {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?? [];
    }

    public static function generateUuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function getHeader(string $header)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        return $_SERVER[$key] ?? null;
    }

    public static function normalizeTenantId(array $data): ?string
    {
        return $data['tenant_id'] ?? $_GET['tenant_id'] ?? self::getHeader('X-Tenant-Id') ?? null;
    }

    public static function redisConnect(): ?Redis
    {
        if (!class_exists('Redis')) {
            return null;
        }
        $host = $_ENV['GATEWAY_REDIS_HOST'] ?? ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int)($_ENV['GATEWAY_REDIS_PORT'] ?? ($_ENV['REDIS_PORT'] ?? 6379));
        try {
            $r = new Redis();
            if (@$r->connect($host, $port, 1.0)) {
                return $r;
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    public static function invalidateGatewayAuthCache(string $userId, string $projectId, array $actions = []): void
    {
        if (empty($userId) || empty($projectId)) {
            return;
        }
        if (empty($actions)) {
            // Derive default action keywords from PermissionService if available
            if (!class_exists('PermissionService')) {
                @include_once __DIR__ . '/PermissionService.php';
            }
            if (class_exists('PermissionService')) {
                try {
                    $actions = PermissionService::getActionKeywords();
                } catch (Throwable $e) {
                    $actions = [];
                }
            }
        }
        if (empty($actions)) {
            return;
        }
        $redis = self::redisConnect();
        if (!$redis) {
            return;
        }
        foreach ($actions as $action) {
            $key = 'gateway:cms:auth:' . sha1('u:' . $userId . ':p:' . $projectId . ':a:' . $action);
            try {
                @$redis->del($key);
            } catch (Throwable $e) {
                // ignore
            }
            // publish invalidation message for observability/subscribers
            try {
                @$redis->publish('gateway:cms:auth:invalidate', json_encode(['user_id' => $userId, 'project_id' => $projectId, 'action' => $action]));
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}
