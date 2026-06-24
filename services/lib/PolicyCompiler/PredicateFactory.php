<?php
declare(strict_types=1);

class PredicateFactory
{
    public static function createRulePredicate(string $ruleName, array $rule, array $policy): array
    {
        $scopePredicates = self::buildScopePredicates($rule, $policy);
        $contentPredicates = self::buildPatternPredicates($rule['patterns'] ?? []);

        if (!empty($scopePredicates) && !empty($contentPredicates)) {
            return [
                'type' => 'composed',
                'operator' => 'and',
                'children' => [
                    self::wrapOrPredicate($scopePredicates),
                    self::wrapOrPredicate($contentPredicates),
                ],
            ];
        }

        if (!empty($scopePredicates)) {
            return self::wrapOrPredicate($scopePredicates);
        }

        if (!empty($contentPredicates)) {
            return self::wrapOrPredicate($contentPredicates);
        }

        if (($rule['applies_to'] ?? '') === 'pr' || isset($rule['check']) || isset($rule['trigger'])) {
            return [
                'type' => 'meta',
                'check' => $rule['check'] ?? null,
                'trigger' => $rule['trigger'] ?? null,
                'description' => $rule['description'] ?? null,
            ];
        }

        return [
            'type' => 'unsupported',
            'description' => $rule['description'] ?? null,
        ];
    }

    /**
     * @param array<array{pattern:string,type?:string}> $patterns
     * @return array<int,array>
     */
    private static function buildPatternPredicates(array $patterns): array
    {
        $result = [];
        foreach ($patterns as $patternDef) {
            $pattern = $patternDef['pattern'] ?? null;
            $type = $patternDef['type'] ?? 'regex';

            if ($pattern === null) {
                continue;
            }

            if ($type === 'regex') {
                $result[] = [
                    'type' => 'regex',
                    'pattern' => $pattern,
                    'field' => 'content',
                    'flags' => 'i',
                ];
                continue;
            }

            if ($type === 'path_glob') {
                $result[] = [
                    'type' => 'path_glob',
                    'pattern' => $pattern,
                    'field' => 'filePath',
                ];
                continue;
            }

            $result[] = [
                'type' => 'regex',
                'pattern' => $pattern,
                'field' => 'content',
                'flags' => 'i',
            ];
        }

        return $result;
    }

    private static function buildScopePredicates(array $rule, array $policy): array
    {
        $predicates = [];
        $appliesTo = $rule['applies_to'] ?? '';

        if ($appliesTo === 'control_plane_files') {
            $paths = $policy['control_plane']['paths'] ?? [];
            foreach ($paths as $pathPattern) {
                $pattern = $pathPattern;
                if (str_ends_with($pathPattern, '/')) {
                    $pattern = rtrim($pathPattern, '/') . '/**';
                }

                $predicates[] = [
                    'type' => 'path_glob',
                    'pattern' => $pattern,
                    'field' => 'filePath',
                ];
            }
        }

        if (!empty($rule['requires_paths']) && is_array($rule['requires_paths'])) {
            foreach ($rule['requires_paths'] as $requiredPath) {
                $pattern = $requiredPath;
                if (str_ends_with($requiredPath, '/')) {
                    $pattern = rtrim($requiredPath, '/') . '/**';
                }

                $predicates[] = [
                    'type' => 'path_glob',
                    'pattern' => $pattern,
                    'field' => 'filePath',
                ];
            }
        }

        return $predicates;
    }

    private static function wrapOrPredicate(array $predicates): array
    {
        if (count($predicates) === 1) {
            return $predicates[0];
        }

        return [
            'type' => 'composed',
            'operator' => 'or',
            'children' => array_values($predicates),
        ];
    }
}
