<?php

class AssistantContext
{
    public string $assistantId;
    public string $conversationId;
    public ?string $sessionId;
    public ?string $tenantId;
    public ?string $userId;
    public array $variables = [];
    public array $metadata = [];

    public function __construct(string $assistantId, string $conversationId, ?string $sessionId = null, ?string $tenantId = null, ?string $userId = null)
    {
        $this->assistantId = $assistantId;
        $this->conversationId = $conversationId;
        $this->sessionId = $sessionId;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function set(string $k, $v): void { $this->variables[$k] = $v; }
    public function get(string $k, $default = null) { return $this->variables[$k] ?? $default; }
}
