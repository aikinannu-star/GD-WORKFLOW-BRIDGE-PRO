<?php
class ExecutionQueue
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/queue';
        foreach ([$this->basePath . DIRECTORY_SEPARATOR . 'pending', $this->basePath . DIRECTORY_SEPARATOR . 'processing'] as $dir) {
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        }
    }

    public function enqueue(array $item): string
    {
        $id = $item['id'] ?? bin2hex(random_bytes(8));
        $item['id'] = $id;
        $item['queuedAt'] = $item['queuedAt'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . $id . '.json';
        file_put_contents($file, json_encode($item, JSON_PRETTY_PRINT));
        return $id;
    }

    public function dequeue(): ?array
    {
        $dir = $this->basePath . DIRECTORY_SEPARATOR . 'pending';
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        sort($files);
        if (empty($files)) {
            return null;
        }

        $file = array_shift($files);
        $item = json_decode(file_get_contents($file), true);
        if (!is_array($item)) {
            return null;
        }

        $processingFile = $this->basePath . DIRECTORY_SEPARATOR . 'processing' . DIRECTORY_SEPARATOR . basename($file);
        rename($file, $processingFile);
        return $item;
    }

    public function ack(string $id): void
    {
        $processingFile = $this->basePath . DIRECTORY_SEPARATOR . 'processing' . DIRECTORY_SEPARATOR . $id . '.json';
        if (is_file($processingFile)) {
            unlink($processingFile);
        }
    }

    public function size(): int
    {
        return count(glob($this->basePath . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . '*.json'));
    }
}
