<?php
/**
 * Centralized control plane auth helper.
 * Provides a single place to enforce control-plane requests (health/metrics/etc).
 */

require_once __DIR__ . '/ServiceHelpers.php';

class ControlPlaneAuth
{
    public static function isEnabled(): bool
    {
        return !empty($_ENV['GATEWAY_HEALTH_TOKEN']);
    }

    public static function getRequiredToken(): ?string
    {
        return $_ENV['GATEWAY_HEALTH_TOKEN'] ?? null;
    }

    public static function extractProvidedToken(): ?string
    {
        $provided = ServiceHelpers::getHeader('X-Health-Token') ?? '';
        if (empty($provided)) {
            $auth = ServiceHelpers::getHeader('Authorization') ?? '';
            if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
                $provided = $m[1];
            }
        }
        return $provided ?: null;
    }

    public static function enforceOrExit(): void
    {
        $required = self::getRequiredToken();
        if (!$required) {
            return; // not enabled
        }
        $provided = self::extractProvidedToken();
        if (empty($provided) || $provided !== $required) {
            ServiceHelpers::sendJson(401, ['error' => 'unauthorized', 'message' => 'invalid control-plane token']);
        }
    }
}
