<?php

require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/ProviderRequestHeaders.php';

class LocalModelProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        $headers = [];
        if (!empty($options['capture_request_headers'])) {
            $headers = ProviderRequestHeaders::build($options);
        }

        return [
            'success' => true,
            'text' => $prompt,
            'raw' => null,
            'headers' => $headers,
            'error' => null,
        ];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield $this->chat($prompt, $options);
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['status' => 'ok'];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'stream' => true,
            'embeddings' => true,
            'health' => true,
            'supportsTools' => true,
        ];
    }
}
