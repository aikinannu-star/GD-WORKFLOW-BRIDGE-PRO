<?php

require_once __DIR__ . '/../execution/ExecutionReport.php';

class ExecutionReportAnalyticsTest
{
    public function run(): bool
    {
        try {
            $this->testTokenAccountingFields();
            $this->testCostReconciliationFields();
            $this->testPerformanceMetricsFields();
            $this->testResponseCharacteristicsFields();
            $this->testAnalyticsInSerialization();
            $this->testAnalyticsAccumulationAndModification();

            echo "✅ Execution report analytics test passed\n";
            return true;
        } catch (\Exception $e) {
            echo "❌ Execution report analytics test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function testTokenAccountingFields(): void
    {
        $report = new ExecutionReport('exec_analytics_1');

        // Test cached tokens
        $report->addCachedTokens(100);
        if ($report->getCachedTokens() !== 100) {
            throw new \Exception('Cached tokens not added correctly');
        }
        $report->addCachedTokens(50);
        if ($report->getCachedTokens() !== 150) {
            throw new \Exception('Cached tokens accumulation failed');
        }

        // Test embedding tokens
        $report->addEmbeddingTokens(200);
        if ($report->getEmbeddingTokens() !== 200) {
            throw new \Exception('Embedding tokens not added correctly');
        }
        $report->setEmbeddingTokens(300);
        if ($report->getEmbeddingTokens() !== 300) {
            throw new \Exception('Embedding tokens set failed');
        }
    }

    private function testCostReconciliationFields(): void
    {
        $report = new ExecutionReport('exec_analytics_2');

        // Estimated vs actual cost
        $report->addLLMUsage(100, 50, 0.005, 'USD');
        if ($report->toArray()['llmUsage']['estimatedCost'] !== 0.005) {
            throw new \Exception('LLM usage not tracked');
        }

        // Set actual cost for reconciliation
        $report->setActualCost(0.0048);
        if ($report->getActualCost() !== 0.0048) {
            throw new \Exception('Actual cost not set');
        }

        // Null cost case
        $report->setActualCost(null);
        if ($report->getActualCost() !== null) {
            throw new \Exception('Actual cost null not handled');
        }
    }

    private function testPerformanceMetricsFields(): void
    {
        $report = new ExecutionReport('exec_analytics_3');

        // Queue time
        $report->setQueueTimeMs(500);
        if ($report->getQueueTimeMs() !== 500) {
            throw new \Exception('Queue time not set');
        }

        // Tool count
        $report->setToolCount(3);
        if ($report->getToolCount() !== 3) {
            throw new \Exception('Tool count not set');
        }
        $report->addToolEvent(['name' => 'search']);
        $report->addToolEvent(['name' => 'calculator']);
        if (count($report->toArray()['tools']) !== 2) {
            throw new \Exception('Tool events not tracked');
        }
    }

    private function testResponseCharacteristicsFields(): void
    {
        $report = new ExecutionReport('exec_analytics_4');

        // Streaming flag
        if ($report->isStreamed()) {
            throw new \Exception('Streamed should default to false');
        }
        $report->setStreamed(true);
        if (!$report->isStreamed()) {
            throw new \Exception('Streamed flag not set');
        }

        // Provider version
        $report->setProviderVersion('gpt-4-0613');
        if ($report->getProviderVersion() !== 'gpt-4-0613') {
            throw new \Exception('Provider version not set');
        }

        // Retry count
        if ($report->getRetryCount() !== 0) {
            throw new \Exception('Retry count should default to 0');
        }
        $report->incrementRetryCount();
        if ($report->getRetryCount() !== 1) {
            throw new \Exception('Retry count increment failed');
        }
        $report->setRetryCount(3);
        if ($report->getRetryCount() !== 3) {
            throw new \Exception('Retry count set failed');
        }
    }

    private function testAnalyticsInSerialization(): void
    {
        $report = new ExecutionReport('exec_analytics_5');
        
        $report->addCachedTokens(100)
               ->addEmbeddingTokens(200)
               ->setActualCost(0.0075)
               ->setQueueTimeMs(250)
               ->setToolCount(2)
               ->setStreamed(true)
               ->setProviderVersion('claude-3-opus')
               ->setRetryCount(1);

        $array = $report->toArray();

        // Verify all analytics fields in serialization
        if ($array['cachedTokens'] !== 100) {
            throw new \Exception('Cached tokens not in serialization');
        }
        if ($array['embeddingTokens'] !== 200) {
            throw new \Exception('Embedding tokens not in serialization');
        }
        if ($array['actualCost'] !== 0.0075) {
            throw new \Exception('Actual cost not in serialization');
        }
        if ($array['queueTimeMs'] !== 250) {
            throw new \Exception('Queue time not in serialization');
        }
        if ($array['toolCount'] !== 2) {
            throw new \Exception('Tool count not in serialization');
        }
        if ($array['streamed'] !== true) {
            throw new \Exception('Streamed flag not in serialization');
        }
        if ($array['providerVersion'] !== 'claude-3-opus') {
            throw new \Exception('Provider version not in serialization');
        }
        if ($array['retryCount'] !== 1) {
            throw new \Exception('Retry count not in serialization');
        }
    }

    private function testAnalyticsAccumulationAndModification(): void
    {
        $report = new ExecutionReport('exec_analytics_6');

        // Build up analytics over time
        $report->setQueueTimeMs(100);
        $report->addCachedTokens(50);
        $report->addCachedTokens(50);
        
        // Simulate processing
        $report->setStreamed(true);
        $report->setProviderVersion('gpt-4');
        
        // First request
        $report->addToolEvent(['name' => 'search', 'result' => '5 pages']);
        
        // Estimate cost
        $report->addLLMUsage(150, 75, 0.0045, 'USD');
        
        // Then get actual from provider
        $report->setActualCost(0.0044);
        
        // Verify complete analytics picture
        $array = $report->toArray();
        
        if ($array['cachedTokens'] !== 100) {
            throw new \Exception('Cache accumulation failed');
        }
        if ($array['queueTimeMs'] !== 100) {
            throw new \Exception('Queue time not persisted');
        }
        if (!$array['streamed']) {
            throw new \Exception('Streaming flag not persisted');
        }
        if ($array['llmUsage']['promptTokens'] !== 150) {
            throw new \Exception('LLM tokens not tracked');
        }
        if ($array['actualCost'] !== 0.0044) {
            throw new \Exception('Cost reconciliation failed');
        }
    }
}

$test = new ExecutionReportAnalyticsTest();
exit($test->run() ? 0 : 1);
