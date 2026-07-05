<?php
require_once __DIR__ . '/QueueInterface.php';

class FileQueue implements QueueInterface
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/queue';
        if (!is_dir($this->basePath)) { mkdir($this->basePath, 0775, true); }
    }

    public function enqueue(array $item): string
    {
        $id = $item['id'] ?? bin2hex(random_bytes(8));
        $item['id'] = $id;
        $file = $this->basePath . DIRECTORY_SEPARATOR . $id . '.json';
        file_put_contents($file, json_encode($item, JSON_PRETTY_PRINT));
        return $id;
    }

    public function dequeue(): ?array
    {
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . '*.json');
        sort($files);
        if (empty($files)) {
            return null;
        }
        $file = array_shift($files);
        $item = json_decode(file_get_contents($file), true);
        if (!is_array($item)) {
            return null;
        }
        unlink($file);
        return $item;
    }

    public function ack(string $id): void
    {
    }

    public function size(): int
    {
        return count(glob($this->basePath . DIRECTORY_SEPARATOR . '*.json'));
    }
}
