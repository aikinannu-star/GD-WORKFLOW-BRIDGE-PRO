<?php
class ActionResult
{
    private $success;
    private $output;
    private $logs;
    private $warnings;
    private $duration;
    private $nextNode;
    private $retry;
    private $error;

    public function __construct(bool $success, array $output = [], array $logs = [], array $warnings = [], float $duration = 0.0, $nextNode = null, bool $retry = false, ?string $error = null)
    {
        $this->success = $success;
        $this->output = $output;
        $this->logs = $logs;
        $this->warnings = $warnings;
        $this->duration = $duration;
        $this->nextNode = $nextNode;
        $this->retry = $retry;
        $this->error = $error;
    }

    public static function success(array $output = [], $nextNode = null, array $logs = [], array $warnings = [], float $duration = 0.0): self
    {
        return new self(true, $output, $logs, $warnings, $duration, $nextNode, false, null);
    }

    public static function failure(string $error, array $output = [], bool $retry = false, $nextNode = null): self
    {
        return new self(false, $output, [], [], 0.0, $nextNode, $retry, $error);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getOutput(): array
    {
        return $this->output;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getNextNode()
    {
        return $this->nextNode;
    }

    public function shouldRetry(): bool
    {
        return $this->retry;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setDuration(float $duration): void
    {
        $this->duration = $duration;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'logs' => $this->logs,
            'warnings' => $this->warnings,
            'duration' => $this->duration,
            'nextNode' => $this->nextNode,
            'retry' => $this->retry,
            'error' => $this->error,
        ];
    }
}
