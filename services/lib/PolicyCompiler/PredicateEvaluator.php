<?php
declare(strict_types=1);

class PredicateEvaluator
{
    public function evaluate(array $predicate, array $context): bool
    {
        $type = $predicate['type'] ?? '';

        switch ($type) {
            case 'regex':
                return $this->evaluateRegex($predicate, $context);
            case 'path_glob':
                return $this->evaluatePathGlob($predicate, $context);
            case 'composed':
                return $this->evaluateComposed($predicate, $context);
            case 'always':
                return true;
            case 'meta':
                return $this->evaluateMetaPredicate($predicate, $context);
            case 'unsupported':
            default:
                return false;
        }
    }

    private function evaluateRegex(array $predicate, array $context): bool
    {
        $field = $predicate['field'] ?? 'content';
        $pattern = $predicate['pattern'] ?? '';
        $flags = $predicate['flags'] ?? 'i';
        $value = $this->getContextValue($field, $context);

        if ($value === null || $pattern === '') {
            return false;
        }

        $delimiter = '/';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        $regex = $delimiter . $escaped . $delimiter . $flags;

        return @preg_match($regex, $value) === 1;
    }

    private function evaluatePathGlob(array $predicate, array $context): bool
    {
        $field = $predicate['field'] ?? 'filePath';
        $pattern = $predicate['pattern'] ?? '';
        $value = $this->getContextValue($field, $context);

        if ($value === null || $pattern === '') {
            return false;
        }

        return $this->globMatches($pattern, $value);
    }

    private function evaluateComposed(array $predicate, array $context): bool
    {
        $operator = strtolower($predicate['operator'] ?? 'and');
        $children = $predicate['children'] ?? [];

        if (!is_array($children) || count($children) === 0) {
            return false;
        }

        if ($operator === 'or') {
            foreach ($children as $child) {
                if ($this->evaluate($child, $context)) {
                    return true;
                }
            }
            return false;
        }

        if ($operator === 'not') {
            return !$this->evaluate($children[0], $context);
        }

        foreach ($children as $child) {
            if (!$this->evaluate($child, $context)) {
                return false;
            }
        }

        return true;
    }

    private function getContextValue(string $field, array $context): ?string
    {
        $value = $context[$field] ?? null;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function globMatches(string $pattern, string $value): bool
    {
        $pattern = str_replace('\\', '/', $pattern);
        $value = str_replace('\\', '/', $value);

        if (substr($pattern, -1) === '/') {
            $pattern = rtrim($pattern, '/') . '/**';
        }

        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\\*\\*', '.*', $regex);
        $regex = str_replace('\\*', '[^/]*', $regex);
        $regex = str_replace('\\?', '.', $regex);

        return preg_match('/^' . $regex . '$/i', $value) === 1;
    }

    private function evaluateMetaPredicate(array $predicate, array $context): bool
    {
        $trigger = $predicate['trigger'] ?? null;
        $check = $predicate['check'] ?? null;

        if ($trigger !== null && isset($context['trigger']) && $context['trigger'] === $trigger) {
            if ($check === null) {
                return true;
            }
            return isset($context['check_results'][$check]) && $context['check_results'][$check] === true;
        }

        return false;
    }
}
