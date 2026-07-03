<?php

require_once __DIR__ . '/ModelProviderInterface.php';
require_once __DIR__ . '/ProviderRequestHeaders.php';

class LocalModelProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        $requestHeaders = ProviderRequestHeaders::build($options);
        if (!empty($options['capture_request_headers'])) {
            return ['success' => true, 'text' => '', 'raw' => null, 'headers' => $requestHeaders, 'error' => null];
        }

        return ['success' => true, 'text' => 'Local assistant response for: ' . trim($prompt), 'raw' => ['prompt' => $prompt], 'error' => null];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['text' => 'Local assistant streaming is not implemented.'];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => array_map('strlen', str_split(substr($input, 0, 64)))];
    }

    public function health(): array
    {
        return ['status' => 'ok', 'provider' => 'local'];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'embeddings' => true,
            'health' => true,
            // Indicate that the local provider supports tool calling so
            // dispatcher-backed tools (workflows, actions) are available
            // in development and test environments.
            'supportsToolCalling' => true,
        ];
    }
}
