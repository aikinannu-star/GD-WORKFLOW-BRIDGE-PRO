<?php
require_once __DIR__ . '/ActionInterface.php';

class HttpAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $url = $payload['url'] ?? null;
        if (!$url) {
            return ActionResult::failure('missing_url', ['error' => 'missing_url']);
        }

        $method = strtoupper($payload['method'] ?? 'GET');
        $body = $payload['body'] ?? null;
        $headers = $payload['headers'] ?? [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $payload['timeout'] ?? 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ActionResult::success(['statusCode' => $statusCode, 'body' => $response], null, []);
    }

    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }
}
