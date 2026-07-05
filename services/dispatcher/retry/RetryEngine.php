<?php
require_once __DIR__ . '/RetryPolicy.php';

class RetryEngine
{
    private $policy;

    public function __construct(RetryPolicy $policy = null)
    {
        $this->policy = $policy ?: new RetryPolicy();
    }

    public function shouldRetry(string $error, int $attempt): bool
    {
        return $this->policy->isRetryable($error, $attempt);
    }

    public function getDelaySeconds(int $attempt): float
    {
        return $this->policy->getBackoffSeconds($attempt);
    }

    public function getPolicy(): RetryPolicy
    {
        return $this->policy;
    }
}
