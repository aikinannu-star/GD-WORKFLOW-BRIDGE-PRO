<?php

/**
 * Semantic version comparison and constraint resolution
 * 
 * Supports:
 * - Exact versions: 1.2.3
 * - Ranges: >=1.0.0, >1.2.0, <=2.0.0, <2.0.0
 * - Tilde ranges: ~1.2.3 (allows patch-level changes: >=1.2.3, <1.3.0)
 * - Caret ranges: ^1.2.3 (allows minor and patch: >=1.2.3, <2.0.0)
 */
class SemanticVersionComparator
{
    /**
     * Parse semantic version into [major, minor, patch, prerelease, build]
     */
    public static function parse(string $version): array
    {
        $version = trim($version);

        // Extract build metadata
        $build = '';
        if (strpos($version, '+') !== false) {
            [$version, $build] = explode('+', $version, 2);
        }

        // Extract prerelease
        $prerelease = '';
        if (strpos($version, '-') !== false) {
            [$version, $prerelease] = explode('-', $version, 2);
        }

        // Parse major.minor.patch
        $parts = explode('.', $version);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            throw new \RuntimeException("Invalid semantic version: {$version}");
        }

        return [
            'major' => (int)$parts[0],
            'minor' => (int)$parts[1],
            'patch' => (int)$parts[2],
            'prerelease' => $prerelease,
            'build' => $build,
        ];
    }

    /**
     * Compare two versions: returns -1 (v1 < v2), 0 (equal), 1 (v1 > v2)
     */
    public static function compare(string $v1, string $v2): int
    {
        $p1 = self::parse($v1);
        $p2 = self::parse($v2);

        // Compare major.minor.patch
        if ($p1['major'] !== $p2['major']) {
            return $p1['major'] < $p2['major'] ? -1 : 1;
        }
        if ($p1['minor'] !== $p2['minor']) {
            return $p1['minor'] < $p2['minor'] ? -1 : 1;
        }
        if ($p1['patch'] !== $p2['patch']) {
            return $p1['patch'] < $p2['patch'] ? -1 : 1;
        }

        // Prerelease versions sort before release versions
        $hasPreV1 = !empty($p1['prerelease']);
        $hasPreV2 = !empty($p2['prerelease']);

        if ($hasPreV1 && !$hasPreV2) return -1;
        if (!$hasPreV1 && $hasPreV2) return 1;
        if ($hasPreV1 && $hasPreV2) {
            return strcmp($p1['prerelease'], $p2['prerelease']);
        }

        return 0;
    }

    /**
     * Check if version matches constraint
     * Examples: >=1.0.0, >1.2.0, <=2.0.0, <2.0.0, ~1.2.3, ^1.2.3, 1.2.3
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        // Tilde range: ~1.2.3 means >=1.2.3, <1.3.0
        if (strpos($constraint, '~') === 0) {
            $baseVersion = substr($constraint, 1);
            $base = self::parse($baseVersion);
            $parsed = self::parse($version);

            if ($parsed['major'] !== $base['major'] || $parsed['minor'] !== $base['minor']) {
                return false;
            }
            return $parsed['patch'] >= $base['patch'];
        }

        // Caret range: ^1.2.3 means >=1.2.3, <2.0.0
        if (strpos($constraint, '^') === 0) {
            $baseVersion = substr($constraint, 1);
            $base = self::parse($baseVersion);
            $parsed = self::parse($version);

            if ($parsed['major'] !== $base['major']) {
                return false;
            }
            if ($parsed['minor'] < $base['minor']) {
                return false;
            }
            if ($parsed['minor'] === $base['minor'] && $parsed['patch'] < $base['patch']) {
                return false;
            }
            return true;
        }

        // Range operators
        if (strpos($constraint, '>=') === 0) {
            return self::compare($version, substr($constraint, 2)) >= 0;
        }
        if (strpos($constraint, '>') === 0) {
            return self::compare($version, substr($constraint, 1)) > 0;
        }
        if (strpos($constraint, '<=') === 0) {
            return self::compare($version, substr($constraint, 2)) <= 0;
        }
        if (strpos($constraint, '<') === 0) {
            return self::compare($version, substr($constraint, 1)) < 0;
        }

        // Exact version
        return self::compare($version, $constraint) === 0;
    }

    /**
     * Check if a plugin version is compatible with runtime requirements
     */
    public static function isCompatible(string $runtimeVersion, string $minimumRequired): bool
    {
        return self::satisfies($runtimeVersion, ">=" . $minimumRequired);
    }
}
