#!/usr/bin/env php
<?php
require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../lib/EventStore.php';
require_once __DIR__ . '/../lib/LicenseActivator.php';

$limit = $argv[1] ?? 50;
$es = new EventStore();
if (!$es->isEnabled()) {
    echo "EventStore not configured, aborting. Use PGHOST/PGDATABASE/PGUSER/PGPASSWORD or EVENTS_DSN.\n";
    exit(1);
}

$es->ensureSchema();
$pending = $es->fetchPending((int)$limit);
if (empty($pending)) {
    echo "No pending events to process.\n";
    exit(0);
}

foreach ($pending as $row) {
    $eventKey = $row['event_key'];
    echo "Processing: {$eventKey}\n";

    $attempts = intval($row['attempts'] ?? 0) + 1;
    $row['attempts'] = $attempts;
    $row['last_attempt_at'] = gmdate('c');
    $row['status'] = 'processing';
    $es->saveEvent($eventKey, $row);

    $licenseKey = $row['license_key'] ?? ($row['metadata']['license_key'] ?? ($row['metadata']['license'] ?? null));
    if (empty($licenseKey)) {
        $row['status'] = 'failed';
        $row['last_error'] = 'no_license_key';
        $row['next_retry_at'] = gmdate('c', time() + min(3600, 60 * pow(2, $attempts)));
        $es->saveEvent($eventKey, $row);
        echo " -> no license_key, marked failed\n";
        continue;
    }

    $site = $row['metadata']['site'] ?? null;
    try {
        $resp = LicenseActivator::activate($licenseKey, $site);
    } catch (Throwable $e) {
        $resp = ['error' => $e->getMessage()];
    }

    if (!empty($resp['success']) || !empty($resp['access_token']) || !empty($resp['token'])) {
        $row['status'] = 'processed';
        $row['processed_at'] = gmdate('c');
        $row['result'] = $resp;
        $row['last_attempt_at'] = gmdate('c');
        $es->saveEvent($eventKey, $row);
        echo " -> processed OK\n";
    } else {
        $row['status'] = 'failed';
        $row['last_error'] = $resp;
        $row['next_retry_at'] = gmdate('c', time() + min(3600, 60 * pow(2, $attempts)));
        $row['last_attempt_at'] = gmdate('c');
        $es->saveEvent($eventKey, $row);
        echo " -> activation failed, scheduled retry at {$row['next_retry_at']}\n";
    }
}

echo "Done. Processed " . count($pending) . " events.\n";
