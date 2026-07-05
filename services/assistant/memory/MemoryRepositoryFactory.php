<?php

require_once __DIR__ . '/MemoryRepositoryInterface.php';
require_once __DIR__ . '/FileMemoryRepository.php';
require_once __DIR__ . '/SqlMemoryRepository.php';
require_once __DIR__ . '/HybridMemoryRepository.php';
require_once __DIR__ . '/VectorMemoryRepository.php';

class MemoryRepositoryFactory
{
    public static function create(array $options = []): MemoryRepositoryInterface
    {
        $repositoryType = strtolower($options['memory_repository'] ?? 'file');
        $memoryPath = $options['memory_path'] ?? null;

        $listLimit = isset($options['memory_list_limit']) ? (int)$options['memory_list_limit'] : 1000;

        switch ($repositoryType) {
            case 'file':
                return new FileMemoryRepository($memoryPath, $listLimit);
            case 'sql':
                $pdo = self::createPdo($options);
                return new SqlMemoryRepository($pdo);
            case 'hybrid':
                $pdo = self::createPdo($options);
                $primary = new SqlMemoryRepository($pdo);
                $secondary = new FileMemoryRepository($memoryPath, $listLimit);
                return new HybridMemoryRepository($primary, $secondary);
            case 'vector':
                return new VectorMemoryRepository($memoryPath, $listLimit);
            default:
                throw new InvalidArgumentException('Unsupported memory repository type: ' . $repositoryType);
        }
    }

    private static function createPdo(array $options): PDO
    {
        $dsn = $options['memory_dsn'] ?? 'sqlite:' . __DIR__ . '/../../data/assistant/memory.db';
        if (stripos($dsn, 'sqlite:') === 0 && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new InvalidArgumentException('SQLite PDO driver is required for SQL memory repository but is not available.');
        }

        $username = $options['memory_dsn_username'] ?? null;
        $password = $options['memory_dsn_password'] ?? null;
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
