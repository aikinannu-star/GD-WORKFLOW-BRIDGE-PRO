<?php
/**
 * Control Plane Policy Validator
 * 
 * Validates CONTROL_PLANE_POLICY.yml for:
 * - Schema correctness
 * - Backward compatibility
 * - Breaking change detection
 * 
 * Usage:
 *   php tools/policy-validator.php [--policy=path/to/policy.yml] [--strict]
 * 
 * Exit codes:
 *   0: Policy is valid
 *   1: Schema validation failed
 *   2: Breaking changes detected
 */

$policyPath = 'CONTROL_PLANE_POLICY.yml';
$strict = false;

foreach ($_SERVER['argv'] as $arg) {
    if (strpos($arg, '--policy=') === 0) {
        $policyPath = substr($arg, strlen('--policy='));
    }
    if ($arg === '--policy') {
        $nextKey = array_search($arg, $_SERVER['argv']) + 1;
        if (isset($_SERVER['argv'][$nextKey])) {
            $policyPath = $_SERVER['argv'][$nextKey];
        }
    }
    if ($arg === '--strict') {
        $strict = true;
    }
}

if (!file_exists($policyPath)) {
    fwrite(STDERR, "ERROR: Policy file not found: {$policyPath}\n");
    exit(1);
}

// Parse policy file (try yaml extension first, fall back to simple parser)
$policy = null;
if (function_exists('yaml_parse_file')) {
    $policy = yaml_parse_file($policyPath);
}

if (!$policy) {
    // Fallback: use simple YAML parser
    $content = file_get_contents($policyPath);
    $policy = parseSimpleYaml($content);
}

if (!$policy || !is_array($policy)) {
    fwrite(STDERR, "ERROR: Failed to parse policy file: {$policyPath}\n");
    exit(1);
}

$errors = [];
$warnings = [];

// Validate schema
validateSchema($policy, $errors, $warnings);

// Check for breaking changes (by comparing against known valid policy)
$baselinePolicy = getBaselinePolicy();
detectBreakingChanges($policy, $baselinePolicy, $errors, $warnings);

// Validate compiled artifact if present
validateCompiledArtifact($policy, $policyPath, $errors, $warnings);

// Output results
echo "Policy Validation Results\n";
echo "========================\n\n";

if (!empty($errors)) {
    echo "❌ ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
    echo "\n";
}

if (!empty($errors) || ($strict && !empty($warnings))) {
    exit(1);
}

if (empty($errors) && empty($warnings)) {
    echo "✓ Policy is valid and backward-compatible\n";
} elseif (empty($errors)) {
    echo "✓ Policy is valid (with warnings; use --strict to treat as errors)\n";
}

echo "\nPolicy Version: " . ($policy['version'] ?? 'unknown') . "\n";

$rules = $policy['rules'] ?? [];
$rulesCount = is_array($rules) ? count($rules) : 0;
$deprecatedRules = $policy['deprecated_rules'] ?? [];
$deprecatedCount = is_array($deprecatedRules) ? count($deprecatedRules) : 0;
echo "Rules Count: " . ($rulesCount + $deprecatedCount) . "\n";

$controlPlanePaths = $policy['control_plane']['paths'] ?? [];
$pathCount = is_array($controlPlanePaths) ? count($controlPlanePaths) : 0;
echo "Control-Plane Paths: {$pathCount}\n";

exit(empty($errors) ? 0 : 1);

// Helpers

function validateSchema(array $policy, &$errors, &$warnings): void {
    // Required fields
    if (empty($policy['version'])) {
        $errors[] = "Missing required field: version";
    }
    
    if (empty($policy['schema_version'])) {
        $errors[] = "Missing required field: schema_version";
    }
    
    if ($policy['version'] !== $policy['schema_version']) {
        $warnings[] = "version ({$policy['version']}) does not match schema_version ({$policy['schema_version']})";
    }
    
    if (empty($policy['control_plane']) || empty($policy['control_plane']['paths'])) {
        $errors[] = "Missing or empty: control_plane.paths";
    }
    
    if (!is_array($policy['control_plane']['paths'])) {
        $errors[] = "control_plane.paths must be an array";
    } else {
        foreach ($policy['control_plane']['paths'] as $path) {
            if (empty($path) || !is_string($path)) {
                $errors[] = "control_plane.paths contains invalid entry: " . json_encode($path);
            }
        }
    }
    
    // Validate rules
    if (!isset($policy['rules'])) {
        $errors[] = "Missing required field: rules";
    } else {
        foreach (($policy['rules'] ?? []) as $ruleName => $rule) {
            validateRule($ruleName, $rule, $errors, $warnings);
        }
    }
    
    // Validate enforcement config
    if (isset($policy['enforcement']['mode'])) {
        $validModes = ['warning', 'error', 'disabled'];
        if (!in_array($policy['enforcement']['mode'], $validModes)) {
            $errors[] = "enforcement.mode must be one of: " . implode(', ', $validModes);
        }
    }
}

function validateRule(string $ruleName, array $rule, &$errors, &$warnings): void {
    $required = ['description', 'severity', 'enabled', 'message'];
    foreach ($required as $field) {
        if (!isset($rule[$field])) {
            $errors[] = "Rule '{$ruleName}' missing required field: {$field}";
        }
    }
    
    if (isset($rule['severity'])) {
        $validSeverities = ['warning', 'error'];
        if (!in_array($rule['severity'], $validSeverities)) {
            $errors[] = "Rule '{$ruleName}' has invalid severity. Must be one of: " . implode(', ', $validSeverities);
        }
    }
    
    if (isset($rule['enabled']) && !is_bool($rule['enabled'])) {
        $errors[] = "Rule '{$ruleName}' enabled must be boolean";
    }
}

function detectBreakingChanges(array $policy, array $baseline, &$errors, &$warnings): void {
    // Check if rules were removed (breaking)
    $baselineRules = array_keys($baseline['rules'] ?? []);
    $currentRules = array_keys($policy['rules'] ?? []);
    
    foreach ($baselineRules as $ruleName) {
        if (!in_array($ruleName, $currentRules)) {
            // Check if rule was properly deprecated
            $deprecatedRules = $policy['deprecated_rules'] ?? [];
            $isDeprecated = false;
            foreach ($deprecatedRules as $dep) {
                if (($dep['rule_name'] ?? null) === $ruleName) {
                    $isDeprecated = true;
                    break;
                }
            }
            
            if (!$isDeprecated) {
                $errors[] = "BREAKING: Rule '{$ruleName}' was removed without deprecation. Must add to deprecated_rules first.";
            }
        }
    }
    
    // Check if paths were removed (breaking)
    $baselinePaths = $baseline['control_plane']['paths'] ?? [];
    $currentPaths = $policy['control_plane']['paths'] ?? [];
    
    foreach ($baselinePaths as $path) {
        if (!in_array($path, $currentPaths)) {
            $warnings[] = "BREAKING: Control-plane path removed: {$path}. Ensure this is intentional and documented in RFC.";
        }
    }
    
    // Check for severity changes (breaking when warning → error)
    foreach (($baseline['rules'] ?? []) as $ruleName => $baselineRule) {
        $currentRule = $policy['rules'][$ruleName] ?? null;
        if ($currentRule && $baselineRule['severity'] !== $currentRule['severity']) {
            if ($baselineRule['severity'] === 'warning' && $currentRule['severity'] === 'error') {
                $errors[] = "BREAKING: Rule '{$ruleName}' severity changed from warning → error. Requires RFC and migration period.";
            }
        }
    }
}

function validateCompiledArtifact(array $policy, string $policyPath, &$errors, &$warnings): void {
    $compiledPath = __DIR__ . '/../build/compiled-policy.json';
    if (!file_exists($compiledPath)) {
        $warnings[] = "Compiled policy artifact not present: {$compiledPath}";
        return;
    }

    $compiled = json_decode(file_get_contents($compiledPath) ?: '', true);
    if (!is_array($compiled)) {
        $errors[] = "Compiled policy artifact is invalid JSON: {$compiledPath}";
        return;
    }

    $metadata = $compiled['metadata'] ?? [];
    if (!is_array($metadata)) {
        $errors[] = "Compiled policy artifact metadata missing or invalid.";
        return;
    }

    $supportedArtifacts = ['1.0'];
    $artifactVersion = $metadata['artifact_version'] ?? null;
    if ($artifactVersion === null || !in_array($artifactVersion, $supportedArtifacts, true)) {
        $errors[] = "Unsupported compiled policy artifact version: " . json_encode($artifactVersion) . ". Supported: " . implode(', ', $supportedArtifacts);
    }

    $policySchemaVersion = $metadata['policy_schema_version'] ?? null;
    if ($policySchemaVersion === null || $policySchemaVersion !== ($policy['schema_version'] ?? null)) {
        $errors[] = "Compiled artifact policy schema version mismatch: expected {$policy['schema_version']}, found " . json_encode($policySchemaVersion);
    }

    $expectedDigest = hash('sha256', file_get_contents($policyPath));
    $sourceDigest = $metadata['source_policy_digest'] ?? null;
    if ($sourceDigest === null) {
        $errors[] = "Compiled artifact missing source_policy_digest metadata.";
    } elseif ($sourceDigest !== $expectedDigest) {
        $errors[] = "Compiled artifact source policy digest mismatch. Please regenerate build/compiled-policy.json.";
    }

    if (empty($compiled['graph']['nodes']) || empty($compiled['graph']['edges'])) {
        $errors[] = "Compiled artifact graph is missing nodes or edges.";
    }

    $artifactDigest = $metadata['artifact_digest'] ?? null;
    if ($artifactDigest === null) {
        $errors[] = "Compiled artifact missing artifact_digest metadata.";
        return;
    }

    $validationPayload = [
        'metadata' => $metadata,
        'graph' => $compiled['graph'],
    ];
    unset($validationPayload['metadata']['artifact_digest']);

    $computedDigest = hash('sha256', json_encode($validationPayload, JSON_PRETTY_PRINT));
    if ($computedDigest !== $artifactDigest) {
        $errors[] = "Compiled artifact digest mismatch. Expected {$artifactDigest}, got {$computedDigest}.";
    }
}

function getBaselinePolicy(): array {
    // The "correct" baseline (what policy v1.0 should be)
    return [
        'version' => '1.0',
        'schema_version' => '1.0',
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
            'no_cms_imports' => ['severity' => 'warning'],
            'no_business_logic' => ['severity' => 'warning'],
            'require_rfc_for_control_plane' => ['severity' => 'warning']
        ],
        'deprecated_rules' => []
    ];
}

function parseSimpleYaml(string $yaml): array {
    $lines = explode("\n", $yaml);
    $result = [];
    $stack = [['ref' => &$result, 'indent' => -1]];
    
    foreach ($lines as $line) {
        // Strip inline comments (anything after # that's not in quotes)
        $line = preg_replace('/#.*$/', '', $line);
        
        // Skip empty lines and comments
        if (empty(trim($line)) || trim($line)[0] === '#') {
            continue;
        }
        
        // Calculate indentation
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);
        
        // Pop stack until we find parent at lower indentation
        while (count($stack) > 1 && $stack[count($stack) - 1]['indent'] >= $indent) {
            array_pop($stack);
        }
        
        // Get current container
        $current = &$stack[count($stack) - 1]['ref'];
        
        // Parse key: value pattern
        if (preg_match('/^([\w\-_]+):\s*(.*)$/i', $trimmed, $m)) {
            $key = $m[1];
            $val = trim($m[2]);
            
            if (empty($val)) {
                // Nested object
                $current[$key] = [];
                $stack[] = ['ref' => &$current[$key], 'indent' => $indent];
            } else {
                // Parse value
                $current[$key] = parseYamlValue($val);
            }
        } elseif (preg_match('/^-\s+(.+)$/i', $trimmed, $m)) {
            // Array item
            $current[] = parseYamlValue($m[1]);
        }
    }
    
    return $result;
}

function parseYamlValue(string $val): mixed {
    $val = trim($val);
    
    // Handle quotes
    if (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'")) {
        return substr($val, 1, -1);
    }
    
    // Handle booleans
    if ($val === 'true' || $val === 'yes' || $val === 'on') {
        return true;
    }
    if ($val === 'false' || $val === 'no' || $val === 'off') {
        return false;
    }
    
    // Handle numbers
    if (is_numeric($val)) {
        return (int)$val;
    }
    
    // Handle null
    if ($val === 'null' || $val === '~') {
        return null;
    }
    
    return $val;
}
