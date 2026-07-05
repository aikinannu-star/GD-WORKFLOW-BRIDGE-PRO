<?php

require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../RuntimeServiceRegistry.php';
require_once __DIR__ . '/../execution/AssistantExecutionPipeline.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../ToolInterface.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class ToolSupportTestTool implements ToolInterface
{
    public function id(): string { return 'workflow_execute'; }
    public function name(): string { return 'Workflow Execute'; }
    public function description(): string { return 'Execute a workflow'; }
    public function inputSchema(): array { return ['required' => ['workflowId', 'input']]; }
    public function execute(array $args): array { return ['success' => true, 'result' => ['workflowId' => $args['workflowId'] ?? 'unknown', 'status' => 'completed']]; }
}

$toolRegistry = new ToolRegistry();
$toolRegistry->registerTool(new ToolSupportTestTool());
$serviceRegistry = new RuntimeServiceRegistry(null, new RuntimeEventEmitter(), $toolRegistry);
$toolExecutionStage = new ToolExecutionStage($serviceRegistry->toolServices());

$context = new RuntimeExecutionContext(
    new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'),
    null,
    null,
    ['message' => 'Run workflow'],
    [],
    [],
    [],
    [],
    null
);
$context->setToolPlan(['toolId' => 'workflow_execute', 'arguments' => ['workflowId' => 'default', 'input' => ['query' => 'test']]]);
$context->setProviderInfo(new ProviderInfo(['provider' => 'local', 'model' => 'local', 'supportsToolCalling' => false]));

if ($toolExecutionStage->supports($context) !== false) {
    echo 'Expected tool execution stage to reject tool plans when provider lacks tool support' . PHP_EOL;
    exit(1);
}

$toolResults = $context->getToolResults();
if (!is_array($toolResults) || ($toolResults['success'] ?? true) !== false || ($toolResults['error'] ?? '') !== 'provider_lacks_tool_support') {
    echo 'Expected provider_lacks_tool_support tool result from tool execution stage' . PHP_EOL;
    exit(1);
}

echo 'Tool support capability test passed' . PHP_EOL;
