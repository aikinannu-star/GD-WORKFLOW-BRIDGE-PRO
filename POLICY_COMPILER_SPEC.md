Policy Compiler Specification

Version: 0.1

Goal
----
Compile `CONTROL_PLANE_POLICY.yml` into a deterministic, typed, and versioned artifact (`build/compiled-policy.json`) that can be consumed by linters, evaluators, and runtime services.

Key Concepts
------------
- Rule: Named policy rule with severity, enabled flag, message, and predicate.
- Predicate: A typed, serializable condition (regex, AST pattern, path matcher) that can be evaluated deterministically at runtime.
- RuleGraph: Directed graph connecting policy metadata, rules, and path scopes.
- Violation: Typed output produced by evaluation: `id`, `rule`, `severity`, `message`, `remediation_hint`, `location`.
- Artifact: JSON file containing compiled nodes, edges and normalized predicates with metadata and a digest.

Artifact Schema (compiled-policy.json)
--------------------------------------
{
  "metadata": {
    "policy_version": "1.0",
    "policy_schema_version": "1.0",
    "artifact_version": "1.0",
    "compiler_version": "1.0",
    "compiled_at": "2026-06-23T...Z",
    "source_policy": "CONTROL_PLANE_POLICY.yml",
    "source_policy_digest": "<sha256>",
    "artifact_digest": "<sha256>"
  },
  "graph": {
    "nodes": [
      {"id":"rule:no_cms_imports", "type":"rule", "meta":{...}, "predicate":{...}},
      ...
    ],
    "edges": [ {"from":"policy:root","to":"rule:no_cms_imports","type":"contains"}, ... ]
  }
}

Predicate Model (initial)
-------------------------
- type: "regex" — fields: pattern (string), flags (string)
- type: "path_glob" — fields: pattern (string)
- type: "composed" — fields: operator (and|or|not), children ([predicates])

Example Rule
------------
- name: no_cms_imports
  severity: error
  enabled: true
  predicate:
    type: composed
    operator: and
    children:
      - type: path_glob
        pattern: "services/gateway/**"
      - type: regex
        pattern: "(include|require).*includes/"

Runtime API
-----------
PolicyEvaluator should accept either:
- a file path (it will load file content), or
- a context object: {filePath: string, content?: string, diff?: string}

It will return an array of `Violation` objects with methods:
- getId(): string
- getRule(): string
- getSeverity(): string
- getMessage(): string
- getRemediation(): ?string
- getLocation(): array (file, lineStart, lineEnd)
- toArray(): array

Compiler Responsibilities
-------------------------
- Normalize policy YAML into canonical structure
- Translate heuristics into serialized predicates
- Generate unique, stable IDs for rules
- Emit compiled artifact with metadata and digest

Evaluator Responsibilities
--------------------------
- Load compiled artifact
- Efficiently evaluate predicates
- Emit typed `Violation` objects

CI Integration
--------------
- Produce `build/compiled-policy.json` on push to main (artifact uploaded)
- Linter and simulator should consume the compiled artifact when present

Backwards Compatibility
-----------------------
- Store `metadata.policy_version`, `metadata.policy_schema_version`, `metadata.artifact_version`, and `metadata.compiler_version`
- Runtime evaluators must validate artifact compatibility before loading
- Unsupported artifact or schema versions should fail fast and fall back to the raw policy loader or reject execution
- Validator must detect incompatible changes in predicate model and prevent merge

Next steps (implementation order)
---------------------------------
1. Implement `Violation` value object
2. Extend `PolicyEvaluator` to return `Violation` objects
3. Implement basic predicate serialization for regex and path_glob
4. Update `PolicyCompiler` to emit predicate nodes
5. Add `tools/compile-policy.php` & CI workflow to upload artifact
6. Expand evaluator with AST-aware predicates

