<?php

/**
 * CapabilityRoutingExample — Shows how to use ProviderCapabilities for pipeline decisions
 * 
 * Instead of: if ($provider === 'openai') { enableToolCalling(); }
 * Use:        if ($metadata->getCapabilities()->supportsToolCalling()) { enableToolCalling(); }
 * 
 * This decouples pipeline logic from provider names, enabling new providers without code changes.
 */

require_once __DIR__ . '/ProviderCapabilities.php';
require_once __DIR__ . '/ProviderMetadata.php';

class CapabilityRoutingExample
{
    /**
     * Example 1: Tool Execution Stage — Decision based on capability
     */
    public static function executeToolsStage(ProviderMetadata $metadata, array $tools): void
    {
        if ($metadata->getCapabilities()->supportsToolCalling()) {
            echo "✅ Provider supports tool calling: {$metadata->providerName}\n";
            echo "   → Include tool definitions in prompt\n";
            echo "   → Parse tool calls from response\n";
            echo "   → Execute tools and feed back to model\n";
        } else {
            echo "⚠️  Provider does NOT support tool calling: {$metadata->providerName}\n";
            echo "   → Skip tool definitions\n";
            echo "   → Manual tool handling required\n";
        }
    }

    /**
     * Example 2: Token Estimation — Skip if provider reports real usage
     */
    public static function estimateTokenUsage(ProviderMetadata $metadata, string $prompt, string $completion): array
    {
        if ($metadata->getCapabilities()->reportsRealTokenUsage()) {
            echo "✅ Provider reports real token usage\n";
            echo "   → Use usage from API response\n";
            return ['source' => 'reported', 'promptTokens' => 0, 'completionTokens' => 0];
        } else {
            echo "📊 Provider does NOT report token usage\n";
            echo "   → Estimate using token counter\n";
            return ['source' => 'estimated', 'promptTokens' => 123, 'completionTokens' => 45];
        }
    }

    /**
     * Example 3: Streaming Support — Feature flag-based
     */
    public static function configureStreaming(ProviderMetadata $metadata, bool $requestStreaming): bool
    {
        if ($requestStreaming && $metadata->getCapabilities()->supportsStreaming()) {
            echo "🚀 Streaming enabled for {$metadata->providerName}\n";
            echo "   → Use streaming API endpoint\n";
            echo "   → Buffer and emit tokens\n";
            return true;
        } else {
            echo "📌 Streaming disabled for {$metadata->providerName}\n";
            echo "   → Use standard blocking API\n";
            echo "   → Return complete response\n";
            return false;
        }
    }

    /**
     * Example 4: Vision Support — Optional capability
     */
    public static function processVisionRequest(ProviderMetadata $metadata, array $messages): void
    {
        $hasImage = false;
        foreach ($messages as $msg) {
            if (isset($msg['content']) && is_array($msg['content'])) {
                foreach ($msg['content'] as $block) {
                    if (isset($block['type']) && $block['type'] === 'image') {
                        $hasImage = true;
                        break;
                    }
                }
            }
        }

        if ($hasImage) {
            if ($metadata->getCapabilities()->supportsVision()) {
                echo "👁️  Vision request supported by {$metadata->providerName}\n";
                echo "   → Include image URLs/data in request\n";
            } else {
                echo "❌ Vision request NOT supported by {$metadata->providerName}\n";
                echo "   → Reject request or use text-only fallback\n";
                throw new \Exception("Provider {$metadata->providerName} does not support vision");
            }
        }
    }

    /**
     * Example 5: Cost Calculation — Conditional based on provider capability
     */
    public static function calculateCost(ProviderMetadata $metadata, int $promptTokens, int $completionTokens): ?float
    {
        if (!$metadata->getCapabilities()->reportsCost()) {
            echo "⚠️  {$metadata->providerName} does not report cost capability\n";
            echo "   → Use local pricing profile\n";
        }

        $pricing = $metadata->pricingProfile;
        if (empty($pricing)) {
            echo "   ⚠️  No pricing profile registered\n";
            return null;
        }

        $promptCost = ($promptTokens / 1000) * ($pricing['prompt_per_1k'] ?? 0);
        $completionCost = ($completionTokens / 1000) * ($pricing['completion_per_1k'] ?? 0);
        $total = $promptCost + $completionCost;

        echo "   → Calculated cost: \${$total}\n";
        return $total;
    }

    /**
     * Example 6: Registering Custom Providers — Declarative capability binding
     */
    public static function registerCustomProvider(): ProviderMetadata
    {
        echo "\n--- Custom Provider Registration Example ---\n";

        // Old way: Hardcode provider-specific logic throughout codebase
        // if ($provider === 'my_custom_llm') { ... special handling ... }
        
        // New way: Register once with declarative capabilities
        $customCapabilities = ProviderCapabilities::custom()
            ->withToolCalling(true)
            ->withRealTokenUsage(true)
            ->withStreaming(false)  // No streaming support
            ->withVision(true)
            ->withCostReporting(true)
            ->withApiVersion('v2024-07');

        $custom = new ProviderMetadata(
            'my_custom_llm',
            null,
            $customCapabilities,
            [
                'currency' => 'USD',
                'prompt_per_1k' => 0.001,
                'completion_per_1k' => 0.002,
            ],
            'https://my-llm.example.com/api/invoke'
        );

        echo "✅ Custom provider registered: {$custom->providerName}\n";
        echo "   Capabilities:\n";
        echo "   - Tool calling: " . ($custom->getCapabilities()->supportsToolCalling() ? 'YES' : 'NO') . "\n";
        echo "   - Real token usage: " . ($custom->getCapabilities()->reportsRealTokenUsage() ? 'YES' : 'NO') . "\n";
        echo "   - Streaming: " . ($custom->getCapabilities()->supportsStreaming() ? 'YES' : 'NO') . "\n";
        echo "   - Vision: " . ($custom->getCapabilities()->supportsVision() ? 'YES' : 'NO') . "\n";

        return $custom;
    }

    /**
     * Main example runner
     */
    public static function runExamples(): void
    {
        echo "\n=== Provider Capability Routing Examples ===\n\n";

        // Setup different providers
        $openai = ProviderMetadata::fromArray([
            'providerName' => 'openai',
            'model' => 'gpt-4',
        ]);

        $local = ProviderMetadata::fromArray([
            'providerName' => 'local',
            'model' => 'llama-7b',
        ]);

        $cohere = ProviderMetadata::fromArray([
            'providerName' => 'cohere',
            'model' => 'command-r',
        ]);

        $tools = [
            ['name' => 'search', 'description' => 'Search the web'],
            ['name' => 'calculator', 'description' => 'Calculate math'],
        ];

        // Example 1: Tool support
        echo "--- Example 1: Tool Calling Support ---\n";
        self::executeToolsStage($openai, $tools);
        echo "\n";
        self::executeToolsStage($local, $tools);
        echo "\n";

        // Example 2: Token usage
        echo "--- Example 2: Token Usage Reporting ---\n";
        echo "OpenAI: ";
        self::estimateTokenUsage($openai, "What is 2+2?", "2+2=4");
        echo "\nLocal: ";
        self::estimateTokenUsage($local, "What is 2+2?", "2+2=4");
        echo "\n";

        // Example 3: Streaming
        echo "--- Example 3: Streaming Support ---\n";
        self::configureStreaming($openai, true);
        echo "\n";
        self::configureStreaming($local, true);
        echo "\n";

        // Example 4: Vision
        echo "--- Example 4: Vision Support ---\n";
        try {
            $messages = [['content' => [['type' => 'image', 'url' => 'data:image/png;...']]]];
            self::processVisionRequest($openai, $messages);
            echo "\n";
            self::processVisionRequest($local, $messages);
        } catch (\Exception $e) {
            echo "Caught expected error: " . $e->getMessage() . "\n\n";
        }

        // Example 5: Cost calculation
        echo "--- Example 5: Cost Calculation ---\n";
        echo "OpenAI: ";
        self::calculateCost($openai, 100, 50);
        echo "\nCohere: ";
        self::calculateCost($cohere, 100, 50);
        echo "\n";

        // Example 6: Custom provider registration
        self::registerCustomProvider();

        echo "\n=== Summary ===\n";
        echo "✅ Capabilities enable provider-agnostic pipeline routing\n";
        echo "✅ New providers require no pipeline code changes\n";
        echo "✅ Feature flags prevent feature-specific conditionals\n";
    }
}

// Run if executed directly
CapabilityRoutingExample::runExamples();
