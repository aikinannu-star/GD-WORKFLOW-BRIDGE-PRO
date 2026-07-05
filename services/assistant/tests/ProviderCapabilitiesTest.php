<?php

require_once __DIR__ . '/../execution/ProviderCapabilities.php';
require_once __DIR__ . '/../execution/ProviderMetadata.php';

class ProviderCapabilitiesTest
{
    public function run(): bool
    {
        try {
            $this->testCapabilityProfilesForCommonProviders();
            $this->testCapabilityBuilderPattern();
            $this->testCapabilityArraySerialization();
            $this->testProviderMetadataIntegration();
            $this->testCapabilityBasedConditionals();

            echo "✅ Provider capabilities test passed\n";
            return true;
        } catch (\Exception $e) {
            echo "❌ Provider capabilities test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testCapabilityProfilesForCommonProviders(): void
    {
        // OpenAI
        $openai = ProviderCapabilities::forProvider('openai');
        if (!$openai->supportsToolCalling()) {
            throw new \Exception('OpenAI should support tool calling');
        }
        if (!$openai->reportsRealTokenUsage()) {
            throw new \Exception('OpenAI should report real token usage');
        }
        if (!$openai->supportsStreaming()) {
            throw new \Exception('OpenAI should support streaming');
        }

        // Anthropic
        $anthropic = ProviderCapabilities::forProvider('anthropic');
        if (!$anthropic->supportsToolCalling()) {
            throw new \Exception('Anthropic should support tool calling');
        }
        if (!$anthropic->reportsRealTokenUsage()) {
            throw new \Exception('Anthropic should report real token usage');
        }

        // Local (minimal)
        $local = ProviderCapabilities::forProvider('local');
        if ($local->supportsToolCalling()) {
            throw new \Exception('Local should not support tool calling');
        }
        if ($local->reportsRealTokenUsage()) {
            throw new \Exception('Local should not report real token usage');
        }

        // Custom (defaults)
        $custom = ProviderCapabilities::forProvider('custom');
        if ($custom->supportsToolCalling()) {
            throw new \Exception('Custom defaults should be conservative');
        }
    }

    private function testCapabilityBuilderPattern(): void
    {
        $custom = ProviderCapabilities::custom()
            ->withToolCalling(true)
            ->withRealTokenUsage(true)
            ->withStreaming(true)
            ->withVision(true)
            ->withCostReporting(true);

        if (!$custom->supportsToolCalling()) {
            throw new \Exception('Builder: tool calling not set');
        }
        if (!$custom->reportsRealTokenUsage()) {
            throw new \Exception('Builder: real token usage not set');
        }
        if (!$custom->supportsStreaming()) {
            throw new \Exception('Builder: streaming not set');
        }
        if (!$custom->supportsVision()) {
            throw new \Exception('Builder: vision not set');
        }
        if (!$custom->reportsCost()) {
            throw new \Exception('Builder: cost reporting not set');
        }
    }

    private function testCapabilityArraySerialization(): void
    {
        $original = ProviderCapabilities::openai()
            ->withApiVersion('2024-06');

        $array = $original->toArray();
        if (!is_array($array)) {
            throw new \Exception('toArray() should return array');
        }
        if ($array['supportsToolCalling'] !== true) {
            throw new \Exception('toArray serialization failed: supportsToolCalling');
        }
        if ($array['apiVersion'] !== '2024-06') {
            throw new \Exception('toArray serialization failed: apiVersion');
        }

        // Round-trip
        $restored = ProviderCapabilities::fromArray($array);
        if (!$restored->supportsToolCalling()) {
            throw new \Exception('fromArray() round-trip failed');
        }
        if ($restored->getApiVersion() !== '2024-06') {
            throw new \Exception('fromArray() version not restored');
        }
    }

    private function testProviderMetadataIntegration(): void
    {
        // Create metadata with explicit capabilities
        $capabilities = ProviderCapabilities::openai();
        $metadata = new ProviderMetadata('openai', 'gpt-4', $capabilities, [
            'prompt_per_1k' => 0.03,
            'completion_per_1k' => 0.06,
        ], 'https://api.openai.com/v1/chat/completions');

        // Verify provider name and model
        if ($metadata->providerName !== 'openai') {
            throw new \Exception('Provider name not set in metadata');
        }
        if ($metadata->model !== 'gpt-4') {
            throw new \Exception('Model not set in metadata');
        }

        // Verify capabilities accessible
        if (!$metadata->getCapabilities()->supportsToolCalling()) {
            throw new \Exception('Metadata capabilities not accessible');
        }

        // Serialize and round-trip
        $array = $metadata->toArray();
        if ($array['capabilities']['supportsToolCalling'] !== true) {
            throw new \Exception('Metadata toArray() did not serialize capabilities');
        }

        $restored = ProviderMetadata::fromArray($array);
        if (!$restored->getCapabilities()->supportsToolCalling()) {
            throw new \Exception('Metadata fromArray() did not restore capabilities');
        }
    }

    private function testCapabilityBasedConditionals(): void
    {
        // Simulate pipeline logic using capabilities instead of provider name
        $openai = ProviderMetadata::fromArray([
            'providerName' => 'openai',
            'model' => 'gpt-4',
        ]);

        $local = ProviderMetadata::fromArray([
            'providerName' => 'local',
            'model' => 'llama-7b',
        ]);

        // OLD WAY (bad):
        // if ($openai->providerName === 'openai') { enableToolCalling(); }
        
        // NEW WAY (good):
        if ($openai->getCapabilities()->supportsToolCalling()) {
            // Tool calling enabled for OpenAI
        } else {
            throw new \Exception('Capability check failed for OpenAI');
        }

        if ($local->getCapabilities()->supportsToolCalling()) {
            throw new \Exception('Local should not support tool calling');
        }

        // Usage estimation logic
        if ($openai->getCapabilities()->reportsRealTokenUsage()) {
            // Skip estimation, use reported tokens
        } else {
            throw new \Exception('OpenAI should report real tokens');
        }

        if ($local->getCapabilities()->reportsRealTokenUsage()) {
            throw new \Exception('Local should not report real tokens');
        }

        // Streaming decision
        if ($openai->getCapabilities()->supportsStreaming()) {
            // Enable streaming
        } else {
            throw new \Exception('OpenAI should support streaming');
        }
    }
}

$test = new ProviderCapabilitiesTest();
exit($test->run() ? 0 : 1);
