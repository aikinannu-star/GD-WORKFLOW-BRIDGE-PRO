<?php
/**
 * Metrics
 * Simple metrics helper with Redis fallback to file-based storage.
 */

class Metrics
{
    private $useRedis = false;
    private $redis = null;
    private $redisHost = null;
    private $redisPort = 6379;
    private $pushgatewayUrl = null;
    private $pushEnabled = false;
    private $pushJob = 'billing';
    private $pushInstance = null;
    private $pushInterval = 15; // seconds

    public function __construct()
    {
        $this->redisHost = $_ENV['REDIS_HOST'] ?? ($_ENV['GATEWAY_REDIS_HOST'] ?? null);
        $this->redisPort = intval($_ENV['REDIS_PORT'] ?? ($_ENV['GATEWAY_REDIS_PORT'] ?? 6379));
        $this->pushgatewayUrl = $_ENV['PUSHGATEWAY_URL'] ?? null;
        $this->pushEnabled = (strtolower(($_ENV['PUSHGATEWAY_ENABLED'] ?? 'false')) === 'true') && !empty($this->pushgatewayUrl);
        $this->pushJob = $_ENV['PUSHGATEWAY_JOB'] ?? 'billing';
        $this->pushInstance = $_ENV['PUSHGATEWAY_INSTANCE'] ?? gethostname() ?: php_uname('n');
        $this->pushInterval = (int)($_ENV['PUSHGATEWAY_PUSH_INTERVAL'] ?? 15);
        if ($this->redisHost && class_exists('Redis')) {
            try {
                $r = new Redis();
                if (@$r->connect($this->redisHost, $this->redisPort, 1.0)) {
                    $this->redis = $r;
                    $this->useRedis = true;
                }
            } catch (Throwable $e) {
                $this->useRedis = false;
            }
        }
    }

    public function incr(string $name, int $by = 1): int
    {
        if ($this->useRedis && $this->redis) {
            try {
                $val = $this->redis->incrBy($name, $by);
                $this->maybePush();
                return $val;
            } catch (Throwable $e) {
                // fall back
            }
        }
        $metrics = $this->loadFile();
        $metrics[$name] = ($metrics[$name] ?? 0) + $by;
        $this->saveFile($metrics);
        $this->maybePush();
        return $metrics[$name];
    }

    public function getAll(): array
    {
        if ($this->useRedis && $this->redis) {
            try {
                $keys = $this->redis->keys('*');
                $out = [];
                foreach ($keys as $k) {
                    $out[$k] = (int)$this->redis->get($k);
                }
                return $out;
            } catch (Throwable $e) {
                // fall back
            }
        }
        return $this->loadFile();
    }

    public function renderPrometheus(): string
    {
        $out = [];
        $metrics = $this->getAll();
        foreach ($metrics as $k => $v) {
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($k));
            $out[] = "# HELP {$name} Auto-generated metric from billing service";
            $out[] = "# TYPE {$name} counter";
            $out[] = "{$name} {$v}";
        }
        return implode("\n", $out) . "\n";
    }

    public function pushGateway(string $job = null, string $instance = null): bool
    {
        if (empty($this->pushgatewayUrl)) return false;
        $job = $job ?? $this->pushJob;
        $instance = $instance ?? $this->pushInstance;
        $url = rtrim($this->pushgatewayUrl, '/') . '/metrics/job/' . rawurlencode($job) . '/instance/' . rawurlencode($instance);
        $payload = $this->renderPrometheus();

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $resp = curl_exec($ch);
            if ($resp === false) {
                curl_close($ch);
                return false;
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code >= 200 && $code < 300);
        }

        $opts = ['http' => ['method' => 'PUT', 'header' => "Content-Type: text/plain\r\n", 'content' => $payload, 'timeout' => 2, 'ignore_errors' => true]];
        $ctx = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        return $resp !== false;
    }

    private function maybePush(): void
    {
        if (!$this->pushEnabled) return;
        $now = time();
        $last = $this->getLastPushTime();
        if ($last !== null && ($now - $last) < $this->pushInterval) {
            return;
        }
        try {
            $ok = $this->pushGateway($this->pushJob, $this->pushInstance);
            if ($ok) {
                $this->setLastPushTime($now);
            }
        } catch (Throwable $_) {
            // best-effort; leave last push time unchanged so next call may retry
        }
    }

    private function getLastPushTime(): ?int
    {
        if ($this->useRedis && $this->redis) {
            try {
                $k = 'metrics:last_push:' . $this->pushJob;
                $v = $this->redis->get($k);
                if ($v !== false && $v !== null && is_numeric($v)) return (int)$v;
            } catch (Throwable $_) {}
        }
        $path = ServiceHelpers::dataPath('billing', 'metrics_last_push');
        if (file_exists($path)) {
            $c = @file_get_contents($path);
            if ($c !== false && is_numeric($c)) return (int)$c;
        }
        return null;
    }

    private function setLastPushTime(int $ts): void
    {
        if ($this->useRedis && $this->redis) {
            try {
                $k = 'metrics:last_push:' . $this->pushJob;
                $this->redis->set($k, (int)$ts);
                return;
            } catch (Throwable $_) {}
        }
        $path = ServiceHelpers::dataPath('billing', 'metrics_last_push');
        @file_put_contents($path, (int)$ts);
    }

    private function loadFile(): array
    {
        $path = ServiceHelpers::dataPath('billing', 'metrics.json');
        if (!file_exists($path)) return [];
        $c = file_get_contents($path);
        return json_decode($c, true) ?? [];
    }

    private function saveFile(array $metrics): bool
    {
        $path = ServiceHelpers::dataPath('billing', 'metrics.json');
        return file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }
}
