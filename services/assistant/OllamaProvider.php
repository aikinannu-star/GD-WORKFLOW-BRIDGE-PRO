<?php

require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/ProviderRequestHeaders.php';

class OllamaProvider implements AssistantProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            // Most Ollama versions support /v1/completions; fall back to
            // /api/generate when available or when using newer images.
            'api_url' => 'http://ollama:11434/v1/completions',
            'model' => 'mistral',
            'max_tokens' => 512,
            'temperature' => 0.2,
            'timeout' => 20,
            'max_retries' => 2,
            'retry_delay_ms' => 200,
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

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            return ['success' => false, 'text' => '', 'raw' => $payload, 'error' => 'invalid_request_payload'];
        }

        $urls = $this->buildCandidateApiUrls($this->config['api_url']);
        $lastResult = ['success' => false, 'text' => '', 'raw' => null, 'error' => 'no_response_from_ollama'];

        foreach ($urls as $apiUrl) {
            $attemptCount = max(1, (int)($this->config['max_retries'] ?? 0) + 1);
            $result = null;

            for ($attempt = 0; $attempt < $attemptCount; $attempt++) {
                $result = $this->requestOllama($apiUrl, $jsonPayload, $options);
                $result['api_url'] = $apiUrl;
                if ($result['success']) {
                    return $result;
                }

                $lastResult = $result;
                $shouldRetry = $attempt + 1 < $attemptCount && $this->shouldRetryRequest($result);
                if (!$shouldRetry) {
                    break;
                }

                $this->sleepBeforeRetry();
            }

            $isNotFound = isset($result['http_code']) && $result['http_code'] === 404;
            $shouldTryFallback = $isNotFound || ($result['error'] ?? null) === 'invalid_json_response';

            if (!$shouldTryFallback) {
                break;
            }
        }

        return $lastResult;
    }

    private function buildCandidateApiUrls(string $apiUrl): array
    {
        $urls = [$apiUrl];

        $alternate = null;
        if (str_ends_with($apiUrl, '/api/generate')) {
            $alternate = substr($apiUrl, 0, -strlen('/api/generate')) . '/v1/completions';
        } elseif (str_ends_with($apiUrl, '/v1/completions')) {
            $alternate = substr($apiUrl, 0, -strlen('/v1/completions')) . '/api/generate';
        }

        if ($alternate !== null && $alternate !== $apiUrl) {
            $urls[] = $alternate;
        }

        return array_values(array_unique($urls));
    }

    public function shouldRetryRequest(array $result): bool
    {
        $error = strtolower((string)($result['error'] ?? ''));
        if ($error === '') {
            return false;
        }

        $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : 0;
        if ($httpCode >= 500) {
            return true;
        }

        $transientMarkers = [
            'temporarily_unavailable',
            'timed out',
            'timeout',
            'connection reset',
            'connection refused',
            'could not resolve host',
            'network is unreachable',
            'service unavailable',
            'rate limit',
            'too many requests',
            'temporarily unavailable',
            'try again later',
            'operation timed out',
            'econnrefused',
            'econnreset',
        ];

        foreach ($transientMarkers as $marker) {
            if (str_contains($error, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function sleepBeforeRetry(): void
    {
        $delayMs = max(0, (int)($this->config['retry_delay_ms'] ?? 0));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function requestOllama(string $apiUrl, string $jsonPayload, array $options): array
    {
        $ch = curl_init($apiUrl);
        $httpHeaders = ProviderRequestHeaders::build($options);
        if (!isset($httpHeaders['Content-Type'])) {
            $httpHeaders['Content-Type'] = 'application/json';
        }
        $formattedHeaders = [];
        foreach ($httpHeaders as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        if (!empty($options['capture_request_headers'])) {
            curl_close($ch);
            return ['success' => true, 'text' => '', 'raw' => null, 'headers' => $httpHeaders, 'error' => null];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => (int)$this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => max(1, min((int)$this->config['timeout'], 5)),
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
            return ['success' => false, 'text' => '', 'raw' => $response, 'http_code' => $httpCode, 'error' => 'invalid_json_response'];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'text' => '', 'raw' => $body, 'http_code' => $httpCode, 'error' => $body['error'] ?? $body['message'] ?? 'llm_error'];
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

        if (isset($body['response'])) {
            if (is_string($body['response'])) {
                return ['success' => true, 'text' => trim($body['response']), 'raw' => $body, 'error' => null];
            }
            if (is_array($body['response'])) {
                $s = $this->findFirstString($body['response']);
                if ($s !== null) {
                    return ['success' => true, 'text' => trim($s), 'raw' => $body, 'error' => null];
                }
            }
        }

        if (isset($body['results']) && is_array($body['results']) && !empty($body['results'])) {
            $resultEntry = $body['results'][0];
            if (is_array($resultEntry)) {
                if (isset($resultEntry['response'])) {
                    if (is_string($resultEntry['response'])) {
                        return ['success' => true, 'text' => trim($resultEntry['response']), 'raw' => $body, 'error' => null];
                    }
                    $s = $this->findFirstString($resultEntry['response']);
                    if ($s !== null) {
                        return ['success' => true, 'text' => trim($s), 'raw' => $body, 'error' => null];
                    }
                }
                if (isset($resultEntry['output'])) {
                    if (is_string($resultEntry['output'])) {
                        return ['success' => true, 'text' => trim($resultEntry['output']), 'raw' => $body, 'error' => null];
                    }
                    $s = $this->findFirstString($resultEntry['output']);
                    if ($s !== null) {
                        return ['success' => true, 'text' => trim($s), 'raw' => $body, 'error' => null];
                    }
                }
            }
        }

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

        if (isset($body['generation']) && is_array($body['generation']) && !empty($body['generation'])) {
            $generation = $body['generation'][0];
            if (is_array($generation)) {
                if (isset($generation['text']) && is_string($generation['text'])) {
                    return ['success' => true, 'text' => trim($generation['text']), 'raw' => $body, 'error' => null];
                }
                $s = $this->findFirstString($generation);
                if ($s !== null) {
                    return ['success' => true, 'text' => trim($s), 'raw' => $body, 'error' => null];
                }
            }
        }

        $found = $this->findFirstString($body);
        if ($found !== null) {
            return ['success' => true, 'text' => trim($found), 'raw' => $body, 'error' => null];
        }

        return ['success' => false, 'text' => '', 'raw' => $body, 'http_code' => $httpCode, 'error' => 'unknown_choice_format'];
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
