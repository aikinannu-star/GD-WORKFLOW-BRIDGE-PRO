<?php
class RetryPolicy
{
    private $maxAttempts;
    private $backoffSeconds;
    private $exponentialBackoff;
    private $retryableErrors;

    public function __construct(int $maxAttempts = 3, float $backoffSeconds = 1.0, bool $exponentialBackoff = true, array $retryableErrors = [])
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->backoffSeconds = max(0.0, $backoffSeconds);
        $this->exponentialBackoff = $exponentialBackoff;
        $this->retryableErrors = $retryableErrors;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getBackoffSeconds(int $attempt): float
    {
        $base = $this->backoffSeconds;
        if ($this->exponentialBackoff && $attempt > 1) {
            $base *= $attempt;
        }
        return max(0.0, $base);
    }

    public function isRetryable(string $error, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if (empty($this->retryableErrors)) {
            return true;
        }
        return in_array($error, $this->retryableErrors, true);
    }
}
