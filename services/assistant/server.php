<?php
/**
 * AI Assistant Service (MVP)
 * Provides lightweight assistant session and prompt handling.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../lib/GracefulShutdownManager.php';
require_once __DIR__ . '/ProviderInterface.php';
require_once __DIR__ . '/OllamaProvider.php';

define('ASSISTANT_SERVICE_NAME', 'assistant');
define('ASSISTANT_SERVICE_PORT', 8017);

if (!defined('ASSISTANT_TEST_MODE')) {
    define('ASSISTANT_TEST_MODE', false);
}

global $method, $uri, $shutdownManager;
$shutdownManager = new GracefulShutdownManager(ASSISTANT_SERVICE_NAME, getAssistantShutdownTimeoutSeconds());

function loadAssistantSessions(): array {
    return ServiceHelpers::loadJson('assistant', 'sessions.json');
}

function saveAssistantSessions(array $sessions): bool {
    return ServiceHelpers::saveJson('assistant', 'sessions.json', $sessions);
}

function getAssistantProviderConfig(): array {
    return [
        'api_url' => getenv('ASSISTANT_LLM_API_URL') ?: 'http://ollama:11434/api/generate',
        'model' => getenv('ASSISTANT_LLM_MODEL') ?: 'gemma:2b',
        'max_tokens' => (int)(getenv('ASSISTANT_LLM_MAX_TOKENS') ?: 512),
        'temperature' => (float)(getenv('ASSISTANT_LLM_TEMPERATURE') ?: 0.2),
        'timeout' => (int)(getenv('ASSISTANT_LLM_TIMEOUT_SECONDS') ?: 20),
    ];
}

function getAssistantShutdownTimeoutSeconds(): int {
    return max(1, (int)(getenv('ASSISTANT_SHUTDOWN_TIMEOUT') ?: 30));
}

function getAssistantProvider(): AssistantProviderInterface {
    $provider = strtolower(getenv('ASSISTANT_PROVIDER') ?: 'ollama');
    switch ($provider) {
        case 'ollama':
        default:
            return new OllamaProvider(getAssistantProviderConfig());
    }
}

function getAssistantStartupState(): array {
    static $startupState = null;
    if ($startupState !== null) {
        return $startupState;
    }

    $errors = [];
    $provider = strtolower(getenv('ASSISTANT_PROVIDER') ?: 'ollama');
    $allowedProviders = ['ollama', 'local'];

    if (!in_array($provider, $allowedProviders, true)) {
        $errors[] = sprintf('Unsupported ASSISTANT_PROVIDER value: %s', $provider);
    }

    $apiUrl = getenv('ASSISTANT_LLM_API_URL') ?: 'http://ollama:11434/api/generate';
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'ASSISTANT_LLM_API_URL is not a valid URL';
    }

    if ($provider === 'ollama' && getenv('ASSISTANT_LLM_HEALTH_CHECK')) {
        if (!checkUrlReachable($apiUrl, 1)) {
            $errors[] = sprintf('Assistant provider host is unreachable at %s', $apiUrl);
        }
    }

    $dataPath = dirname(ServiceHelpers::dataPath('assistant', 'sessions.json'));
    if (!is_dir($dataPath) && !mkdir($dataPath, 0777, true) && !is_dir($dataPath)) {
        $errors[] = sprintf('Unable to create assistant data directory: %s', $dataPath);
    }

    if (!is_writable($dataPath)) {
        $errors[] = sprintf('Assistant data directory is not writable: %s', $dataPath);
    }

    $startupState = [
        'ready' => empty($errors),
        'service' => ASSISTANT_SERVICE_NAME,
        'provider' => $provider,
        'time' => gmdate('c'),
        'errors' => $errors,
    ];

    return $startupState;
}

function checkUrlReachable(string $url, int $timeoutSeconds = 1): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host = $parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $transport = $scheme === 'https' ? 'ssl://' : '';

    $socket = @fsockopen($transport . $host, $port, $errno, $errstr, $timeoutSeconds);
    if ($socket !== false) {
        fclose($socket);
        return true;
    }

    return false;
}

function setupAssistantGracefulShutdown(): void {
    global $shutdownManager;
    if (!isset($shutdownManager)) {
        $shutdownManager = new GracefulShutdownManager(ASSISTANT_SERVICE_NAME);
    }

    $shutdownManager->onShutdown(function (): void {
        ServiceHelpers::emitStructuredLog(ASSISTANT_SERVICE_NAME, 'info', 'shutdown_signal_received', ['service' => ASSISTANT_SERVICE_NAME]);
    });

    $shutdownManager->registerSignalHandlers();
}

function canAcceptAssistantRequests(): bool
{
    global $shutdownManager;
    return isset($shutdownManager) ? $shutdownManager->canAcceptRequests() : true;
}

function handleAssistantRequest(callable $handler): void
{
    global $shutdownManager;
    if (!isset($shutdownManager)) {
        $shutdownManager = new GracefulShutdownManager(ASSISTANT_SERVICE_NAME, getAssistantShutdownTimeoutSeconds());
    }

    if (!$shutdownManager->canAcceptRequests()) {
        ServiceHelpers::incrementMetric(ASSISTANT_SERVICE_NAME, 'assistant_requests_rejected_during_shutdown_total');
        ServiceHelpers::sendJson(503, ['error' => 'service_draining', 'message' => 'Assistant service is draining and not accepting new requests.']);
    }

    $shutdownManager->beginRequest();
    try {
        $handler();
    } finally {
        $shutdownManager->endRequest();
    }
}

function loadPromptTemplate(string $name): ?string {
    $path = __DIR__ . '/prompts/' . $name . '.md';
    if (!file_exists($path)) {
        return null;
    }
    return file_get_contents($path);
}

function renderPromptTemplate(string $template, array $context): string {
    return preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/', function ($matches) use ($context) {
        $key = $matches[1];
        return isset($context[$key]) ? (string)$context[$key] : '';
    }, $template);
}

function buildAssistantPrompt(string $templateName, array $context): string {
    $template = loadPromptTemplate($templateName);
    if ($template === null) {
        return trim($context['instructions'] ?? '');
    }
    return renderPromptTemplate($template, $context);
}

function createStructuredResponse(string $type, array $payload, array $warnings = [], float $confidence = 0.9): array {
    return [
        'type' => $type,
        'payload' => $payload,
        'warnings' => array_values($warnings),
        'confidence' => $confidence,
        'timestamp' => gmdate('c'),
    ];
}

function validateSchema(array $payload, array $schema): array {
    $errors = [];
    if (!empty($schema['required']) && is_array($schema['required'])) {
        foreach ($schema['required'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $payload)) {
                $errors[] = 'Missing required key: ' . $requiredKey;
            }
        }
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}

function sendAssistantResponse(string $type, array $payload, array $warnings = [], float $confidence = 0.9): void {
    ServiceHelpers::sendJson(200, createStructuredResponse($type, $payload, $warnings, $confidence));
}

function generateAssistantText(string $prompt): ?string {
    if (empty($prompt)) {
        return null;
    }

    $provider = getAssistantProvider();
    $result = $provider->generate($prompt);
    ServiceHelpers::emitStructuredLog(ASSISTANT_SERVICE_NAME, 'info', 'assistant_provider_result', [
        'success' => (bool)($result['success'] ?? false),
        'error' => $result['error'] ?? null,
        'provider' => get_class($provider),
        'prompt_length' => strlen($prompt),
        'response_preview' => is_string($result['text'] ?? null) ? substr(trim($result['text']), 0, 200) : null,
        'raw' => is_array($result['raw'] ?? null) ? array_slice($result['raw'], 0, 5, true) : $result['raw'] ?? null,
    ]);

    if (!$result['success']) {
        ServiceHelpers::emitStructuredLog(ASSISTANT_SERVICE_NAME, 'warning', 'LLM provider failure', ['error' => $result['error'] ?? 'unknown']);
        return null;
    }

    return trim((string)($result['text'] ?? ''));
}

function parseStructuredAssistantOutput(string $text): array {
    $payload = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
        return ['success' => true, 'payload' => $payload, 'error' => null];
    }
    return ['success' => false, 'payload' => ['raw_output' => $text], 'error' => 'invalid_json'];
}

if (!defined('ASSISTANT_SERVER_LOADED')) {
    define('ASSISTANT_SERVER_LOADED', true);

    function runAssistantServer(): array
    {
        global $shutdownManager;

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $shutdownManager = $shutdownManager ?? new GracefulShutdownManager(ASSISTANT_SERVICE_NAME, getAssistantShutdownTimeoutSeconds());

        setupAssistantGracefulShutdown();

        if (isset($_ENV['ENABLE_INTERNAL_SHUTDOWN_ENDPOINT']) && $_ENV['ENABLE_INTERNAL_SHUTDOWN_ENDPOINT'] === '1' && $method === 'POST' && $uri === '/internal/shutdown') {
            $shutdownManager->requestShutdown('internal_api');
            ServiceHelpers::sendJson(202, ['status' => 'shutdown_requested']);
        }

        if (isset($_ENV['ASSISTANT_ALLOW_TEST_ENDPOINTS']) && $_ENV['ASSISTANT_ALLOW_TEST_ENDPOINTS'] === '1' && $method === 'POST' && $uri === '/api/v1/assistant/simulate/hang') {
            handleAssistantRequest(function () {
                sleep(3);
                ServiceHelpers::sendJson(200, ['status' => 'hung_request_completed']);
            });
        }

        if ($method === 'GET' && in_array($uri, ['/health/live', '/health/live/', '/livez', '/livez/'], true)) {
            ServiceHelpers::incrementMetric('assistant', 'assistant_health_live_checks_total', ['status' => 'live']);
            ServiceHelpers::sendJson(200, ['status' => 'live', 'service' => ASSISTANT_SERVICE_NAME, 'time' => gmdate('c'), 'version' => '0.1.0']);
        }

if ($method === 'GET' && in_array($uri, ['/health/ready', '/health/ready/', '/readyz', '/readyz/'], true)) {
    $state = getAssistantStartupState();
    ServiceHelpers::incrementMetric('assistant', 'assistant_health_ready_checks_total', ['status' => $state['ready'] ? 'ready' : 'not_ready']);
    ServiceHelpers::sendJson($state['ready'] ? 200 : 503, $state);
}

if ($method === 'GET' && in_array($uri, ['/health', '/health/'], true)) {
    $state = getAssistantStartupState();
    $code = $state['ready'] ? 200 : 503;
    ServiceHelpers::incrementMetric('assistant', 'assistant_health_checks_total', ['status' => $state['ready'] ? 'ready' : 'not_ready']);
    ServiceHelpers::sendJson($code, $state);
}

if ($method === 'GET' && in_array($uri, ['/metrics', '/metrics/'], true)) {
    header('Content-Type: text/plain; version=0.0.4');
    echo ServiceHelpers::renderPrometheusMetrics(ASSISTANT_SERVICE_NAME);
    exit;
}

if ($method === 'POST' && $uri === '/api/v1/assistant/sessions') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        $sessions = loadAssistantSessions();
        $session = [
            'id' => ServiceHelpers::generateUuid(),
            'user_id' => trim($input['user_id'] ?? 'anonymous'),
            'created_at' => gmdate('c'),
            'messages' => [],
        ];
        $sessions[] = $session;
        saveAssistantSessions($sessions);
        ServiceHelpers::sendJson(201, ['session' => $session]);
    });
}

function handleAssistantTask(string $template, array $context, string $type): void {
    $prompt = buildAssistantPrompt($template, $context);
    $assistantText = generateAssistantText($prompt);
    if (empty($assistantText)) {
        ServiceHelpers::sendJson(502, ['error' => 'llm_unavailable', 'message' => 'Assistant provider is unavailable or returned an error.']);
    }

    $parsed = parseStructuredAssistantOutput($assistantText);
    if ($parsed['success']) {
        $responsePayload = $parsed['payload'];
    } else {
        $responsePayload = ['output' => $assistantText];
    }

    if (!empty($context['schema']) && is_array($context['schema']) && $parsed['success']) {
        $validation = validateSchema($responsePayload, $context['schema']);
        if (!$validation['valid']) {
            ServiceHelpers::sendJson(422, ['error' => 'schema_validation_failed', 'details' => $validation['errors']]);
        }
    }

    sendAssistantResponse($type, $responsePayload);
}

if ($method === 'POST' && $uri === '/api/v1/assistant/generate/workflow') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        handleAssistantTask('workflow', [
            'instructions' => trim($input['instructions'] ?? ''),
            'context' => trim($input['context'] ?? ''),
        ], 'workflow');
    });
}

if ($method === 'POST' && $uri === '/api/v1/assistant/generate/service') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        handleAssistantTask('service', [
            'instructions' => trim($input['instructions'] ?? ''),
            'context' => trim($input['context'] ?? ''),
        ], 'service');
    });
}

if ($method === 'POST' && $uri === '/api/v1/assistant/review/code') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        handleAssistantTask('review', [
            'instructions' => trim($input['instructions'] ?? ''),
            'code' => trim($input['code'] ?? ''),
        ], 'review');
    });
}

if ($method === 'POST' && $uri === '/api/v1/assistant/explain/service') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        handleAssistantTask('explain', [
            'instructions' => trim($input['instructions'] ?? ''),
            'subject' => trim($input['subject'] ?? ''),
        ], 'explain');
    });
}

if ($method === 'POST' && $uri === '/api/v1/assistant/refactor') {
    handleAssistantRequest(function () {
        $input = ServiceHelpers::getRequestBody();
        handleAssistantTask('refactor', [
            'instructions' => trim($input['instructions'] ?? ''),
            'code' => trim($input['code'] ?? ''),
            'goal' => trim($input['goal'] ?? ''),
        ], 'refactor');
    });
}

if ($method === 'POST' && preg_match('#^/api/v1/assistant/sessions/([^/]+)/message$#', $uri, $matches)) {
    handleAssistantRequest(function () use ($matches) {
        $sessionId = $matches[1];
        $input = ServiceHelpers::getRequestBody();
        $sessions = loadAssistantSessions();
        foreach ($sessions as &$session) {
            if (($session['id'] ?? '') === $sessionId) {
                $message = [
                    'role' => 'user',
                    'text' => trim($input['text'] ?? ''),
                    'created_at' => gmdate('c'),
                ];
                $assistantText = generateAssistantText($message['text']);
                if (empty($assistantText)) {
                    $assistantText = 'This is a placeholder AI response for: ' . ($message['text'] ?? '');
                }
                $assistantReply = [
                    'role' => 'assistant',
                    'text' => $assistantText,
                    'created_at' => gmdate('c'),
                ];
                $session['messages'][] = $message;
                $session['messages'][] = $assistantReply;
                saveAssistantSessions($sessions);
                ServiceHelpers::sendJson(200, ['session' => $session, 'reply' => $assistantReply]);
            }
        }
        ServiceHelpers::sendJson(404, ['error' => 'session_not_found']);
    });
}

ServiceHelpers::sendJson(404, ['error' => 'not_found']);
    }
}

if (!ASSISTANT_TEST_MODE) {
    runAssistantServer();
}
