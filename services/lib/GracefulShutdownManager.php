<?php

class GracefulShutdownManager
{
    private string $serviceName;
    private bool $shutdownRequested = false;
    private bool $draining = false;
    private array $callbacks = [];
    private bool $callbacksExecuted = false;
    private int $activeRequests = 0;
    private int $shutdownTimeoutSeconds;
    private ?float $shutdownRequestedAt = null;
    private bool $forceShutdownTriggered = false;

    public function __construct(string $serviceName = 'service', int $shutdownTimeoutSeconds = 30)
    {
        $this->serviceName = $serviceName;
        $this->shutdownTimeoutSeconds = max(1, $shutdownTimeoutSeconds);
    }

    public function beginRequest(): void
    {
        $this->activeRequests++;
        $this->log('request_started', ['active_requests' => $this->activeRequests]);
        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'setGauge')) {
            ServiceHelpers::setGauge($this->serviceName, $this->serviceName . '_active_requests', [], (float)$this->activeRequests);
        }
    }

    public function endRequest(): void
    {
        if ($this->activeRequests > 0) {
            $this->activeRequests--;
        }
        $this->log('request_completed', ['active_requests' => $this->activeRequests]);
        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'setGauge')) {
            ServiceHelpers::setGauge($this->serviceName, $this->serviceName . '_active_requests', [], (float)$this->activeRequests);
        }

        if ($this->shutdownRequested && ($this->activeRequests === 0 || $this->hasShutdownTimeoutExpired())) {
            $this->runCallbacks();
        }
    }

    public function getActiveRequestCount(): int
    {
        return $this->activeRequests;
    }

    public function getShutdownTimeoutSeconds(): int
    {
        return $this->shutdownTimeoutSeconds;
    }

    public function getShutdownRequestedAt(): ?float
    {
        return $this->shutdownRequestedAt;
    }

    public function hasShutdownTimeoutExpired(): bool
    {
        if ($this->shutdownRequestedAt === null) {
            return false;
        }
        return (microtime(true) - $this->shutdownRequestedAt) >= $this->shutdownTimeoutSeconds;
    }

    public function canAcceptRequests(): bool
    {
        return !$this->draining && !$this->shutdownRequested;
    }

    public function beginDrain(): void
    {
        $this->draining = true;
        $this->log('drain_started');
    }

    public function requestShutdown(string $reason = 'signal'): void
    {
        if ($this->shutdownRequested) {
            return;
        }

        $this->shutdownRequested = true;
        $this->draining = true;
        $this->shutdownRequestedAt = microtime(true);
        $this->log('shutdown_requested', [
            'reason' => $reason,
            'shutdown_timeout_seconds' => $this->shutdownTimeoutSeconds,
        ]);

        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'incrementMetric')) {
            ServiceHelpers::incrementMetric($this->serviceName, $this->serviceName . '_shutdown_in_progress_total');
        }
        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'setGauge')) {
            ServiceHelpers::setGauge($this->serviceName, $this->serviceName . '_shutdown_in_progress', [], 1.0);
        }

        $this->scheduleShutdownTimeoutAlarm();
        if ($this->activeRequests === 0) {
            $this->runCallbacks();
        }
    }

    public function handleShutdownTimeout(): void
    {
        if (!$this->shutdownRequested || $this->callbacksExecuted) {
            return;
        }

        if ($this->activeRequests > 0) {
            $this->forceShutdownTriggered = true;
            $this->log('shutdown_timeout_exceeded', ['active_requests' => $this->activeRequests]);
            if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'incrementMetric')) {
                ServiceHelpers::incrementMetric($this->serviceName, $this->serviceName . '_shutdown_forced_total');
            }
            if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'setGauge')) {
                ServiceHelpers::setGauge($this->serviceName, $this->serviceName . '_shutdown_in_progress', [], 0.0);
            }
            $this->runCallbacks();
        }
    }

    private function scheduleShutdownTimeoutAlarm(): void
    {
        if (function_exists('pcntl_alarm') && defined('SIGALRM')) {
            pcntl_alarm($this->shutdownTimeoutSeconds);
        }
    }

    public function isShuttingDown(): bool
    {
        return $this->shutdownRequested;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function onShutdown(callable $callback): void
    {
        if ($this->shutdownRequested && $this->activeRequests === 0) {
            $callback($this);
            return;
        }

        $this->callbacks[] = $callback;
    }

    public function runCallbacks(): void
    {
        if ($this->callbacksExecuted) {
            return;
        }

        $this->callbacksExecuted = true;
        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'setGauge')) {
            ServiceHelpers::setGauge($this->serviceName, $this->serviceName . '_shutdown_in_progress', [], 0.0);
        }
        foreach ($this->callbacks as $callback) {
            try {
                $callback($this);
            } catch (Throwable $e) {
                $this->log('shutdown_callback_failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public function registerSignalHandlers(): bool
    {
        if (php_sapi_name() !== 'cli' || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }

        pcntl_async_signals(true);
        $manager = $this;
        $handler = function (int $signal) use ($manager): void {
            if (defined('SIGALRM') && $signal === SIGALRM) {
                $manager->handleShutdownTimeout();
                return;
            }

            $signalName = match ($signal) {
                SIGTERM => 'SIGTERM',
                SIGINT => 'SIGINT',
                default => 'UNKNOWN',
            };
            $manager->requestShutdown($signalName);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        if (defined('SIGALRM')) {
            pcntl_signal(SIGALRM, $handler);
        }

        return true;
    }

    private function log(string $event, array $context = []): void
    {
        if (class_exists('ServiceHelpers') && method_exists('ServiceHelpers', 'emitStructuredLog')) {
            ServiceHelpers::emitStructuredLog($this->serviceName, 'info', $event, $context);
        }
    }
}
