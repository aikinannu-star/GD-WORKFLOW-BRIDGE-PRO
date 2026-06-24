<?php
declare(strict_types=1);

/**
 * ArtifactManager
 *
 * Manages compiled policy artifact loading with change detection and metrics.
 */
class ArtifactManager
{
    private ?array $cachedArtifact = null;
    private ?string $cachedPath = null;
    private ?string $lastDigest = null;
    private int $reloadCount = 0;
    private float $lastReloadTime = 0.0;
    private int $evaluationCount = 0;
    private int $signatureVerifyCount = 0;
    private int $signatureFailCount = 0;

    public function __construct(private string $artifactPath)
    {
    }

    /**
     * Load artifact, detecting changes and returning whether it changed.
     */
    public function load(): bool
    {
        if (!file_exists($this->artifactPath)) {
            $this->cachedArtifact = null;
            $this->lastDigest = null;
            return false;
        }

        $content = file_get_contents($this->artifactPath);
        if ($content === false) {
            $this->cachedArtifact = null;
            $this->lastDigest = null;
            return false;
        }

        $currentDigest = hash('sha256', $content);
        $changed = ($this->lastDigest !== $currentDigest);

        $artifact = json_decode($content, true);
        if (!is_array($artifact)) {
            $this->cachedArtifact = null;
            $this->lastDigest = null;
            return false;
        }

        $this->cachedArtifact = $artifact;
        $this->cachedPath = $this->artifactPath;

        if ($changed) {
            $this->lastDigest = $currentDigest;
            $this->reloadCount++;
            $this->lastReloadTime = microtime(true);
        }

        return $changed;
    }

    public function getArtifact(): ?array
    {
        return $this->cachedArtifact;
    }

    public function getMetadata(): ?array
    {
        return is_array($this->cachedArtifact['metadata'] ?? null) ? $this->cachedArtifact['metadata'] : null;
    }

    public function recordEvaluation(): void
    {
        $this->evaluationCount++;
    }

    public function recordSignatureVerify(bool $success): void
    {
        $this->signatureVerifyCount++;
        if (!$success) {
            $this->signatureFailCount++;
        }
    }

    public function getReloadCount(): int
    {
        return $this->reloadCount;
    }

    public function getLastReloadTime(): float
    {
        return $this->lastReloadTime;
    }

    public function getEvaluationCount(): int
    {
        return $this->evaluationCount;
    }

    public function getSignatureVerifyCount(): int
    {
        return $this->signatureVerifyCount;
    }

    public function getSignatureFailCount(): int
    {
        return $this->signatureFailCount;
    }

    public function getLastDigest(): ?string
    {
        return $this->lastDigest;
    }

    public function isHealthy(): bool
    {
        return $this->cachedArtifact !== null && $this->lastDigest !== null;
    }
}
