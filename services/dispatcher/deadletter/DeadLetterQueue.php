<?php
class DeadLetterQueue
{
    private $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/dead-letter';
        if (!is_dir($this->basePath)) { mkdir($this->basePath, 0775, true); }
    }

    public function enqueue(array $record): string
    {
        $id = $record['id'] ?? bin2hex(random_bytes(8));
        $record['id'] = $id;
        $record['timestamp'] = $record['timestamp'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $file = $this->basePath . DIRECTORY_SEPARATOR . $id . '.json';
        file_put_contents($file, json_encode($record, JSON_PRETTY_PRINT));
        return $id;
    }

    public function list(): array
    {
        $files = glob($this->basePath . DIRECTORY_SEPARATOR . '*.json');
        $records = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $records[] = $data;
            }
        }
        usort($records, function ($a, $b) {
            return ($a['timestamp'] ?? '') <=> ($b['timestamp'] ?? '');
        });
        return $records;
    }
}
