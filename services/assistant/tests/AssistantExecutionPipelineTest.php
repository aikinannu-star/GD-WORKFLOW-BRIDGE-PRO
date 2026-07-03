<?php

require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ToolInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../AssistantRuntime.php';
require_once __DIR__ . '/../RuntimeServiceRegistry.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../execution/AssistantExecutionPipeline.php';
require_once __DIR__ . '/../execution/ExecutionReport.php';
require_once __DIR__ . '/../execution/RuntimeExecutionResult.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class TestExecutionTool implements ToolInterface
{
    public function id(): string { return 'echo'; }
    public function name(): string { return 'Echo'; }
    public function description(): string { return 'Echo tool'; }
    public function inputSchema(): array { return []; }
    public function execute(array $args): array { return ['success' => true, 'result' => $args]; }
}

class TestExecutionProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'ok']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'ok']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array { return ['provider' => 'ollama', 'model' => 'llama3.1:8b', 'modelFamily' => 'llama', 'contextWindow' => 8192, 'supportsToolCalling' => true]; }
}

$toolRegistry = new ToolRegistry();
$toolRegistry->registerTool(new TestExecutionTool());
$provider = new TestExecutionProvider();
$runtime = new AssistantRuntime($provider, new RuntimeEventEmitter(), $toolRegistry);

$serviceRegistry = new RuntimeServiceRegistry($provider, new RuntimeEventEmitter(), $toolRegistry);
$contextServices = $serviceRegistry->contextServices();
if (!$contextServices->getContextAssembler() instanceof ContextAssembler) {
    echo 'Expected context services to expose a context assembler' . PHP_EOL;
    exit(1);
}

$pipeline = new AssistantExecutionPipeline($provider, new RuntimeEventEmitter(), null, $toolRegistry, null, null, null, null, $serviceRegistry);
$pipeline->registerStage(new PlanningStage($serviceRegistry->toolServices()), 'planning');
$serviceRuntime = new AssistantRuntime($serviceRegistry);

$context = new RuntimeExecutionContext(
    new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'),
    null,
    null,
    ['message' => 'Hello'],
    [],
    [],
    [],
    [],
    null
);

$serviceResult = $serviceRuntime->execute($context);
if (!$serviceResult->isSuccessful()) {
    echo 'Expected service-registry execution to succeed' . PHP_EOL;
    exit(1);
}

if ($serviceResult->getFinalResponse() === '') {
    echo 'Expected service-registry execution to produce a final response' . PHP_EOL;
    exit(1);
}

$result = $runtime->execute($context);
if (!$result->isSuccessful()) {
    echo 'Expected execution to succeed' . PHP_EOL;
    exit(1);
}

if ($result->getExecutionReport() === null) {
    echo 'Expected execution report to be attached' . PHP_EOL;
    exit(1);
}

if ($result->getFinalResponse() === '') {
    echo 'Expected final response to be present' . PHP_EOL;
    exit(1);
}


class TestValidationTool implements ToolInterface
{
    public function id(): string { return 'validated_echo'; }
    public function name(): string { return 'Validated Echo'; }
    public function description(): string { return 'Echo tool with required arguments'; }
    public function inputSchema(): array { return ['required' => ['message']]; }
    public function execute(array $args): array { return ['success' => true, 'result' => $args]; }
}

$validationToolRegistry = new ToolRegistry();
$validationToolRegistry->registerTool(new TestValidationTool());
$validationServiceRegistry = new RuntimeServiceRegistry($provider, new RuntimeEventEmitter(), $validationToolRegistry);
$validationRuntime = new AssistantRuntime($validationServiceRegistry);

$invalidContext = new RuntimeExecutionContext(
    new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'),
    null,
    null,
    ['message' => 'Run tool'],
    [],
    [],
    [],
    [],
    null
);
$invalidContext->setToolPlan(['toolId' => 'validated_echo', 'arguments' => []]);

$invalidResult = $validationRuntime->execute($invalidContext);
$toolResults = $invalidContext->getToolResults();
if (!is_array($toolResults) || ($toolResults['success'] ?? true) !== false) {
    echo 'Expected tool validation to fail for missing required arguments' . PHP_EOL;
    exit(1);
}

if (($toolResults['error'] ?? '') !== 'tool_validation_failed') {
    echo 'Expected validation failure error to be tool_validation_failed' . PHP_EOL;
    exit(1);
}

echo 'Assistant execution pipeline test passed' . PHP_EOL;
