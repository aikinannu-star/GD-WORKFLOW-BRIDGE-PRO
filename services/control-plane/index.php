<?php
// Control Plane Service with artifact management and metrics.
// Run with: php -S 127.0.0.1:8080 -t services/control-plane

require_once __DIR__ . '/../lib/ArtifactManager.php';

$compiledPath = getenv('COMPILED_POLICY_ARTIFACT') ?: __DIR__ . '/../../build/compiled-policy.json';
$evaluatorPath = __DIR__ . '/../../services/lib/PolicyCompiler/PolicyEvaluatorV2.php';

$artifactManager = new ArtifactManager($compiledPath);
$artifactManager->load();

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

if ($uri === '/status') {
    $metadata = $artifactManager->getMetadata() ?? [];
    echo json_encode([
        'status' => 'ok',
        'compiled_artifact' => file_exists($compiledPath) ? basename($compiledPath) : null,
        'artifact_version' => $metadata['artifact_version'] ?? null,
        'policy_schema_version' => $metadata['policy_schema_version'] ?? null,
        'evaluator_present' => file_exists($evaluatorPath),
    ]);
    exit(0);
}

if ($uri === '/health') {
    $isHealthy = $artifactManager->isHealthy() && file_exists($evaluatorPath);
    if (!$isHealthy) {
        http_response_code(503);
    }
    echo json_encode([
        'status' => $isHealthy ? 'healthy' : 'degraded',
        'artifact_present' => $artifactManager->isHealthy(),
        'evaluator_present' => file_exists($evaluatorPath),
        'reloads' => $artifactManager->getReloadCount(),
    ]);
    exit($isHealthy ? 0 : 1);
}

if ($uri === '/metrics' && $method === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# HELP gdwb_artifact_reloads_total Total number of artifact reloads detected\n";
    echo "# TYPE gdwb_artifact_reloads_total counter\n";
    echo "gdwb_artifact_reloads_total " . $artifactManager->getReloadCount() . "\n";
    
    echo "\n# HELP gdwb_evaluations_total Total number of policy evaluations performed\n";
    echo "# TYPE gdwb_evaluations_total counter\n";
    echo "gdwb_evaluations_total " . $artifactManager->getEvaluationCount() . "\n";
    
    echo "\n# HELP gdwb_signature_verifications_total Total number of signature verifications attempted\n";
    echo "# TYPE gdwb_signature_verifications_total counter\n";
    echo "gdwb_signature_verifications_total " . $artifactManager->getSignatureVerifyCount() . "\n";
    
    echo "\n# HELP gdwb_signature_failures_total Total number of failed signature verifications\n";
    echo "# TYPE gdwb_signature_failures_total counter\n";
    echo "gdwb_signature_failures_total " . $artifactManager->getSignatureFailCount() . "\n";
    
    if ($artifactManager->getLastReloadTime() > 0) {
        echo "\n# HELP gdwb_last_reload_time Unix timestamp of last artifact reload\n";
        echo "# TYPE gdwb_last_reload_time gauge\n";
        echo "gdwb_last_reload_time " . (int)($artifactManager->getLastReloadTime() * 1000) . "\n";
    }
    
    $metadata = $artifactManager->getMetadata() ?? [];
    if (!empty($metadata['artifact_version'])) {
        echo "\n# HELP gdwb_artifact_version Artifact version\n";
        echo "# TYPE gdwb_artifact_version gauge\n";
        $ver = str_replace('.', '_', $metadata['artifact_version']);
        echo "gdwb_artifact_version{version=\"" . addslashes($metadata['artifact_version']) . "\"} 1\n";
    }
    if (!empty($metadata['policy_schema_version'])) {
        echo "\n# HELP gdwb_policy_schema_version Policy schema version\n";
        echo "# TYPE gdwb_policy_schema_version gauge\n";
        echo "gdwb_policy_schema_version{version=\"" . addslashes($metadata['policy_schema_version']) . "\"} 1\n";
    }
    
    exit(0);
}

if ($uri === '/evaluate' && $method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid json']);
        exit(1);
    }

    $artifact = $artifactManager->getArtifact();
    if ($artifact === null) {
        http_response_code(500);
        echo json_encode(['error' => 'compiled artifact not found']);
        exit(1);
    }

    if (!file_exists($evaluatorPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'PolicyEvaluatorV2 not found']);
        exit(1);
    }

    require_once $evaluatorPath;

    if (!class_exists('PolicyEvaluatorV2')) {
        http_response_code(500);
        echo json_encode(['error' => 'PolicyEvaluatorV2 class unavailable']);
        exit(1);
    }

    $filePath = $payload['filePath'] ?? ($payload['path'] ?? 'input');
    $content = $payload['content'] ?? null;
    $context = [];
    if ($content !== null) {
        $context['content'] = $content;
    }

    try {
        $evaluator = PolicyEvaluatorV2::fromCompiledFile($compiledPath);
        $violations = $evaluator->evaluateFile($filePath, $context);
        $artifactManager->recordEvaluation();
        
        $violationData = array_map(function ($violation) {
            return method_exists($violation, 'toArray') ? $violation->toArray() : (array)$violation;
        }, $violations);

        echo json_encode([
            'result' => 'evaluated',
            'violations' => $violationData,
            'count' => count($violationData),
            'artifact' => basename($compiledPath),
        ]);
        exit(0);
    } catch (Throwable $ex) {
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()]);
        exit(1);
    }
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
exit(1);
