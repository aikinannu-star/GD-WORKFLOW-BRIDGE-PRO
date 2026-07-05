<?php

require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/PromptOptimizationResult.php';
require_once __DIR__ . '/../context/summary/ContextSummarizer.php';
require_once __DIR__ . '/../context/ContextBudgeter.php';

class TestOllamaProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok'];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok'];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return ['chat' => true, 'embeddings' => false, 'provider' => 'ollama', 'model' => 'llama3'];
    }
}

class TestVllmProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok'];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok'];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return ['chat' => true, 'embeddings' => false, 'provider' => 'vllm', 'model' => 'qwen'];
    }
}

$optimizer = new PromptOptimizationPipeline();
$result = $optimizer->optimize([
    'instructions' => 'Assistant: process a user message using available tools.',
    'assistantId' => 'assistant-1',
    'sessionId' => 'session-1',
    'userId' => 'user-1',
    'message' => 'Summarize this project.',
    'sections' => [
        ['label' => 'Recent conversation', 'content' => 'User is planning a launch and the assistant is helping with milestones.'],
    ],
    'toolResult' => null,
], new TestOllamaProvider());

if ($result->getFormat() !== 'plain') {
    echo 'Expected Ollama-style optimization to preserve a plain format' . PHP_EOL;
    exit(1);
}

if (($result->getMetadata()['provider'] ?? null) !== 'ollama') {
    echo 'Expected Ollama provider metadata to be preserved' . PHP_EOL;
    exit(1);
}

$ollamaResult = $optimizer->optimize([
    'instructions' => 'Assistant: process a user message using available tools.',
    'assistantId' => 'assistant-1',
    'sessionId' => 'session-1',
    'userId' => 'user-1',
    'message' => 'Summarize this project.',
    'sections' => [
        ['label' => 'Recent conversation', 'content' => 'User is planning a launch and the assistant is helping with milestones.'],
    ],
    'toolResult' => null,
], new TestOllamaProvider());

if ($ollamaResult->getFormat() !== 'plain') {
    echo 'Expected Ollama optimizer to prefer plain formatting' . PHP_EOL;
    exit(1);
}

$vllmResult = $optimizer->optimize([
    'instructions' => 'Assistant: process a user message using available tools.',
    'assistantId' => 'assistant-1',
    'sessionId' => 'session-1',
    'userId' => 'user-1',
    'message' => 'Summarize this project.',
    'sections' => [
        ['label' => 'Recent conversation', 'content' => 'User is planning a launch and the assistant is helping with milestones.'],
    ],
    'toolResult' => null,
], new TestVllmProvider());

if (($vllmResult->getMetadata()['provider'] ?? null) !== 'vllm') {
    echo 'Expected vLLM provider metadata to be preserved' . PHP_EOL;
    exit(1);
}

$summarizer = new ContextSummarizer();
$summary = $summarizer->summarize([['content' => 'This section is very long and should be summarized before being inserted into the prompt.']], 'Please summarize the project.', ['maxTokens' => 24]);
if (stripos($summary, 'summary') === false) {
    echo 'Expected summarizer to produce a concise summary' . PHP_EOL;
    exit(1);
}

echo 'Prompt optimizer integration test passed' . PHP_EOL;
