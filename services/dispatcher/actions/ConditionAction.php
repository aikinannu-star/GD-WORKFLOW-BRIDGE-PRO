<?php
require_once __DIR__ . '/ActionInterface.php';

class ConditionAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $expression = $payload['condition'] ?? $payload['expression'] ?? '';
        $result = $this->evaluateCondition($expression, $context->getVariables());

        // Determine branching next node if provided
        $trueNext = $payload['true_next'] ?? $payload['trueNext'] ?? null;
        $falseNext = $payload['false_next'] ?? $payload['falseNext'] ?? null;
        $nextNode = $result ? ($trueNext ?? null) : ($falseNext ?? null);

        return ActionResult::success(['condition_result' => $result], $nextNode, []);
    }

    private function evaluateCondition(string $expr, array $context): bool
    {
        if ($expr === '') {
            return true;
        }
        if (preg_match('/^([^!=<>]+)\s*(==|!=)\s*(.+)$/', $expr, $m)) {
            $left = trim($m[1]);
            $op = $m[2];
            $right = trim($m[3]);
            $leftValue = $context[$left] ?? null;
            $rightValue = $this->normalizeConditionValue($right);
            if ($op === '==') {
                return (string) $leftValue === (string) $rightValue;
            }
            return (string) $leftValue !== (string) $rightValue;
        }
        return (bool) ($context[$expr] ?? false);
    }

    private function normalizeConditionValue(string $value)
    {
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return floatval($value);
        }
        return $value;
    }
}
