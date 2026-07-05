<?php
class ExecutionContext
{
    private $workflowId;
    private $executionId;
    private $tenantId;
    private $variables;
    private $trigger;
    private $currentNode;
    private $metadata;
    private $logs;

    public function __construct(string $workflowId, string $executionId, string $tenantId, array $variables = [], $trigger = null, $currentNode = null, array $metadata = [])
    {
        $this->workflowId = $workflowId;
        $this->executionId = $executionId;
        $this->tenantId = $tenantId;
        $this->variables = $variables;
        $this->trigger = $trigger;
        $this->currentNode = $currentNode;
        $this->metadata = $metadata;
        $this->logs = [];
    }

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function setVariable(string $name, $value): void
    {
        $this->variables[$name] = $value;
    }

    public function getVariable(string $name, $default = null)
    {
        return $this->variables[$name] ?? $default;
    }

    public function setVariables(array $variables): void
    {
        $this->variables = array_merge($this->variables, $variables);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function setTrigger($trigger): void
    {
        $this->trigger = $trigger;
    }

    public function getTrigger()
    {
        return $this->trigger;
    }

    public function setCurrentNode($currentNode): void
    {
        $this->currentNode = $currentNode;
    }

    public function getCurrentNode()
    {
        return $this->currentNode;
    }

    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }
}
