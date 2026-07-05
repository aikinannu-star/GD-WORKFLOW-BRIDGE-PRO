<?php
/**
 * Policy Simulation Layer
 *
 * Simulates enforcement mode transitions to predict impact before enforcement.
 * Prevents unsafe transitions, enables safe experimentation, eliminates CI surprises.
 *
 * Usage:
 *   php tools/policy-simulator.php [--commits=5] [--profile=strict|permissive|warning|error]
 *
 * Output: CLI report showing violations under different enforcement modes
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../services/lib/PolicyCompiler/PredicateEvaluator.php';
require_once __DIR__ . '/../services/lib/PolicyCompiler/PolicyEvaluatorV2.php';

// Parse arguments
$commits = 5;
$targetProfile = 'strict';

foreach ($argv as $arg) {
    if (preg_match('/--commits=(\d+)/', $arg, $m)) {
        $commits = (int)$m[1];
    }
    if (preg_match('/--profile=(strict|permissive|warning|error)/', $arg, $m)) {
        $targetProfile = $m[1];
    }
}

// Load policy
$policyPath = __DIR__ . '/../CONTROL_PLANE_POLICY.yml';
$policy = loadPolicy($policyPath);
$compiledPolicyEvaluator = null;

if (file_exists(__DIR__ . '/../build/compiled-policy.json')) {
    try {
        $compiledPolicyEvaluator = PolicyEvaluatorV2::fromCompiledFile(__DIR__ . '/../build/compiled-policy.json');
        echo "Loaded compiled policy artifact from build/compiled-policy.json\n\n";
    } catch (RuntimeException $e) {
        fwrite(STDERR, "WARNING: {$e->getMessage()}\n");
    }
}

if (!$policy) {
    fwrite(STDERR, "ERROR: Could not load policy from {$policyPath}\n");
    exit(1);
}

if ($compiledPolicy) {
    echo "Loaded compiled policy artifact from build/compiled-policy.json\n\n";
}

// Get current and target enforcement profiles
$currentProfile = $policy['enforcement'] ?? [];
$allProfiles = $policy['enforcement_profiles'] ?? [];

$targetConfig = getEnforcementConfig($targetProfile, $allProfiles, $currentProfile);

if (!$targetConfig) {
    fwrite(STDERR, "ERROR: Unknown enforcement profile: {$targetProfile}\n");
    exit(1);
}

echo "\n";
echo "=" . str_repeat("=", 79) . "=\n";
echo "Policy Simulation Report\n";
echo "=" . str_repeat("=", 79) . "=\n";
echo "\n";

echo "Current Enforcement: {$currentProfile['mode']} mode\n";
echo "Target Enforcement:  {$targetConfig['mode']} mode\n";
echo "\n";

// Get recent commits
$recentCommits = getRecentCommits($commits);

if (empty($recentCommits)) {
    echo "No commits found to analyze.\n";
    exit(0);
}

echo "Analyzing {$commits} recent commits...\n";
echo "\n";

// Simulate enforcement for each commit
$violations = [
    'by_mode' => [
        'current' => [],
        'target' => [],
    ],
    'by_rule' => [],
    'by_file' => [],
    'by_severity' => [
        'error' => [],
        'warning' => [],
    ],
];

foreach ($recentCommits as $commit) {
    $diff = getCommitDiff($commit['hash']);
    
    if (empty($diff)) {
        continue;
    }
    
    // Parse diff to find files and changes
    $files = parseGitDiff($diff);
    
    foreach ($files as $file) {
            // Check boundary rules for this file using compiled policy when available.
            if ($compiledPolicyEvaluator !== null) {
                $issues = checkCompiledBoundaryRules($file, $compiledPolicyEvaluator);
            $issues = checkBoundaryRules($file, $policy);
        }
        
        foreach ($issues as $issue) {
            // Determine if issue triggers under current vs target mode
            $rule = findRule($policy, $issue['rule']);
            $severity = $rule['severity'] ?? 'warning';
            
            $violation = [
                'file' => $file,
                'rule' => $issue['rule'],
                'severity' => $severity,
                'message' => $issue['message'],
                'commit' => $commit['hash'],
                'author' => $commit['author'],
                'subject' => $commit['subject'],
            ];
            
            // Track in current mode
            if ($currentProfile['mode'] === 'warning' || $severity === 'error') {
                $violations['by_mode']['current'][] = $violation;
            }
            
            // Track in target mode
            if ($targetConfig['mode'] === 'warning' || $severity === 'error') {
                $violations['by_mode']['target'][] = $violation;
            }
            
            // Track by rule
            if (!isset($violations['by_rule'][$issue['rule']])) {
                $violations['by_rule'][$issue['rule']] = [];
            }
            $violations['by_rule'][$issue['rule']][] = $violation;
            
            // Track by file
            if (!isset($violations['by_file'][$file])) {
                $violations['by_file'][$file] = [];
            }
            $violations['by_file'][$file][] = $violation;
            
            // Track by severity
            $violations['by_severity'][$severity][] = $violation;
        }
    }
}

// Generate report
generateReport($violations, $currentProfile, $targetConfig, $policy);

exit(0);

// ============================================================================
// HELPERS
// ============================================================================

function loadPolicy(string $path): ?array {
    if (!file_exists($path)) {
        return null;
    }
    
    $yaml = file_get_contents($path);
    return parseSimpleYaml($yaml);
}

function parseSimpleYaml(string $yaml): array {
    $lines = explode("\n", $yaml);
    $result = [];
    $stack = [['ref' => &$result, 'indent' => -1]];
    
    foreach ($lines as $line) {
        $line = preg_replace('/#.*$/', '', $line);
        if (empty(trim($line))) continue;
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);
        
        while (count($stack) > 1 && $stack[count($stack) - 1]['indent'] >= $indent) {
            array_pop($stack);
        }
        
        $current = &$stack[count($stack) - 1]['ref'];
        
        if (preg_match('/^([\w\-_]+):\s*(.*)$/i', $trimmed, $m)) {
            $key = $m[1];
            $val = trim($m[2]);
            
            if (empty($val)) {
                $current[$key] = [];
                $stack[] = ['ref' => &$current[$key], 'indent' => $indent];
            } else {
                $current[$key] = parseYamlValue($val);
            }
        } elseif (preg_match('/^-\s+(.+)$/i', $trimmed, $m)) {
            $current[] = parseYamlValue($m[1]);
        }
    }
    
    return $result;
}

function parseYamlValue(string $val): mixed {
    $val = trim($val);
    
    if (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'")) {
        return substr($val, 1, -1);
    }
    
    if ($val === 'true' || $val === 'yes' || $val === 'on') return true;
    if ($val === 'false' || $val === 'no' || $val === 'off') return false;
    if ($val === 'null' || $val === '~') return null;
    if (is_numeric($val)) return (int)$val;
    
    return $val;
}

function getEnforcementConfig(string $profile, array $allProfiles, array $currentConfig): ?array {
    if (isset($allProfiles[$profile])) {
        return $allProfiles[$profile];
    }
    
    // Fallback: map mode names to configs
    if ($profile === 'strict' || $profile === 'error') {
        return ['mode' => 'error', 'block_on_violations' => true];
    }
    if ($profile === 'permissive' || $profile === 'warning') {
        return ['mode' => 'warning', 'block_on_violations' => false];
    }
    
    return null;
}

function getRecentCommits(int $count): array {
    $cmd = "git log -{$count} --format=%H%n%an%n%s%n---";
    $output = shell_exec($cmd);
    
    if (!$output) {
        return [];
    }
    
    $commits = [];
    $blocks = explode("---\n", trim($output));
    
    foreach ($blocks as $block) {
        if (empty(trim($block))) continue;
        
        $lines = array_filter(explode("\n", $block));
        if (count($lines) >= 3) {
            $commits[] = [
                'hash' => $lines[0] ?? '',
                'author' => $lines[1] ?? 'Unknown',
                'subject' => $lines[2] ?? 'No message',
            ];
        }
    }
    
    return $commits;
}

function getCommitDiff(string $hash): string {
    $cmd = "git show {$hash}";
    return shell_exec($cmd) ?? '';
}

function loadCompiledArtifact(string $path): ?array {
    if (!file_exists($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return null;
    }

    $payload = json_decode($content, true);
    return is_array($payload) ? $payload : null;
}

function checkCompiledBoundaryRules(string $file, PolicyEvaluatorV2 $evaluator): array {
    $issues = [];
    $violations = $evaluator->evaluateFile($file);

    foreach ($violations as $violation) {
        if (!($violation instanceof Violation)) {
            continue;
        }

        $issues[] = [
            'rule' => $violation->getRule(),
            'message' => $violation->getMessage(),
            'severity' => $violation->getSeverity(),
        ];
    }

    return $issues;
}

function parseGitDiff(string $diff): array {
    $files = [];
    $lines = explode("\n", $diff);
    
    foreach ($lines as $line) {
        if (preg_match('/^diff --git a\/.+ b\/(.+)$/', $line, $m)) {
            $files[] = $m[1];
        }
    }
    
    return array_unique($files);
}

function checkBoundaryRules(string $file, array $policy): array {
    $issues = [];
    $rules = $policy['rules'] ?? [];
    
    foreach ($rules as $ruleName => $rule) {
        if (!($rule['enabled'] ?? false)) {
            continue;
        }
        
        $issue = null;
        
        if ($ruleName === 'no_cms_imports') {
            if (isControlPlaneFile($file, $policy) && isCmsImportFile($file)) {
                $issue = [
                    'rule' => $ruleName,
                    'message' => $rule['message'] ?? 'CMS imports not allowed in control-plane files',
                ];
            }
        } elseif ($ruleName === 'no_business_logic') {
            if (isControlPlaneFile($file, $policy) && hasBusinessLogic($file)) {
                $issue = [
                    'rule' => $ruleName,
                    'message' => $rule['message'] ?? 'Business logic detected in control-plane file',
                ];
            }
        } elseif ($ruleName === 'require_rfc_for_control_plane') {
            if (isControlPlaneFile($file, $policy)) {
                $issue = [
                    'rule' => $ruleName,
                    'message' => $rule['message'] ?? 'RFC required for control-plane changes',
                ];
            }
        }
        
        if ($issue) {
            $issues[] = $issue;
        }
    }
    
    return $issues;
}

function isControlPlaneFile(string $file, array $policy): bool {
    $paths = $policy['control_plane']['paths'] ?? [];
    
    foreach ($paths as $pattern) {
        if (fnmatch($pattern, $file) || fnmatch($pattern . '/*', $file)) {
            return true;
        }
    }
    
    return false;
}

function isCmsImportFile(string $file): bool {
    // Heuristic: files in includes/ are CMS files
    return strpos($file, 'includes/') === 0;
}

function hasBusinessLogic(string $file): bool {
    // Heuristic: look for tenant/customer/order patterns (would need actual diff content in real scenario)
    return preg_match('/\$(tenant|customer|order)/i', $file);
}

function findRule(array $policy, string $ruleName): array {
    $rules = $policy['rules'] ?? [];
    return $rules[$ruleName] ?? ['severity' => 'warning'];
}

function generateReport(array $violations, array $current, array $target, array $policy): void {
    $currentViolations = count($violations['by_mode']['current']);
    $targetViolations = count($violations['by_mode']['target']);
    
    // Summary
    echo "SUMMARY\n";
    echo str_repeat("-", 80) . "\n";
    echo "Current violations (" . $current['mode'] . " mode):  " . $currentViolations . "\n";
    echo "Target violations  (" . $target['mode'] . " mode):   " . $targetViolations . "\n";
    
    $delta = $targetViolations - $currentViolations;
    if ($delta > 0) {
        echo "Impact of transition:                +{$delta} newly blocking violations\n";
    } elseif ($delta < 0) {
        echo "Impact of transition:                {$delta} violations would be unblocked\n";
    } else {
        echo "Impact of transition:                No change\n";
    }
    echo "\n";
    
    // Violations by severity
    echo "VIOLATIONS BY SEVERITY\n";
    echo str_repeat("-", 80) . "\n";
    echo "Errors:    " . count($violations['by_severity']['error']) . "\n";
    echo "Warnings:  " . count($violations['by_severity']['warning']) . "\n";
    echo "\n";
    
    // Violations by rule
    if (!empty($violations['by_rule'])) {
        echo "VIOLATIONS BY RULE\n";
        echo str_repeat("-", 80) . "\n";
        foreach ($violations['by_rule'] as $ruleName => $issues) {
            $rule = findRule($policy, $ruleName);
            echo "{$ruleName} ({$rule['severity']}):  " . count($issues) . " violations\n";
        }
        echo "\n";
    }
    
    // Most affected files
    if (!empty($violations['by_file'])) {
        echo "MOST AFFECTED FILES\n";
        echo str_repeat("-", 80) . "\n";
        
        $fileViolationCounts = [];
        foreach ($violations['by_file'] as $file => $issues) {
            $fileViolationCounts[$file] = count($issues);
        }
        arsort($fileViolationCounts);
        
        $topFiles = array_slice($fileViolationCounts, 0, 5, true);
        foreach ($topFiles as $file => $count) {
            echo "  {$file}: {$count} violations\n";
        }
        echo "\n";
    }
    
    // Migration recommendations
    echo "MIGRATION RECOMMENDATIONS\n";
    echo str_repeat("-", 80) . "\n";
    
    if ($targetViolations === 0) {
        echo "✓ Safe to transition to {$target['mode']} mode immediately\n";
        echo "  No violations detected under target profile\n";
    } elseif ($targetViolations <= 3) {
        echo "⚠ Ready for gradual transition\n";
        echo "  {$targetViolations} violations to resolve before enforcement\n";
        echo "  Plan: Fix violations in upcoming PRs, then enable error mode\n";
    } else {
        echo "✗ Hold on enforcement transition\n";
        echo "  {$targetViolations} violations would block under error mode\n";
        echo "  Plan: Use WARNING mode for 2-3 release cycles to surface violations\n";
        echo "       Then fix systematically, then transition to ERROR mode\n";
    }
    echo "\n";
    
    // Detailed violations (if any)
    if ($targetViolations > 0 && $targetViolations <= 10) {
        echo "DETAILED VIOLATIONS (TARGET MODE)\n";
        echo str_repeat("-", 80) . "\n";
        
        $count = 0;
        foreach ($violations['by_mode']['target'] as $violation) {
            if ($count++ >= 10) break;
            
            echo "\n{$violation['file']}\n";
            echo "  Rule:     {$violation['rule']}\n";
            echo "  Severity: {$violation['severity']}\n";
            echo "  Message:  {$violation['message']}\n";
            echo "  Commit:   {$violation['commit']} ({$violation['author']})\n";
            echo "  Subject:  {$violation['subject']}\n";
        }
        
        if ($targetViolations > 10) {
            echo "\n... and " . ($targetViolations - 10) . " more violations\n";
        }
        echo "\n";
    }
    
    // Next steps
    echo "NEXT STEPS\n";
    echo str_repeat("-", 80) . "\n";
    
    if ($target['mode'] === 'error') {
        echo "1. Review violations above\n";
        echo "2. Plan fixes in upcoming PRs\n";
        echo "3. Run this simulator again: php tools/policy-simulator.php --profile=error\n";
        echo "4. When zero violations: update CONTROL_PLANE_POLICY.yml\n";
        echo "   enforcement.mode: \"error\"\n";
        echo "5. Commit and push to enable error-mode enforcement in CI\n";
    } else {
        echo "1. Monitor violations in WARNING mode\n";
        echo "2. File issues for violations that need fixing\n";
        echo "3. Run simulator periodically to track progress\n";
        echo "4. When ready: php tools/policy-simulator.php --profile=strict\n";
    }
    echo "\n";
}
