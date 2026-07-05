<?php
require_once __DIR__ . '/../ToolInterface.php';

class ServiceTool implements ToolInterface
{
    public function supports(string $taskType): bool
    {
        return $taskType === 'scaffold_service';
    }

    public function execute(array $payload): array
    {
        $target = getenv('DISPATCHER_SERVICE_URL') ?: 'http://assistant-service:8017/api/v1/assistant/generate/service';
        $post = ['instructions' => $payload['instructions'] ?? '', 'context' => $payload['context'] ?? ''];
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($post),
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); return ['status' => 502, 'result' => ['error' => 'upstream_error', 'detail' => $err]]; }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $body = json_decode($resp, true) ?? ['raw' => $resp];
        return ['status' => ($code >=200 && $code<300) ? 200 : $code, 'result' => $body];
    }
}
