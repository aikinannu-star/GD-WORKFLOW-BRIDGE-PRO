<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/../AssistantContext.php';

class WorkflowContextSource implements ContextSourceInterface
{
    public function collect(AssistantContext $context, string $message): array
    {
        $workflowState = $context->get('workflow_state');
        if (empty($workflowState)) {
            return [];
        }

        if (is_array($workflowState)) {
            $lines = [];
            foreach ($workflowState as $key => $value) {
                $lines[] = $key . ': ' . (is_scalar($value) ? (string)$value : json_encode($value));
            }
            $content = implode("\n", $lines);
        } else {
            $content = (string)$workflowState;
        }

        return [[
            'name' => 'workflow',
            'label' => 'Workflow state',
            'priority' => 70,
            'content' => $content,
            'metadata' => [],
        ]];
    }
}
