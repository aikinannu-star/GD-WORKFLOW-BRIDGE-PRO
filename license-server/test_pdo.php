<?php
try {
    $pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=licenses','postgres','');
    echo "CONNECT_OK\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
