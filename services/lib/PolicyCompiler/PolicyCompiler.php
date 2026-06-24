<?php
declare(strict_types=1);

require_once __DIR__ . '/PredicateFactory.php';

/**
 * PolicyCompiler
 *
 * Loads a YAML policy and compiles it into a RuleGraph JSON artifact.
 */
class PolicyCompiler
{
    private const ARTIFACT_VERSION = '1.0';
    private const COMPILER_VERSION = '1.0';

    private string $policyPath;
    private array $policy = [];
    private string $policyHash = '';

    public function __construct(string $policyPath)
    {
        $this->policyPath = $policyPath;
    }

    /**
     * Load policy from YAML file (uses yaml extension if available, otherwise a simple parser)
     * @throws RuntimeException
     */
    public function loadPolicy(): void
    {
        if (!file_exists($this->policyPath)) {
            throw new RuntimeException("Policy file not found: {$this->policyPath}");
        }

        $content = file_get_contents($this->policyPath);
        $this->policyHash = $content !== false ? hash('sha256', $content) : '';

        if (function_exists('yaml_parse')) {
            $parsed = yaml_parse($content);
        } else {
            $parsed = $this->parseSimpleYaml($content);
        }

        if (!is_array($parsed)) {
            throw new RuntimeException("Failed to parse policy file: {$this->policyPath}");
        }

        $this->policy = $parsed;
    }

    /**
     * Compile loaded policy into a RuleGraph object
     */
    public function compile(): RuleGraph
    {
        $graph = new RuleGraph();

        // Add policy metadata node
        $graph->addNode('policy:root', 'policy', [
            'version' => $this->policy['version'] ?? null,
            'schema_version' => $this->policy['schema_version'] ?? null,
        ]);

        // Add control plane paths as nodes
        $paths = $this->policy['control_plane']['paths'] ?? [];
        foreach ($paths as $i => $p) {
            $nodeId = "path:{$i}";
            $graph->addNode($nodeId, 'path', ['pattern' => $p]);
            $graph->addEdge('policy:root', $nodeId, 'contains');
        }

        // Add rules
        $rules = $this->policy['rules'] ?? [];
        foreach ($rules as $name => $rule) {
            $nodeId = "rule:{$name}";
            $predicate = PredicateFactory::createRulePredicate($name, $rule, $this->policy);

            $graph->addNode($nodeId, 'rule', [
                'name' => $name,
                'severity' => $rule['severity'] ?? 'warning',
                'enabled' => (bool)($rule['enabled'] ?? false),
                'message' => $rule['message'] ?? '',
                'predicate' => $predicate,
                'applies_to' => $rule['applies_to'] ?? null,
                'patterns' => $rule['patterns'] ?? [],
            ]);
            $graph->addEdge('policy:root', $nodeId, 'contains');
        }

        return $graph;
    }

    /**
     * Save compiled graph as structured JSON artifact.
     */
    public function saveCompiled(RuleGraph $graph, string $outPath): void
    {
        $metadata = [
            'policy_version' => $this->policy['version'] ?? null,
            'policy_schema_version' => $this->policy['schema_version'] ?? null,
            'artifact_version' => self::ARTIFACT_VERSION,
            'compiler_version' => self::COMPILER_VERSION,
            'compiled_at' => gmdate('c'),
            'source_policy' => basename($this->policyPath),
            'source_policy_digest' => $this->policyHash,
        ];

        $payload = [
            'metadata' => $metadata,
            'graph' => $graph->toArray(),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('Failed to serialize compiled graph');
        }

        $metadata['artifact_digest'] = hash('sha256', $encoded);
        $payload['metadata'] = $metadata;

        $data = json_encode($payload, JSON_PRETTY_PRINT);
        if ($data === false) {
            throw new RuntimeException('Failed to serialize compiled graph');
        }

        file_put_contents($outPath, $data);
    }

    // Simple YAML parser (fallback). Copied/adapted for minimal policy needs.
    private function parseSimpleYaml(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [['ref' => &$result, 'indent' => -1]];

        foreach ($lines as $line) {
            $line = preg_replace('/#.*$/', '', $line);
            if (trim($line) === '') continue;

            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);

            while (count($stack) > 1 && $stack[count($stack) - 1]['indent'] >= $indent) {
                array_pop($stack);
            }

            $current = &$stack[count($stack) - 1]['ref'];

            if (preg_match('/^([\w\-\._]+):\s*(.*)$/', $trimmed, $m)) {
                $key = $m[1];
                $val = trim($m[2]);
                if ($val === '') {
                    $current[$key] = [];
                    $stack[] = ['ref' => &$current[$key], 'indent' => $indent];
                } else {
                    $current[$key] = $this->parseYamlValue($val);
                }
            } elseif (preg_match('/^-\s*([\w\-\._]+):\s*(.*)$/', $trimmed, $m)) {
                $key = $m[1];
                $val = trim($m[2]);
                $item = [];
                if ($val === '') {
                    $item[$key] = [];
                    $current[] = $item;
                    $stack[] = ['ref' => &$current[count($current) - 1][$key], 'indent' => $indent];
                } else {
                    $item[$key] = $this->parseYamlValue($val);
                    $current[] = $item;
                    $stack[] = ['ref' => &$current[count($current) - 1], 'indent' => $indent];
                }
            } elseif (preg_match('/^-\s*(.*)$/', $trimmed, $m)) {
                $current[] = $this->parseYamlValue($m[1]);
            }
        }

        return $result;
    }

    private function parseYamlValue(string $val)
    {
        $val = trim($val);
        if ($val === 'true') return true;
        if ($val === 'false') return false;
        if ($val === 'null' || $val === '~') return null;
        if (is_numeric($val)) return (int)$val;
        return trim($val, "'\"");
    }
}
