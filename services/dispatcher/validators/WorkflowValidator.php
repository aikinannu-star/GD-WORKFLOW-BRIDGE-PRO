<?php
/**
 * Simple Workflow Validator
 * Performs structural and business-rule validation for AI-generated workflows.
 */

class WorkflowValidator
{
    private array $allowedNodeTypes = ['trigger','action','condition','delay','approval','end','service_call'];

    public function validate(array $workflow): array
    {
        $errors = [];

        // Accept either snake_case or camelCase keys for workflow id
        if (empty($workflow['workflow_id'] ?? $workflow['workflowId'] ?? null)) {
            $errors[] = 'Missing workflow_id or workflowId';
        }

        foreach (['name','version','steps'] as $k) {
            if (!isset($workflow[$k])) {
                $errors[] = 'Missing required key: ' . $k;
            }
        }

        $steps = $workflow['steps'] ?? [];
        if (!is_array($steps)) {
            $errors[] = 'steps must be an array';
            return ['valid' => false, 'errors' => $errors];
        }

        // Collect IDs and check uniqueness
        $ids = [];
        foreach ($steps as $i => $step) {
            $id = $step['id'] ?? null;
            if (empty($id) || !is_string($id)) {
                $errors[] = "Step at index $i missing valid 'id'";
                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = "Duplicate step id: $id";
            }
            $ids[$id] = true;

            $type = $step['type'] ?? null;
            if (empty($type) || !in_array($type, $this->allowedNodeTypes, true)) {
                $errors[] = "Step $id has invalid or missing type: " . ($type ?? 'null');
            }

            // Approval nodes require approver
            if ($type === 'approval') {
                $approver = $step['settings']['approver'] ?? null;
                if (empty($approver)) {
                    $errors[] = "Approval step $id requires settings.approver";
                }
            }

            // Delay nodes require duration
            if ($type === 'delay') {
                $duration = $step['settings']['duration'] ?? null;
                if (!is_numeric($duration) || $duration <= 0) {
                    $errors[] = "Delay step $id requires positive numeric settings.duration";
                }
            }
        }

        // Validate next references and build adjacency
        $adj = [];
        foreach ($steps as $step) {
            $id = $step['id'] ?? null;
            if (!$id) { continue; }
            $next = $step['next'] ?? [];
            if (!is_array($next)) { $errors[] = "Step $id: next must be an array"; continue; }
            $adj[$id] = $next;
            foreach ($next as $t) {
                if (!isset($ids[$t])) {
                    $errors[] = "Step $id has next reference to unknown step: $t";
                }
            }
        }

        // Find trigger node(s)
        $triggerIds = [];
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'trigger') { $triggerIds[] = $step['id']; }
        }
        if (count($triggerIds) !== 1) {
            $errors[] = 'There must be exactly one trigger node; found ' . count($triggerIds);
        }

        // At least one end node
        $endCount = 0;
        foreach ($steps as $step) { if (($step['type'] ?? '') === 'end') { $endCount++; } }
        if ($endCount < 1) { $errors[] = 'There must be at least one end node'; }

        // Connectivity: all nodes reachable from trigger
        if (!empty($triggerIds) && isset($adj[$triggerIds[0]])) {
            $visited = $this->dfsReachable($triggerIds[0], $adj);
            foreach (array_keys($ids) as $nid) {
                if (!isset($visited[$nid])) {
                    $errors[] = "Node $nid is not reachable from trigger";
                }
            }
        }

        // Cycle detection
        $hasCycle = $this->detectCycle($adj);
        if ($hasCycle) {
            $errors[] = 'Workflow contains a cycle (loops are currently unsupported)';
        }

        // Security: check action types (basic)
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'action') {
                $actionType = $step['settings']['action_type'] ?? null;
                if (empty($actionType)) {
                    $errors[] = "Action step {$step['id']} missing settings.action_type";
                }
                // reject unsafe action types
                if (in_array($actionType, ['exec_shell','run_binary','write_file_unrestricted'], true)) {
                    $errors[] = "Action step {$step['id']} uses forbidden action_type: $actionType";
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function dfsReachable(string $start, array $adj): array
    {
        $stack = [$start];
        $visited = [];
        while (!empty($stack)) {
            $n = array_pop($stack);
            if (isset($visited[$n])) { continue; }
            $visited[$n] = true;
            foreach ($adj[$n] ?? [] as $m) {
                if (!isset($visited[$m])) { $stack[] = $m; }
            }
        }
        return $visited;
    }

    private function detectCycle(array $adj): bool
    {
        $visited = [];
        $rec = [];
        foreach (array_keys($adj) as $node) {
            if ($this->detectCycleDfs($node, $adj, $visited, $rec)) { return true; }
        }
        return false;
    }

    private function detectCycleDfs($node, $adj, &$visited, &$rec): bool
    {
        if (!empty($rec[$node])) { return true; }
        if (!empty($visited[$node])) { return false; }
        $visited[$node] = true;
        $rec[$node] = true;
        foreach ($adj[$node] ?? [] as $n) {
            if ($this->detectCycleDfs($n, $adj, $visited, $rec)) { return true; }
        }
        $rec[$node] = false;
        return false;
    }
}
