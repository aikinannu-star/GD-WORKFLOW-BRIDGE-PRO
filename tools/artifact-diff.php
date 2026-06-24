<?php
// Compare two compiled policy artifacts and report structural differences.
// Usage: php tools/artifact-diff.php --old=build/compiled-policy.old.json --new=build/compiled-policy.json

$options = getopt('', ['old:', 'new:']);
$oldPath = $options['old'] ?? null;
$newPath = $options['new'] ?? null;
if (!$oldPath || !$newPath) {
    fwrite(STDERR, "Usage: php tools/artifact-diff.php --old=path --new=path\n");
    exit(2);
}
if (!file_exists($oldPath) || !file_exists($newPath)) {
    fwrite(STDERR, "ERROR: both files must exist.\n");
    exit(1);
}
$old = json_decode(file_get_contents($oldPath), true);
$new = json_decode(file_get_contents($newPath), true);
if (!$old || !$new) {
    fwrite(STDERR, "ERROR: invalid JSON in inputs.\n");
    exit(1);
}

$oldNodes = $old['graph']['nodes'] ?? [];
$newNodes = $new['graph']['nodes'] ?? [];
$oldEdges = $old['graph']['edges'] ?? [];
$newEdges = $new['graph']['edges'] ?? [];

$oldNodeIds = array_column($oldNodes, 'id');
$newNodeIds = array_column($newNodes, 'id');

$addedNodes = array_diff($newNodeIds, $oldNodeIds);
$removedNodes = array_diff($oldNodeIds, $newNodeIds);

$oldEdgeMap = array_map(function($e){ return json_encode($e); }, $oldEdges);
$newEdgeMap = array_map(function($e){ return json_encode($e); }, $newEdges);

$addedEdges = array_diff($newEdgeMap, $oldEdgeMap);
$removedEdges = array_diff($oldEdgeMap, $newEdgeMap);

echo "Artifact diff report:\n";
if ($addedNodes) echo "  Added nodes: " . implode(', ', $addedNodes) . "\n";
if ($removedNodes) echo "  Removed nodes: " . implode(', ', $removedNodes) . "\n";
if ($addedEdges) echo "  Added edges: \n    " . implode("\n    ", $addedEdges) . "\n";
if ($removedEdges) echo "  Removed edges: \n    " . implode("\n    ", $removedEdges) . "\n";
if (empty($addedNodes) && empty($removedNodes) && empty($addedEdges) && empty($removedEdges)) {
    echo "  No structural changes detected.\n";
}

exit(0);
