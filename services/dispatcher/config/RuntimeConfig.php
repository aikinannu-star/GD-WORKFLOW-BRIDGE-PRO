<?php
class RuntimeConfig
{
    private $config = [];
    private $defaults = [
        'queue_backend' => 'file',
        'lock_backend' => 'memory',
        'metrics_backend' => 'null',
        'retry_max_attempts' => 3,
        'retry_backoff_seconds' => 1.0,
        'retry_exponential' => true,
        'scheduler_interval' => 60,
        'worker_timeout' => 300,
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults, $config);
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function all(): array
    {
        return $this->config;
    }

    public static function fromEnv(): self
    {
        $config = [];
        if ($queueBackend = getenv('RUNTIME_QUEUE_BACKEND')) {
            $config['queue_backend'] = $queueBackend;
        }
        if ($lockBackend = getenv('RUNTIME_LOCK_BACKEND')) {
            $config['lock_backend'] = $lockBackend;
        }
        if ($metricsBackend = getenv('RUNTIME_METRICS_BACKEND')) {
            $config['metrics_backend'] = $metricsBackend;
        }
        return new self($config);
    }
}
