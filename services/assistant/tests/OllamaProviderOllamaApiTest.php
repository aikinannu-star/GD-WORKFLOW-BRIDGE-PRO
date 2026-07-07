<?php
require_once __DIR__ . '/../OllamaProvider.php';

$provider = new OllamaProvider([
    'api_url' => 'http://ollama:11434/api/generate',
    'model' => 'mistral:latest',
    'timeout' => 60,
]);

$result = $provider->generate('Reply with exactly one word: hello');
if (($result['success'] ?? false) !== true || trim((string)($result['text'] ?? '')) === '') {
    fwrite(STDERR, "Ollama provider did not return a usable response\n");
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

fwrite(STDOUT, "Ollama provider returned text: " . trim((string)$result['text']) . "\n");
