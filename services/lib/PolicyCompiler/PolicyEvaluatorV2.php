<?php
declare(strict_types=1);

require_once __DIR__ . '/Violation.php';
require_once __DIR__ . '/PredicateEvaluator.php';

/**
 * PolicyEvaluatorV2
 *
 * Evaluates a compiled RuleGraph or compiled policy artifact against a file or content and returns typed Violation objects.
 */
class PolicyEvaluatorV2
{
    private const SUPPORTED_ARTIFACT_VERSIONS = ['1.0'];
    private const SUPPORTED_POLICY_SCHEMA_VERSIONS = ['1.0'];

    private array $compiledPolicy;
    private PredicateEvaluator $predicateEvaluator;

    public function __construct(array|RuleGraph $compiledPolicy)
    {
        $this->predicateEvaluator = new PredicateEvaluator();
        if ($compiledPolicy instanceof RuleGraph) {
            $this->compiledPolicy = ['graph' => $compiledPolicy->toArray()];
            return;
        }

        $this->compiledPolicy = $compiledPolicy;
    }

    public static function fromCompiledFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Compiled policy not found: {$path}");
        }

        $payload = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($payload)) {
            throw new RuntimeException("Invalid compiled policy artifact: {$path}");
        }

        self::assertCompiledArtifactCompatible($payload, $path);

        return new self($payload);
    }

    private static function assertCompiledArtifactCompatible(array $payload, string $path): void
    {
        $metadata = $payload['metadata'] ?? [];
        if (!is_array($metadata)) {
            throw new RuntimeException("Compiled policy artifact metadata missing or invalid: {$path}");
        }

        $artifactVersion = (string)($metadata['artifact_version'] ?? '');
        if (!in_array($artifactVersion, self::SUPPORTED_ARTIFACT_VERSIONS, true)) {
            throw new RuntimeException(
                "Unsupported compiled policy artifact version {$artifactVersion} in {$path}. " .
                "Supported versions: " . implode(', ', self::SUPPORTED_ARTIFACT_VERSIONS)
            );
        }

        $policySchemaVersion = (string)($metadata['policy_schema_version'] ?? $metadata['schema_version'] ?? '');
        if ($policySchemaVersion !== '' && !in_array($policySchemaVersion, self::SUPPORTED_POLICY_SCHEMA_VERSIONS, true)) {
            throw new RuntimeException(
                "Unsupported policy schema version {$policySchemaVersion} in compiled artifact {$path}. " .
                "Supported policy schema versions: " . implode(', ', self::SUPPORTED_POLICY_SCHEMA_VERSIONS)
            );
        }
    }

    /**
     * Evaluate a file path (or content via context) and return Violation objects.
     *
     * @param string $filePath
     * @param array $context Optional keys: content (string)
     * @return Violation[]
     */
    public function evaluateFile(string $filePath, array $context = []): array
    {
        $content = $context['content'] ?? (is_file($filePath) ? file_get_contents($filePath) : '');
        $context['filePath'] = $filePath;
        $context['content'] = $content;
        $violations = [];

        $nodes = $this->compiledPolicy['graph']['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'rule') {
                continue;
            }

            $meta = $node['meta'] ?? [];
            if (empty($meta['enabled'])) {
                continue;
            }

            $predicate = $meta['predicate'] ?? ['type' => 'unsupported'];
            if (!$this->predicateEvaluator->evaluate($predicate, $context)) {
                continue;
            }

            $ruleName = $meta['name'] ?? ($node['id'] ?? 'unknown');
            $severity = $meta['severity'] ?? 'warning';
            $message = $meta['message'] ?? 'Policy rule violated';

            $violations[] = new Violation(
                'violation:' . md5($filePath . $ruleName),
                $ruleName,
                $severity,
                $message,
                null,
                ['file' => $filePath]
            );
        }

        return $violations;
    }
}
