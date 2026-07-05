<?php

class ModelProfile
{
    private string $family;
    private ?string $name;
    private ?string $provider;
    private int $contextWindow;
    private bool $supportsEmbeddings;
    private bool $supportsTools;
    private bool $supportsVision;
    private bool $supportsJson;
    private bool $supportsStreaming;
    private bool $supportsReasoning;
    private bool $supportsFunctionCalling;
    private bool $supportsThinking;
    private string $preferredInstructionStyle;
    private string $preferredOutputStyle;
    private float $recommendedTemperature;
    private float $recommendedTopP;
    private int $recommendedMaxTokens;

    public function __construct(array $capabilities = [])
    {
        $capabilities = is_array($capabilities) ? $capabilities : [];
        $this->family = strtolower((string)($capabilities['modelFamily'] ?? $capabilities['family'] ?? 'generic'));
        $this->name = isset($capabilities['model']) ? (string)$capabilities['model'] : null;
        $this->provider = isset($capabilities['provider']) ? (string)$capabilities['provider'] : null;
        $this->contextWindow = isset($capabilities['contextWindow']) ? (int)$capabilities['contextWindow'] : 32768;
        $this->supportsEmbeddings = (bool)($capabilities['supportsEmbeddings'] ?? $capabilities['supports_embeddings'] ?? false);
        $this->supportsTools = (bool)($capabilities['supportsTools'] ?? $capabilities['supports_tools'] ?? $capabilities['supportsToolCalling'] ?? $capabilities['supports_tool_calling'] ?? false);
        $this->supportsVision = (bool)($capabilities['supportsVision'] ?? $capabilities['supports_vision'] ?? false);
        $this->supportsJson = (bool)($capabilities['supportsJson'] ?? $capabilities['supports_json'] ?? false);
        $this->supportsStreaming = (bool)($capabilities['supportsStreaming'] ?? $capabilities['supports_streaming'] ?? false);
        $this->supportsReasoning = (bool)($capabilities['supportsReasoning'] ?? $capabilities['supports_reasoning'] ?? false);
        $this->supportsFunctionCalling = (bool)($capabilities['supportsFunctionCalling'] ?? $capabilities['supports_function_calling'] ?? false);
        $this->supportsThinking = (bool)($capabilities['supportsThinking'] ?? $capabilities['supports_thinking'] ?? false);
        $this->preferredInstructionStyle = (string)($capabilities['preferredInstructionStyle'] ?? $capabilities['preferred_instruction_style'] ?? 'plain');
        $this->preferredOutputStyle = (string)($capabilities['preferredOutputStyle'] ?? $capabilities['preferred_output_style'] ?? 'plain');
        $this->recommendedTemperature = (float)($capabilities['recommendedTemperature'] ?? $capabilities['recommended_temperature'] ?? 0.2);
        $this->recommendedTopP = (float)($capabilities['recommendedTopP'] ?? $capabilities['recommended_top_p'] ?? 0.9);
        $this->recommendedMaxTokens = (int)($capabilities['recommendedMaxTokens'] ?? $capabilities['recommended_max_tokens'] ?? 1024);
    }

    public function getFamily(): string { return $this->family; }
    public function getName(): ?string { return $this->name; }
    public function getProvider(): ?string { return $this->provider; }
    public function getContextWindow(): int { return $this->contextWindow; }
    public function supportsEmbeddings(): bool { return $this->supportsEmbeddings; }
    public function supportsTools(): bool { return $this->supportsTools; }
    public function supportsVision(): bool { return $this->supportsVision; }
    public function supportsJson(): bool { return $this->supportsJson; }
    public function supportsStreaming(): bool { return $this->supportsStreaming; }
    public function supportsReasoning(): bool { return $this->supportsReasoning; }
    public function supportsFunctionCalling(): bool { return $this->supportsFunctionCalling; }
    public function supportsThinking(): bool { return $this->supportsThinking; }
    public function getPreferredInstructionStyle(): string { return $this->preferredInstructionStyle; }
    public function getPreferredOutputStyle(): string { return $this->preferredOutputStyle; }
    public function getRecommendedTemperature(): float { return $this->recommendedTemperature; }
    public function getRecommendedTopP(): float { return $this->recommendedTopP; }
    public function getRecommendedMaxTokens(): int { return $this->recommendedMaxTokens; }
}
