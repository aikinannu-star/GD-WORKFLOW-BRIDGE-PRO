<?php
require_once __DIR__ . '/../repositories/FileWorkflowRepository.php';
require_once __DIR__ . '/../validators/WorkflowValidator.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ExecutionStateService.php';
require_once __DIR__ . '/WorkflowEventService.php';
require_once __DIR__ . '/../actions/ActionRegistry.php';
require_once __DIR__ . '/../actions/LogAction.php';
require_once __DIR__ . '/../actions/DelayAction.php';
require_once __DIR__ . '/../actions/SetVariableAction.php';
require_once __DIR__ . '/../actions/HttpAction.php';
require_once __DIR__ . '/../actions/ConditionAction.php';
require_once __DIR__ . '/../runtime/ExecutionContext.php';
require_once __DIR__ . '/../retry/RetryEngine.php';
require_once __DIR__ . '/../retry/RetryPolicy.php';
require_once __DIR__ . '/../deadletter/DeadLetterQueue.php';
require_once __DIR__ . '/../middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../events/RuntimeEventEmitter.php';

class WorkflowExecutionService
{
    private $validator;
    private $audit;
    private $stateService;
    private $eventService;
    private $actionRegistry;
    private $retryEngine;
    private $deadLetterQueue;
    private $middlewarePipeline;
    private $eventEmitter;

    public function __construct($validator = null, $audit = null, $stateService = null, $eventService = null, $actionRegistry = null, $retryEngine = null, $deadLetterQueue = null, $middlewarePipeline = null, $eventEmitter = null)
    {
        $this->validator = $validator ?: new WorkflowValidator();
        $this->audit = $audit ?: new AuditService();
        $this->stateService = $stateService ?: new ExecutionStateService();
        $this->eventService = $eventService ?: new WorkflowEventService();
        $this->actionRegistry = $actionRegistry ?: $this->buildActionRegistry();
        $this->retryEngine = $retryEngine ?: new RetryEngine(new RetryPolicy(3, 0.0, true));
        $this->deadLetterQueue = $deadLetterQueue ?: new DeadLetterQueue();
        $this->middlewarePipeline = $middlewarePipeline ?: new MiddlewarePipeline();
        $this->eventEmitter = $eventEmitter ?: new RuntimeEventEmitter();
    }

    public function execute(array $workflowRecord, array $input = []): array
    {
        $workflowDef = $workflowRecord['workflow'] ?? $workflowRecord;
        $validation = $this->validator->validate(is_array($workflowDef) ? $workflowDef : []);
        if (!$validation['valid']) {
            throw new Exception(json_encode(['validation_errors' => $validation['errors']]));
        }

        $workflowId = $workflowRecord['id'] ?? ($workflowDef['workflow_id'] ?? $workflowDef['workflowId'] ?? null);
        $tenantId = $workflowRecord['tenantId'] ?? ($workflowDef['tenant_id'] ?? 'default');
        $workflowName = $workflowRecord['name'] ?? ($workflowDef['name'] ?? 'workflow');

        $contextVariables = array_merge($input, [
            'workflowId' => $workflowId,
            'tenantId' => $tenantId,
            'workflowName' => $workflowName,
        ]);

        $executionRecord = [
            'workflowId' => $workflowId,
            'tenantId' => $tenantId,
            'status' => 'running',
            'currentNode' => null,
            'variables' => $contextVariables,
            'retryCount' => 0,
            'triggerSource' => $input['triggerSource'] ?? 'manual',
            'startedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
        ];
        $savedExecution = $this->stateService->start($executionRecord);
        $this->eventService->publish('workflow.started', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId]);
        $this->eventEmitter->emit('workflow.started', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId]);

        $context = new ExecutionContext((string) $workflowId, (string) $savedExecution['executionId'], (string) $tenantId, $contextVariables, null, null, ['workflowName' => $workflowName]);

        $steps = $workflowDef['steps'] ?? [];
        $stepMap = [];
        foreach ($steps as $step) {
            if (!empty($step['id'])) { $stepMap[$step['id']] = $step; }
        }

        $triggerId = null;
        foreach ($stepMap as $id => $step) {
            if (($step['type'] ?? '') === 'trigger') { $triggerId = $id; break; }
        }

        $trace = [];
        $currentId = $triggerId;
        $status = 'completed';
        $executionContext = [
            'workflowId' => $workflowId,
            'executionId' => $savedExecution['executionId'],
            'tenantId' => $tenantId,
            'workflow' => $workflowDef,
            'steps' => $steps,
            'context' => $context,
        ];
        while ($currentId !== null && isset($stepMap[$currentId])) {
            $step = $stepMap[$currentId];
            $this->stateService->update($savedExecution['executionId'], ['currentNode' => $step['id'] ?? $currentId, 'variables' => $context->getVariables()]);
            $this->eventService->publish('workflow.node.started', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId]);
            $this->eventEmitter->emit('node.started', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId]);
            $result = $this->executeStep($step, $context, $workflowDef, $savedExecution['executionId']);
            $trace[] = [
                'id' => $step['id'] ?? $currentId,
                'type' => $step['type'] ?? 'unknown',
                'status' => $result['status'] ?? 'completed',
                'output' => $result['output'] ?? null,
                'logs' => $result['logs'] ?? [],
            ];
            $this->stateService->update($savedExecution['executionId'], ['variables' => $context->getVariables()]);
            $this->eventService->publish('workflow.node.completed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId, 'status' => $result['status'] ?? 'completed']);
            $this->eventEmitter->emit('node.completed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId, 'status' => $result['status'] ?? 'completed']);
            if (($result['status'] ?? 'completed') === 'pending') {
                $status = 'pending';
                break;
            }
            if (($result['status'] ?? 'completed') === 'failed') {
                $status = 'failed';
                $this->stateService->update($savedExecution['executionId'], [
                    'status' => 'failed',
                    'error' => $result['error'] ?? 'workflow_step_failed',
                    'finishedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
                ]);
                $this->eventService->publish('workflow.node.failed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId, 'error' => $result['error'] ?? 'workflow_step_failed']);
                $this->eventService->publish('workflow.failed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId, 'error' => $result['error'] ?? 'workflow_step_failed']);
                $this->eventEmitter->emit('node.failed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'nodeId' => $step['id'] ?? $currentId, 'error' => $result['error'] ?? 'workflow_step_failed']);
                $this->eventEmitter->emit('workflow.failed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId, 'error' => $result['error'] ?? 'workflow_step_failed']);
                $this->deadLetterQueue->enqueue([
                    'workflowId' => $workflowId,
                    'executionId' => $savedExecution['executionId'],
                    'action' => $step['id'] ?? $currentId,
                    'error' => $result['error'] ?? 'workflow_step_failed',
                    'payload' => $step,
                ]);
                $this->eventEmitter->emit('deadletter.created', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'error' => $result['error'] ?? 'workflow_step_failed']);
                break;
            }
            if (($step['type'] ?? '') === 'end') {
                break;
            }
            $next = $step['next'] ?? [];
            if (!is_array($next) || empty($next)) {
                break;
            }
            $currentId = $result['nextNode'] ?? $next[0];
        }

        $this->stateService->update($savedExecution['executionId'], ['status' => $status, 'variables' => $context->getVariables(), 'completedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM)]);
        $this->eventService->publish('workflow.completed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId, 'status' => $status]);
        try {
            $this->audit->record($workflowId, $tenantId, $workflowRecord['version'] ?? 1, 'execute', 'system', $status, ['trace' => $trace]);
        } catch (Exception $e) {
            // ignore audit failures
        }

        $this->eventEmitter->emit('workflow.completed', ['executionId' => $savedExecution['executionId'], 'workflowId' => $workflowId, 'tenantId' => $tenantId, 'status' => $status]);

        return [
            'status' => $status,
            'workflowId' => $workflowId,
            'tenantId' => $tenantId,
            'executionId' => $savedExecution['executionId'],
            'steps' => $trace,
            'context' => $context->getVariables(),
        ];
    }

    public function executeById(string $id, array $input = []): array
    {
        $repo = new FileWorkflowRepository();
        $record = $repo->get($id);
        if ($record === null) { throw new Exception('not_found'); }
        return $this->execute($record, $input);
    }

    private function buildActionRegistry(): ActionRegistry
    {
        $registry = new ActionRegistry();
        $registry->register('log', new LogAction());
        $registry->register('delay', new DelayAction());
        $registry->register('set_variable', new SetVariableAction());
        $registry->register('http_request', new HttpAction());
        $registry->register('condition', new ConditionAction());
        return $registry;
    }

    private function executeStep(array $step, ExecutionContext $context, array $workflowDef, string $executionId): array
    {
        $type = $step['type'] ?? 'unknown';
        $settings = $step['settings'] ?? [];
        $handler = function (array $ctx) use ($step, $context, $workflowDef, $executionId): array {
            return $this->executeStepInternal($step, $context, $workflowDef, $executionId);
        };
        return $this->middlewarePipeline->handle(['step' => $step, 'context' => $context], $handler);
    }

    private function executeStepInternal(array $step, ExecutionContext $context, array $workflowDef, string $executionId): array
    {
        $type = $step['type'] ?? 'unknown';
        $settings = $step['settings'] ?? [];

        switch ($type) {
            case 'trigger':
                return ['status' => 'completed', 'output' => ['message' => 'workflow_started']];
            case 'action':
                $actionType = $settings['action_type'] ?? $settings['type'] ?? 'noop';
                $attempt = 1;
                while (true) {
                    $result = $this->actionRegistry->execute($actionType, $settings, $context);
                    if ($result->isSuccess()) {
                        foreach ($result->getLogs() as $message) {
                            $context->addLog($message);
                        }
                        return [
                            'status' => 'completed',
                            'output' => $result->getOutput(),
                            'logs' => $result->getLogs(),
                            'nextNode' => $result->getNextNode(),
                        ];
                    }

                    $error = $result->getError() ?? 'action_failed';
                    if (!$this->retryEngine->shouldRetry($error, $attempt)) {
                        $this->stateService->update($executionId, ['retryCount' => $attempt - 1]);
                        return [
                            'status' => 'failed',
                            'output' => $result->getOutput(),
                            'logs' => $result->getLogs(),
                            'error' => $error,
                        ];
                    }

                    $delay = $this->retryEngine->getDelaySeconds($attempt);
                    $this->eventEmitter->emit('retry.scheduled', ['executionId' => $executionId, 'attempt' => $attempt, 'delaySeconds' => $delay, 'error' => $error]);
                    if ($delay > 0) {
                        usleep((int) ($delay * 1000000));
                    }
                    $attempt++;
                    $this->stateService->update($executionId, ['retryCount' => $attempt - 1]);
                }
            case 'condition':
                $result = (new ConditionAction())->execute($settings, $context);
                return [
                    'status' => 'completed',
                    'output' => $result->getOutput(),
                    'logs' => $result->getLogs(),
                    'nextNode' => $result->getNextNode(),
                ];
            case 'delay':
                $result = (new DelayAction())->execute($settings, $context);
                return ['status' => 'completed', 'output' => $result->getOutput(), 'logs' => $result->getLogs()];
            case 'approval':
                return ['status' => 'pending', 'output' => ['approver' => $settings['approver'] ?? null]];
            case 'end':
                return ['status' => 'completed', 'output' => ['message' => 'workflow_finished']];
            default:
                return ['status' => 'completed', 'output' => ['message' => 'step_skipped']];
        }
    }
}
