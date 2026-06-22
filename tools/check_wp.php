<?php
$ports = [80, 8080, 8000, 8002, 8081, 8888, 9000, 10080];
$found = [];
foreach ($ports as $port) {
    $base = "http://127.0.0.1:$port";
    $ok = false;
    $responses = [];
    foreach (['/','/wp-admin/','/wp-login.php'] as $path) {
        $url = $base . $path;
        $opts = ['http' => ['method' => 'GET','timeout' => 2,'header' => "User-Agent: WP-Probe/1.0\r\n"]];
        $ctx = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            $responses[] = ['url' => $url, 'len' => strlen($body)];
            if (stripos($body, 'wp-content') !== false || stripos($body, 'wp-login.php') !== false || stripos($body, 'WordPress') !== false) {
                $ok = true;
                break;
            }
        }
    }
    if ($ok) {
        echo "[FOUND] WordPress detected on port $port\n";
        $found[] = $port;
    } else {
        echo "[no] WordPress not detected on port $port\n";
    }
}
if (empty($found)) {
    echo "No WordPress instances detected on common ports.\n";
} else {
    echo "Detected WordPress on ports: " . implode(', ', $found) . "\n";
}
