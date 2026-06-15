#!/usr/bin/env php
<?php
require_once __DIR__ . '/../services/lib/EventStore.php';

$es = new EventStore();
if (!$es->isEnabled()) {
    echo "Postgres/EventStore not configured (PGHOST/PGDATABASE/PGUSER/PGPASSWORD or EVENTS_DSN required)\n";
    exit(1);
}

try {
    $es->ensureSchema();
    echo "billing_events table ensured.\n";
    exit(0);
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(2);
}
