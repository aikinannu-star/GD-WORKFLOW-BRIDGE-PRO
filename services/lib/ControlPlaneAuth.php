<?php
/**
 * Minimal control-plane auth compatibility shim.
 *
 * The gateway and related services include this dependency for runtime
 * control-plane authentication, but the current local/test environment does
 * not require the full external integration. The implementation below keeps
 * the interface safe and permissive so the service can start and be tested.
 */
class ControlPlaneAuth
{
    public static function isEnabled(): bool
    {
        return false;
    }

    public static function validateRequest(array $headers = [], array $context = []): array
    {
        return [
            'valid' => true,
            'enabled' => false,
            'reason' => 'control_plane_auth_disabled',
        ];
    }

    public static function authorizeService(string $service, array $headers = []): bool
    {
        return true;
    }
}
