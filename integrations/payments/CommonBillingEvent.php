<?php
/**
 * CommonBillingEvent
 * Normalize provider-specific webhook events into a common shape
 */

class CommonBillingEvent
{
    public static function normalize(array $rawEvent, string $provider): array
    {
        // If adapter already returned processed shape, prefer that
        if (isset($rawEvent['event_type'])) {
            $type = $rawEvent['event_type'];
            $obj = $rawEvent['object'] ?? ($rawEvent['data'] ?? []);
        } else {
            $type = $rawEvent['type'] ?? $rawEvent['event'] ?? $rawEvent['event_type'] ?? 'unknown';
            $obj = $rawEvent['data'] ?? $rawEvent['object'] ?? $rawEvent['payload'] ?? $rawEvent;
        }

        $normalized = [
            'type' => $type,
            'provider' => $provider,
            'reference' => $obj['id'] ?? $obj['reference'] ?? $obj['tx_ref'] ?? $obj['payment_id'] ?? null,
            'amount' => $obj['amount'] ?? ($obj['amount_paid'] ?? null),
            'currency' => strtoupper($obj['currency'] ?? ($obj['currency_code'] ?? null)),
            'customer_id' => $obj['customer'] ?? ($obj['customer_id'] ?? ($obj['email'] ?? null)),
            'metadata' => $obj['metadata'] ?? $obj['meta'] ?? [],
            'raw' => $rawEvent,
        ];

        // Attempt to extract an explicit event id from common locations
        $eventId = null;
        if (!empty($rawEvent['id'])) $eventId = $rawEvent['id'];
        if (empty($eventId) && !empty($rawEvent['event_id'])) $eventId = $rawEvent['event_id'];
        if (empty($eventId) && !empty($rawEvent['data']['id'])) $eventId = $rawEvent['data']['id'];
        if (empty($eventId) && !empty($rawEvent['data']['object']['id'])) $eventId = $rawEvent['data']['object']['id'];
        if (empty($eventId) && !empty($obj['reference'])) $eventId = $obj['reference'];
        if (empty($eventId) && !empty($obj['tx_ref'])) $eventId = $obj['tx_ref'];

        $normalized['event_id'] = $eventId;
        // Unique key used for idempotency: provider:event_id or provider:reference
        $normalized['event_key'] = $provider . ':' . ($eventId ?? ($normalized['reference'] ?? uniqid('evt_', true)));

        // Try extract license_key from metadata
        $licenseKey = null;
        if (!empty($normalized['metadata']) && is_array($normalized['metadata'])) {
            if (!empty($normalized['metadata']['license_key'])) $licenseKey = $normalized['metadata']['license_key'];
            if (!empty($normalized['metadata']['license'])) $licenseKey = $normalized['metadata']['license'];
        }
        if (!$licenseKey && !empty($obj['metadata']['license_key'])) $licenseKey = $obj['metadata']['license_key'];
        if (!$licenseKey && !empty($rawEvent['metadata']['license_key'])) $licenseKey = $rawEvent['metadata']['license_key'];

        $normalized['license_key'] = $licenseKey;

        // Normalize event type to common terms
        $t = strtolower($normalized['type']);
        if (strpos($t, 'payment') !== false || strpos($t, 'invoice') !== false || strpos($t, 'charge') !== false || strpos($t, 'checkout') !== false) {
            if (strpos($t, 'failed') !== false || strpos($t, 'failed') !== false) {
                $normalized['event'] = 'payment_failed';
            } else {
                $normalized['event'] = 'payment_succeeded';
            }
        } elseif (strpos($t, 'subscription') !== false) {
            if (strpos($t, 'created') !== false) $normalized['event'] = 'subscription_created';
            elseif (strpos($t, 'cancel') !== false) $normalized['event'] = 'subscription_canceled';
            else $normalized['event'] = 'subscription_updated';
        } else {
            $normalized['event'] = $normalized['type'];
        }

        return $normalized;
    }
}
