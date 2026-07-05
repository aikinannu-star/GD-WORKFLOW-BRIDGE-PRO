<?php
/**
 * Check compiled policy artifact integrity and metadata compatibility.
 *
 * Usage:
 *   php tools/check-compiled-artifact.php
 */

$compiledPath = __DIR__ . '/../build/compiled-policy.json';
if (!file_exists($compiledPath)) {
    fwrite(STDERR, "ERROR: Compiled policy artifact not found: {$compiledPath}\n");
    exit(1);
}

$content = file_get_contents($compiledPath);
if ($content === false) {
    fwrite(STDERR, "ERROR: Failed to read compiled policy artifact: {$compiledPath}\n");
    exit(1);
}

$compiled = json_decode($content, true);
if (!is_array($compiled)) {
    fwrite(STDERR, "ERROR: Compiled policy artifact is invalid JSON: {$compiledPath}\n");
    exit(1);
}

$metadata = $compiled['metadata'] ?? null;
if (!is_array($metadata)) {
    fwrite(STDERR, "ERROR: Compiled policy artifact metadata missing or invalid.\n");
    exit(1);
}

if (empty($metadata['artifact_digest'])) {
    fwrite(STDERR, "ERROR: Compiled policy artifact missing artifact_digest.\n");
    exit(1);
}

$expectedDigest = $metadata['artifact_digest'];
$validationMetadata = $metadata;
unset($validationMetadata['artifact_digest']);

$validationPayload = [
    'metadata' => $validationMetadata,
    'graph' => $compiled['graph'] ?? null,
];

$computedDigest = hash('sha256', json_encode($validationPayload, JSON_PRETTY_PRINT));
if ($computedDigest !== $expectedDigest) {
    fwrite(STDERR, "ERROR: Compiled policy artifact digest mismatch. Expected {$expectedDigest}, got {$computedDigest}.\n");
    exit(1);
}

if (empty($compiled['graph']['nodes']) || empty($compiled['graph']['edges'])) {
    fwrite(STDERR, "ERROR: Compiled policy artifact graph is missing nodes or edges.\n");
    exit(1);
}

echo "PASS: compiled policy artifact integrity verified\n";
exit(0);
