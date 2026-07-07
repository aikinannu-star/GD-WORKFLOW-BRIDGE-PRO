<?php

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/ProviderRequestHeaders.php';

class OllamaProvider implements AssistantProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            // New Ollama images expose a simple /api/generate endpoint; keep
            // v1/completions as a fallback for older test configurations.
            'api_url' => 'http://ollama:11434/api/generate',
            'model' => 'mistral',
            'max_tokens' => 512,
            'temperature' => 0.2,
            'timeout' => 20,
        ], $config);
    }

    public function generate(string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->config['model'],
            'prompt' => $options['prompt'] ?? $prompt,
            'stream' => false,
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'],
            'temperature' => $options['temperature'] ?? $this->config['temperature'],
        ];

        if (!empty($options['messages'])) {
            $payload['messages'] = $options['messages'];
            unset($payload['prompt']);
        }

        if (!empty($options['post_data']) && is_array($options['post_data'])) {
            $payload = $options['post_data'];
        }

        $ch = curl_init($this->config['api_url']);
        $httpHeaders = ProviderRequestHeaders::build($options);
        if (!isset($httpHeaders['Content-Type'])) {
            $httpHeaders['Content-Type'] = 'application/json';
        }
        $formattedHeaders = [];
        foreach ($httpHeaders as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        if (!empty($options['capture_request_headers'])) {
            return ['success' => true, 'text' => '', 'raw' => null, 'headers' => $httpHeaders, 'error' => null];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => (int)$this->config['timeout'],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'text' => '', 'raw' => null, 'error' => $error];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($response, true);
        if (!is_array($body)) {
            return ['success' => false, 'text' => '', 'raw' => $response, 'error' => 'invalid_json_response'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'text' => '', 'raw' => $body, 'error' => $body['error'] ?? 'llm_error'];
        }

        // Many providers return a `choices` array with different shapes.
        // Attempt to extract text from common keys, then fall back to a
        // tolerant recursive search for the first string value in the body.
        $choice = $body['choices'][0] ?? null;
        if (is_array($choice)) {
            if (isset($choice['message']['content'])) {
                return ['success' => true, 'text' => trim($choice['message']['content']), 'raw' => $body, 'error' => null];
            }
            if (isset($choice['content'])) {
                return ['success' => true, 'text' => trim($choice['content']), 'raw' => $body, 'error' => null];
            }
            if (isset($choice['text'])) {
                return ['success' => true, 'text' => trim($choice['text']), 'raw' => $body, 'error' => null];
            }
        }

        // Ollama /api/generate returns the response in a `response` field
        if (isset($body['response']) && is_string($body['response'])) {
            return ['success' => true, 'text' => trim($body['response']), 'raw' => $body, 'error' => null];
        }

        // Ollama and other runtimes sometimes return `output` or plainer shapes.
        if (isset($body['output'])) {
            if (is_string($body['output'])) {
                return ['success' => true, 'text' => trim($body['output']), 'raw' => $body, 'error' => null];
            }
            if (is_array($body['output']) && !empty($body['output'])) {
                $first = $body['output'][0];
                if (is_string($first)) {
                    return ['success' => true, 'text' => trim($first), 'raw' => $body, 'error' => null];
                }
                if (is_array($first)) {
                    $s = $this->findFirstString($first);
                    if ($s !== null) {
                        return ['success' => true, 'text' => trim($s), 'raw' => $body, 'error' => null];
                    }
                }
            }
        }

        // Generic recursive search for the first non-empty string in the response.
        $found = $this->findFirstString($body);
        if ($found !== null) {
            return ['success' => true, 'text' => trim($found), 'raw' => $body, 'error' => null];
        }

        return ['success' => false, 'text' => '', 'raw' => $body, 'error' => 'unknown_choice_format'];
    }

    private function findFirstString(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                $s = $this->findFirstString($v);
                if ($s !== null) {
                    return $s;
                }
            }
        }
        return null;
    }

    public function chat(string $prompt, array $options = []): array
    {
        $options['prompt'] = $options['prompt'] ?? $prompt;
        return $this->generate($options['prompt'], $options);
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield $this->chat($prompt, $options);
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => array_map('strlen', str_split(substr($input, 0, 64)))];
    }

    public function health(): array
    {
        $url = $this->config['api_url'] ?? '';
        if (!is_string($url) || $url === '') {
            return ['status' => 'unavailable', 'provider' => 'ollama', 'url' => $url, 'error' => 'missing_api_url'];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return ['status' => 'unavailable', 'provider' => 'ollama', 'url' => $url, 'error' => 'invalid_api_url'];
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host = $parts['host'];
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $timeout = (int)($this->config['timeout'] ?? 20);
        $transport = $scheme === 'https' ? 'ssl://' : '';

        $socket = @fsockopen($transport . $host, $port, $errno, $errstr, max(1, min($timeout, 5)));
        if ($socket === false) {
            return ['status' => 'unavailable', 'provider' => 'ollama', 'url' => $url, 'error' => $errstr ?: 'connection_failed'];
        }

        fclose($socket);
        return ['status' => 'ok', 'provider' => 'ollama', 'url' => $url];
    }

    public function capabilities(): array
    {
        return [
            'chat' => true,
            'stream' => true,
            'embeddings' => true,
            'health' => true,
        ];
    }
}
