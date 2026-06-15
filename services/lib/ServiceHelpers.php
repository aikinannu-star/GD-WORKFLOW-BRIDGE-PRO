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
}
