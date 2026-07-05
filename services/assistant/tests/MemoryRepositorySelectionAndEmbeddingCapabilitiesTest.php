<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../memory/MemoryStore.php';
require_once __DIR__ . '/../memory/MemoryRecord.php';
require_once __DIR__ . '/../memory/FileMemoryRepository.php';
require_once __DIR__ . '/../memory/MemoryRetrievalService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

function assertInstanceOf(string $class, $object, string $message): void
{
    if (!($object instanceof $class)) {
        $actual = is_object($object) ? get_class($object) : gettype($object);
        echo "FAIL: {$message} (expected {$class}, got {$actual})\n";
        exit(1);
    }
}

function runRepositorySelectionTests(string $basePath): void
{
    $cases = [
        ['config' => ['memory_path' => $basePath . '/file'], 'expected' => 'FileMemoryRepository', 'description' => 'Default file backend'],
        ['config' => ['memory_repository' => 'file', 'memory_path' => $basePath . '/file_explicit'], 'expected' => 'FileMemoryRepository', 'description' => 'Explicit file backend'],
        ['config' => ['memory_repository' => 'sql', 'memory_dsn' => 'sqlite::memory:'], 'expected' => 'SqlMemoryRepository', 'description' => 'SQL backend selection'],
        ['config' => ['memory_repository' => 'hybrid', 'memory_dsn' => 'sqlite::memory:'], 'expected' => 'HybridMemoryRepository', 'description' => 'Hybrid backend selection'],
        ['config' => ['memory_repository' => 'vector', 'memory_path' => $basePath . '/vector'], 'expected' => 'VectorMemoryRepository', 'description' => 'Vector backend selection'],
    ];

    $supportsSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);

    foreach ($cases as $case) {
        if (in_array($case['expected'], ['SqlMemoryRepository', 'HybridMemoryRepository'], true) && !$supportsSqlite) {
            echo "SKIP: {$case['description']} (SQLite PDO driver unavailable)\n";
            continue;
        }

        $result = RuntimeBootstrap::bootstrap($case['config']);
        $repository = $result['memoryRepository'];
        assertInstanceOf($case['expected'], $repository, $case['description']);
    }

    $caught = false;
    try {
        RuntimeBootstrap::bootstrap(['memory_repository' => 'unknown', 'memory_path' => $basePath . '/unknown']);
    } catch (InvalidArgumentException $exception) {
        $caught = true;
        assertTrue(strpos($exception->getMessage(), 'Unsupported memory repository type') !== false, 'Unknown backend reported a clear configuration error');
    }

    assertTrue($caught, 'Unknown backend should throw an InvalidArgumentException');
}

class EmbeddingDisabledProvider implements ModelProviderInterface
{
    public int $embeddingsCalled = 0;

    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function embeddings(string $input, array $options = []): array
    {
        $this->embeddingsCalled++;
        return ['vector' => [0.0, 0.0, 0.0]];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'embeddings' => false,
        ];
    }
}

class EmbeddingEnabledProvider implements ModelProviderInterface
{
    public int $embeddingsCalled = 0;

    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok', 'raw' => ['prompt' => $prompt]];
    }

    public function embeddings(string $input, array $options = []): array
    {
        $this->embeddingsCalled++;
        return ['vector' => [1.0, 0.0, 0.0]];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'embeddings' => true,
        ];
    }
}

function runEmbeddingCapabilityTests(string $basePath): void
{
    $repository = new FileMemoryRepository($basePath . '/embedding');
    $store = new MemoryStore($repository);
    $record = new MemoryRecord([
        'tenantId' => 'tenant-1',
        'userId' => 'user-42',
        'type' => 'preference',
        'content' => 'User prefers email updates',
        'confidence' => 0.0,
        'tags' => ['preference'],
        'metadata' => ['embedding' => [1.0, 0.0, 0.0]],
    ]);
    $store->add($record);

    $fallbackProvider = new EmbeddingDisabledProvider();
    $fallbackService = new MemoryRetrievalService($store, $fallbackProvider);
    $fallbackResults = $fallbackService->retrieve('user-42', 'tenant-1', 'vector-match');
    assertTrue(count($fallbackResults) === 1, 'Fallback retrieval still returns a relevant match when embeddings are unavailable');
    assertTrue($fallbackProvider->embeddingsCalled === 0, 'Fallback provider does not request embeddings when capability is disabled');

    $semanticProvider = new EmbeddingEnabledProvider();
    $semanticService = new MemoryRetrievalService($store, $semanticProvider);
    $semanticResults = $semanticService->retrieve('user-42', 'tenant-1', 'vector-match');
    assertTrue(count($semanticResults) === 1, 'Semantic ranking path is exercised when embeddings are available');
    assertTrue($semanticProvider->embeddingsCalled > 0, 'Semantic provider requests embeddings when capability is enabled');
}

$basePath = sys_get_temp_dir() . '/assistant_memory_test_' . uniqid();
@mkdir($basePath, 0775, true);
runRepositorySelectionTests($basePath);
runEmbeddingCapabilityTests($basePath);

echo 'Memory repository selection and embedding capability tests passed' . PHP_EOL;
