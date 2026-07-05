Control Plane Service

Run locally:

```bash
php -S 127.0.0.1:8080 -t services/control-plane
```

Endpoints:

- GET `/status` — service health and artifact presence
- GET `/health` — lightweight health check endpoint (returns HTTP 503 on degraded state)
- GET `/metrics` — Prometheus-format metrics for artifact reloads, evaluations, and signature verifications
- POST `/evaluate` — accepts JSON body and evaluates a compiled policy artifact using `PolicyEvaluatorV2`

Example `POST /evaluate` request body:

```json
{
  "filePath": "dummy.txt",
  "content": "any content"
}
```

Example response:

```json
{
  "result": "evaluated",
  "violations": [
    {
      "id": "violation:...",
      "rule": "always-violate",
      "severity": "error",
      "message": "Test violation",
      "remediation": null,
      "location": {
        "file": "dummy.txt"
      }
    }
  ],
  "count": 1,
  "artifact": "compiled-policy.json"
}
```

Example `GET /metrics` response (Prometheus format):

```
# HELP gdwb_artifact_reloads_total Total number of artifact reloads detected
# TYPE gdwb_artifact_reloads_total counter
gdwb_artifact_reloads_total 5

# HELP gdwb_evaluations_total Total number of policy evaluations performed
# TYPE gdwb_evaluations_total counter
gdwb_evaluations_total 42

# HELP gdwb_signature_verifications_total Total number of signature verifications attempted
# TYPE gdwb_signature_verifications_total counter
gdwb_signature_verifications_total 10

# HELP gdwb_signature_failures_total Total number of failed signature verifications
# TYPE gdwb_signature_failures_total counter
gdwb_signature_failures_total 0

# HELP gdwb_last_reload_time Unix timestamp of last artifact reload
# TYPE gdwb_last_reload_time gauge
gdwb_last_reload_time 1719216000000

# HELP gdwb_artifact_version Artifact version
# TYPE gdwb_artifact_version gauge
gdwb_artifact_version{version="1.0"} 1

# HELP gdwb_policy_schema_version Policy schema version
# TYPE gdwb_policy_schema_version gauge
gdwb_policy_schema_version{version="1.0"} 1
```

Architecture:

- `ArtifactManager` handles artifact loading with automatic change detection (reload-on-change).
- `PolicyEvaluatorV2` evaluates compiled policy predicates against file paths and content.
- Metrics are tracked across service lifetime and exposed in Prometheus text format.
- Health checks verify both artifact presence and evaluator availability, degrading to 503 when needed.
