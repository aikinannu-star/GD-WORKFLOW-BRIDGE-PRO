<?php
// Simple artifact compatibility checker
// Usage: php tools/artifact-compatibility-check.php --artifact=build/compiled-policy.json --min-artifact-version=1.0 --min-policy-schema-version=1.0

$options = getopt('', ['artifact:', 'min-artifact-version::', 'min-policy-schema-version::']);
$artifactPath = $options['artifact'] ?? __DIR__ . '/../build/compiled-policy.json';

if (!file_exists($artifactPath)) {
    fwrite(STDERR, "ERROR: Artifact not found: {$artifactPath}\n");
    exit(1);
}

$raw = file_get_contents($artifactPath);
$artifact = json_decode($raw, true);
if (!is_array($artifact)) {
    fwrite(STDERR, "ERROR: Invalid artifact JSON: {$artifactPath}\n");
    exit(1);
}

$metadata = $artifact['metadata'] ?? [];
if (empty($metadata['artifact_version']) || empty($metadata['policy_schema_version'])) {
    fwrite(STDERR, "ERROR: artifact_version or policy_schema_version missing in metadata.\n");
    exit(1);
}

function semverCompare($a, $b) {
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    $len = max(count($pa), count($pb));
    for ($i=0;$i<$len;$i++) {
        $va = $pa[$i] ?? 0; $vb = $pb[$i] ?? 0;
        if ($va < $vb) return -1;
        if ($va > $vb) return 1;
    }
    return 0;
}

$minArtifact = $options['min-artifact-version'] ?? null;
$minSchema = $options['min-policy-schema-version'] ?? null;
$fail = false;

if ($minArtifact !== null) {
    if (semverCompare($metadata['artifact_version'], $minArtifact) < 0) {
        fwrite(STDERR, "ERROR: artifact_version {$metadata['artifact_version']} < required {$minArtifact}\n");
        $fail = true;
    }
}
if ($minSchema !== null) {
    if (semverCompare($metadata['policy_schema_version'], $minSchema) < 0) {
        fwrite(STDERR, "ERROR: policy_schema_version {$metadata['policy_schema_version']} < required {$minSchema}\n");
        $fail = true;
    }
}

if ($fail) exit(2);
echo "PASS: artifact compatibility checks OK\n";
exit(0);
