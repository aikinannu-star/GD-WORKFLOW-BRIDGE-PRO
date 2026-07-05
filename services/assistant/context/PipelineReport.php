<?php

class PipelineReport
{
    private string $name;
    private ?float $startedAt = null;
    private ?float $finishedAt = null;
    private array $stagesExecuted = [];
    private array $stagesSkipped = [];
    private array $messages = [];
    private array $warnings = [];
    private array $errors = [];
    private array $statistics = [];
    private array $metadata = [];

    public function __construct(string $name = 'pipeline')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function start(): self
    {
        $this->startedAt = microtime(true);
        return $this;
    }

    public function finish(): self
    {
        $this->finishedAt = microtime(true);
        return $this;
    }

    public function getStartedAt(): ?float
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?float
    {
        return $this->finishedAt;
    }

    public function getDuration(): ?float
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return null;
        }

        return $this->finishedAt - $this->startedAt;
    }

    public function addStageExecuted(string $name, array $details = []): self
    {
        $this->stagesExecuted[] = [
            'name' => $name,
            'details' => $details,
        ];

        return $this;
    }

    public function addStageSkipped(string $name, array $details = []): self
    {
        $this->stagesSkipped[] = [
            'name' => $name,
            'details' => $details,
        ];

        return $this;
    }

    public function addMessage(string $level, string $message): self
    {
        $this->messages[] = [
            'level' => $level,
            'message' => $message,
        ];

        if ($level === 'warning') {
            $this->warnings[] = $message;
        }

        if ($level === 'error') {
            $this->errors[] = $message;
        }

        return $this;
    }

    public function addWarning(string $message): self
    {
        return $this->addMessage('warning', $message);
    }

    public function addError(string $message): self
    {
        return $this->addMessage('error', $message);
    }

    public function setStatistics(array $statistics): self
    {
        $this->statistics = $statistics;
        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getStagesExecuted(): array
    {
        return $this->stagesExecuted;
    }

    public function getStagesSkipped(): array
    {
        return $this->stagesSkipped;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'duration' => $this->getDuration(),
            'stagesExecuted' => $this->stagesExecuted,
            'stagesSkipped' => $this->stagesSkipped,
            'messages' => $this->messages,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'statistics' => $this->statistics,
            'metadata' => $this->metadata,
        ];
    }
}
