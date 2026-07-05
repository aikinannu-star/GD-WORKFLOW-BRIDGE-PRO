<?php
/**
 * Control Plane Boundary Linter (Policy-Driven)
 * 
 * Scans PR diffs for architectural violations per CONTROL_PLANE_POLICY.yml
 * Falls back to hardcoded rules if policy file not found.
 * Runs in enforcement mode specified by policy (currently WARNING by default).
 * 
 * Usage:
 *   php tools/boundary-linter.php [--pr-number=123] [--repo=owner/repo] [--token=github-token]
 * 
 * Environment vars used:
 *   GITHUB_TOKEN         - for GitHub API access
 *   GITHUB_EVENT_PATH    - path to GitHub Actions event JSON
 *   GITHUB_WORKSPACE    - workspace root (for relative path resolution)
 * 
 * Policy File:
 *   CONTROL_PLANE_POLICY.yml - source of truth for rules and enforcement mode
 */

// Load policy or use defaults
$policyFile = null;
$policy = null;

$prNumber = (int)($_SERVER['argv'][array_search('--pr-number', $_SERVER['argv'] ?? []) + 1] ?? 0);
$repo = $_SERVER['argv'][array_search('--repo', $_SERVER['argv'] ?? []) + 1] ?? null;
$token = getenv('GITHUB_TOKEN') ?: null;
$eventPath = getenv('GITHUB_EVENT_PATH');
$workspace = getenv('GITHUB_WORKSPACE') ?: getcwd();

// Load policy with correct workspace
$policy = loadPolicy($workspace);

// If running in GitHub Actions, parse event JSON for PR number and repo
if ($eventPath && file_exists($eventPath)) {
    $event = json_decode(file_get_contents($eventPath), true);
    $prNumber = $prNumber ?: ($event['pull_request']['number'] ?? 0);
    $repo = $repo ?: ($event['repository']['full_name'] ?? null);
}

if (!$prNumber || !$repo || !$token) {
    fwrite(STDERR, "ERROR: Missing --pr-number, --repo, or GITHUB_TOKEN env var\n");
    exit(1);
}

// Fetch PR details and diff
$apiUrl = "https://api.github.com/repos/{$repo}/pulls/{$prNumber}";
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github.v3+json',
            'User-Agent: boundary-linter/1.0'
        ]
    ]
]);

$prData = @file_get_contents($apiUrl, false, $context);
if (!$prData) {
    fwrite(STDERR, "ERROR: Failed to fetch PR details from {$apiUrl}\n");
    exit(1);
}

$pr = json_decode($prData, true);
$prBody = $pr['body'] ?? '';
$prFiles = $pr['changed_files'] ?? 0;

// Fetch PR diff
$diffUrl = "https://api.github.com/repos/{$repo}/pulls/{$prNumber}.diff";
$diffContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            "Authorization: Bearer {$token}",
            'User-Agent: boundary-linter/1.0'
        ]
    ]
]);

$diff = @file_get_contents($diffUrl, false, $diffContext);
if (!$diff) {
    fwrite(STDERR, "WARNING: Could not fetch diff, skipping detailed checks\n");
    $diff = '';
}

$issues = [];

// Parse diff and check for violations
$files = parseGitDiff($diff);
$controlPlaneFiles = array_filter(array_keys($files), fn($f) => isControlPlaneFile($f));
$hasControlPlaneChanges = !empty($controlPlaneFiles);

foreach ($controlPlaneFiles as $file) {
    $changes = $files[$file];
    foreach ($changes as $line) {
        // Check for forbidden imports in control-plane files
        if (preg_match('/^\+.*require.*[\'"].*includes\//', $line) || 
            preg_match('/^\+.*include.*[\'"].*includes\//', $line)) {
            $issues[] = [
                'file' => $file,
                'message' => $policy['rules']['no_cms_imports']['message'] ?? 'Control-plane code imports CMS business logic (includes/*). Use HTTP API or pub/sub instead.',
                'severity' => $policy['rules']['no_cms_imports']['severity'] ?? 'warning',
                'rule' => 'no-cms-imports'
            ];
        }
        
        // Check for business logic in control plane
        if (preg_match('/^\+.*(if.*\$tenant|if.*\$customer|if.*\$order)/', $line, $m)) {
            $issues[] = [
                'file' => $file,
                'message' => $policy['rules']['no_business_logic']['message'] ?? 'Potential business logic in control-plane code detected: ' . trim($m[0]),
                'severity' => $policy['rules']['no_business_logic']['severity'] ?? 'warning',
                'rule' => 'no-business-logic'
            ];
        }
    }
}

// Check for RFC linkage when control plane changes without RFC reference
if ($hasControlPlaneChanges && !preg_match('/RFC|rfc|#\d+.*RFC/i', $prBody)) {
    $issues[] = [
        'file' => 'PR body',
        'message' => $policy['rules']['require_rfc_for_control_plane']['message'] ?? 'Control-plane changes detected but no RFC issue linked in PR body. Reference RFC discussion or open one via RFC_TEMPLATE.md.',
        'severity' => $policy['rules']['require_rfc_for_control_plane']['severity'] ?? 'warning',
        'rule' => 'missing-rfc'
    ];
}

// Output issues in GitHub Actions annotation format
if (!empty($issues)) {
    echo "Control Plane Boundary Linter found " . count($issues) . " issue(s):\n\n";
    foreach ($issues as $issue) {
        echo "::warning file={$issue['file']},title=Control Plane Boundary ({$issue['rule']}):: {$issue['message']}\n";
    }
} else {
    echo "✓ No boundary violations detected\n";
}

// Summary
echo "\nLinter Summary:\n";
echo "  PR #: {$prNumber}\n";
echo "  Files changed: {$prFiles}\n";
echo "  Control-plane files: " . count($controlPlaneFiles) . "\n";
echo "  Issues found: " . count($issues) . "\n";
echo "  Enforcement mode: " . ($policy['enforcement']['mode'] ?? 'warning') . "\n";
echo "  Policy file: " . (file_exists($policyFile) ? $policyFile : 'not found (using defaults)') . "\n";

exit(0); // Always succeed in warning mode

// Helper: Load policy from YAML or use defaults
function loadPolicy(string $workspace): array {
    global $policyFile;
    
    $policyFile = $workspace . '/CONTROL_PLANE_POLICY.yml';
    
    if (!file_exists($policyFile)) {
        // Return hardcoded defaults if no policy file
        return getDefaultPolicy();
    }
    
    // Parse YAML (simple implementation for basic YAML)
    $content = file_get_contents($policyFile);
    $policy = parseSimpleYaml($content);
    
    return $policy ?: getDefaultPolicy();
}

// Helper: Parse simple YAML (basic format for policy file)
function parseSimpleYaml(string $yaml): array {
    $lines = explode("\n", $yaml);
    $result = [];
    $stack = [];
    $current = &$result;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = ltrim($line);
        
        if (preg_match('/^(\w+):\s*"?([^"]*)"?/', $trimmed, $m)) {
            $key = $m[1];
            $val = trim($m[2] ?? '');
            
            // Adjust stack based on indent
            while (count($stack) > $indent / 2) {
                array_pop($stack);
            }
            
            if (!empty($val)) {
                $current[$key] = $val;
            } else {
                $current[$key] = [];
                $stack[] = &$current;
                $current = &$current[$key];
            }
        }
    }
    
    return $result ?: [];
}

// Helper: Get default policy (fallback)
function getDefaultPolicy(): array {
    return [
        'version' => '1.0',
        'enforcement' => [
            'mode' => 'warning',
            'block_on_violations' => false,
            'require_approval_for_control_plane' => true,
            'approval_owners' => ['@aikinannu-star']
        ],
        'control_plane' => [
            'paths' => [
                'services/gateway/',
                'services/lib/ControlPlaneAuth.php',
                'services/lib/AccessGraph.php',
                'services/lib/Metrics.php',
                'services/lib/PermissionService.php',
                '.github/workflows/auth-tests.yml'
            ]
        ],
        'rules' => [
            'no_cms_imports' => [
                'severity' => 'warning',
                'enabled' => true,
                'message' => 'Control-plane code imports CMS business logic (includes/*). Use HTTP API or pub/sub instead.'
            ],
            'no_business_logic' => [
                'severity' => 'warning',
                'enabled' => true,
                'message' => 'Potential business logic in control-plane code. Business logic belongs in the Application Plane (CMS).'
            ],
            'require_rfc_for_control_plane' => [
                'severity' => 'warning',
                'enabled' => true,
                'message' => 'Control-plane changes detected. Link an RFC issue for design review via .github/RFC_TEMPLATE.md'
            ]
        ]
    ];
}

// Helper: Parse unified diff format
function parseGitDiff(string $diff): array {
    $files = [];
    $currentFile = null;
    $lines = explode("\n", $diff);
    
    foreach ($lines as $line) {
        // Detect file changes: +++ b/path/to/file
        if (preg_match('/^\+\+\+ b\/(.+)$/', $line, $m)) {
            $currentFile = $m[1];
            $files[$currentFile] = [];
        } elseif ($currentFile && (preg_match('/^\+/', $line) || preg_match('/^\-/', $line))) {
            $files[$currentFile][] = $line;
        }
    }
    
    return $files;
}

// Helper: Check if file is control-plane code
function isControlPlaneFile(string $file): bool {
    global $policy;
    
    $controlPlanePaths = $policy['control_plane']['paths'] ?? [
        'services/gateway/',
        'services/lib/ControlPlaneAuth.php',
        'services/lib/AccessGraph.php',
        'services/lib/Metrics.php',
        'services/lib/PermissionService.php',
        '.github/workflows/auth-tests.yml'
    ];
    
    foreach ($controlPlanePaths as $path) {
        if (strpos($file, $path) === 0) {
            return true;
        }
    }
    
    return false;
}
