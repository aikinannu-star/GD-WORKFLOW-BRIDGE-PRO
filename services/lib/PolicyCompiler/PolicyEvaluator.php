<?php
declare(strict_types=1);

/**
 * PolicyEvaluator
 *
 * Evaluates a compiled RuleGraph against a file or content and returns typed violations.
 */
class PolicyEvaluator
{
    private RuleGraph $graph;

    public function __construct(RuleGraph $graph)
    {
        $this->graph = $graph;
    }

    /**
     * Evaluate a file path (or content via context) and return violations.
     * Each violation is an associative array: rule, message, severity, file
     *
     * This evaluator uses simple heuristics; the Policy Compiler will later
     * emit richer predicates for production-grade evaluation.
     *
     * @param string $filePath
     * @param array $context Optional keys: content (string)
     * @return array<int,array>
     */
    public function evaluateFile(string $filePath, array $context = []): array
    {
        $content = $context['content'] ?? (is_file($filePath) ? file_get_contents($filePath) : '');
        $violations = [];

        $graphArray = $this->graph->toArray();
        foreach ($graphArray['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'rule') continue;
            $meta = $node['meta'] ?? [];
            if (empty($meta['enabled'])) continue;

            $ruleName = $meta['name'] ?? ($node['id'] ?? 'unknown');
            $severity = $meta['severity'] ?? 'warning';
            $message = $meta['message'] ?? '';

            // Simple built-in heuristics for common rules
            if ($ruleName === 'no_cms_imports') {
                if (preg_match('/\b(include|require)\b|includes\//i', $content)) {
                    $violations[] = [
                        'rule' => $ruleName,
                        'message' => $message !== '' ? $message : 'CMS import detected',
                        'severity' => $severity,
                        'file' => $filePath,
                    ];
                }
            }

            if ($ruleName === 'no_business_logic') {
                if (preg_match('/\$(tenant|customer|order)\b/i', $content)) {
                    $violations[] = [
                        'rule' => $ruleName,
                        'message' => $message !== '' ? $message : 'Business logic detected',
                        'severity' => $severity,
                        'file' => $filePath,
                    ];
                }
            }

            if ($ruleName === 'require_rfc_for_control_plane') {
                // This is a meta-rule; flag change to control-plane files (detected elsewhere in tooling)
            }
        }

        return $violations;
    }
}
