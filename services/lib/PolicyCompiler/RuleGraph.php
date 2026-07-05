<?php
declare(strict_types=1);

/**
 * RuleGraph
 *
 * Simple in-memory directed graph representing policy rules and their relationships.
 */
class RuleGraph
{
    /** @var array<string,array> */
    private array $nodes = [];

    /** @var array<int,array> */
    private array $edges = [];

    public function addNode(string $id, string $type, array $meta = []): void
    {
        if (isset($this->nodes[$id])) {
            return;
        }
        $this->nodes[$id] = [
            'id' => $id,
            'type' => $type,
            'meta' => $meta,
        ];
    }

    public function getNode(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function addEdge(string $from, string $to, string $type = 'rel'): void
    {
        $this->edges[] = [
            'from' => $from,
            'to' => $to,
            'type' => $type,
        ];
    }

    public function toArray(): array
    {
        return [
            'nodes' => array_values($this->nodes),
            'edges' => $this->edges,
        ];
    }

    public static function fromArray(array $data): RuleGraph
    {
        $g = new RuleGraph();
        foreach ($data['nodes'] ?? [] as $node) {
            $g->nodes[$node['id']] = $node;
        }
        foreach ($data['edges'] ?? [] as $edge) {
            $g->edges[] = $edge;
        }
        return $g;
    }
}
