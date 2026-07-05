<?php

class RuntimeExecutionResult
{
    private bool $successful;
    private string $finalResponse;
    private array $metadata;
    private ?ExecutionReport $executionReport;
    private array $payload;

    public function __construct(bool $successful, string $finalResponse = '', array $metadata = [], ?ExecutionReport $executionReport = null, array $payload = [])
    {
        $this->successful = $successful;
        $this->finalResponse = $finalResponse;
        $this->metadata = $metadata;
        $this->executionReport = $executionReport;
        $this->payload = $payload;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getFinalResponse(): string
    {
        return $this->finalResponse;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getExecutionReport(): ?ExecutionReport
    {
        return $this->executionReport;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'finalResponse' => $this->finalResponse,
            'metadata' => $this->metadata,
            'report' => $this->executionReport?->toArray(),
            'payload' => $this->payload,
        ];
    }
}
