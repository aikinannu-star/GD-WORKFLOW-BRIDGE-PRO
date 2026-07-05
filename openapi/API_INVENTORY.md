# API Inventory — Phase 1.1A

This inventory captures every externally accessible endpoint in the current `services/marketplace/server.php` service.

## Inventory Map

### Health & Operational

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /health | None | No | Stable | None | `HealthResponse` | Basic service health probe |
| GET | /api/v1/risk-zones | None | No | Stable | None | `RiskZoneList` | Lists configured risk zone categories |
| GET | /api/v1/risk-zones/classify | None | No | Stable | Query: `health`, `volatility` | `RiskZone` | Predictive classification |
| GET | /api/v1/drift-analysis | None | No | Stable | Query: `metric`, `days_back`, `sort_by` | `DriftAnalysisResult` | Time-series drift analysis |
| GET | /api/v1/intelligence-health | None | No | Stable | None | `IntelligenceHealthResponse` | Platform intelligence health summary |

### Remediation

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| POST | /api/v1/remediation-events | Required | Yes | Stable | `RemediationEventRequest` | `RemediationEvent` | Record remediation recommendation/action |
| POST | /api/v1/remediation-events/{id}/resolve | Required | Yes | Stable | `RemediationResolveRequest` | `RemediationEvent` | Mark remediation resolved |

### Intelligence Effectiveness

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/intelligence-effectiveness/recommendations | Optional | No | Stable | None | `EffectivenessRecommendationsResponse` | Recommendation-level effectiveness |
| GET | /api/v1/intelligence-effectiveness/mttd | Optional | No | Stable | None | `MTTDResponse` | Mean time to detect |
| GET | /api/v1/intelligence-effectiveness/mttr | Optional | No | Stable | None | `MTTRResponse` | Mean time to remediate |
| GET | /api/v1/intelligence-effectiveness/acceptance-rate | Optional | No | Stable | None | `AcceptanceRateResponse` | Adoption of recommendations |
| GET | /api/v1/intelligence-effectiveness/accuracy | Optional | No | Stable | None | `AccuracyResponse` | Accuracy of recommendations |
| GET | /api/v1/intelligence-effectiveness | Optional | No | Stable | None | `IntelligenceEffectivenessSummary` | Composite effectiveness metrics |

### Intelligence Learning

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/intelligence-learning/performance | Optional | No | Stable | None | `LearningPerformance` | Measures recommendation performance |
| GET | /api/v1/intelligence-learning/adoption-gaps | Optional | No | Stable | None | `AdoptionGapsResponse` | Gap detection |
| GET | /api/v1/intelligence-learning/recurring-issues | Optional | No | Stable | None | `RecurringIssuesResponse` | Recurring issue detection |
| GET | /api/v1/intelligence-learning/trends | Optional | No | Stable | None | `LearningTrendsResponse` | Trend analysis |
| GET | /api/v1/intelligence-learning/effectiveness-score | Optional | No | Stable | None | `LearningEffectivenessScore` | Learning effectiveness metric |
| GET | /api/v1/intelligence-learning | Optional | No | Stable | None | `ConsolidatedLearningResponse` | Consolidated learning report |

### Marketplace Products

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/marketplace/products | Optional | Optional | Stable | Query: `tenant_id` | `ProductListResponse` | Product listing |
| GET | /api/v1/marketplace/products/{id} | Optional | Optional | Stable | None | `Product` | Product detail |
| POST | /api/v1/marketplace/products | Required | Optional | Stable | `ProductCreateRequest` | `Product` | Create marketplace product |
| POST | /api/v1/marketplace/products/{id}/purchase | Required | Optional | Stable | `PurchaseRequest` | `PurchaseResponse` | Simulated purchase endpoint |

### Marketplace Plugins

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/marketplace/plugins | Optional | Optional | Stable | Query: `tenant_id` | `PluginListResponse` | Plugin catalog |
| GET | /api/v1/marketplace/plugins/{id} | Optional | Optional | Stable | None | `Plugin` | Plugin detail |
| POST | /api/v1/marketplace/plugins | Required | Optional | Stable | `PluginCreateRequest` | `Plugin` | Create plugin metadata |
| PUT | /api/v1/marketplace/plugins/{id} | Required | Optional | Stable | `PluginUpdateRequest` | `Plugin` | Update plugin metadata |
| POST | /api/v1/marketplace/plugins/{id}/publish | Required | Optional | Stable | None | `Plugin` | Publish plugin |
| POST | /api/v1/marketplace/plugins/{id}/unpublish | Required | Optional | Stable | None | `Plugin` | Unpublish plugin |

### Plugin Keys

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/marketplace/plugins/{id}/keys | Optional | Optional | Stable | Query: `tenant_id` | `PluginKeyListResponse` | List plugin keys |
| GET | /api/v1/marketplace/plugins/{id}/keys/{keyId} | Optional | Optional | Stable | None | `PluginKey` | Key detail |
| POST | /api/v1/marketplace/plugins/{id}/keys | Required | Optional | Stable | `PluginKeyCreateRequest` | `PluginKey` | Create new public key |
| POST | /api/v1/marketplace/plugins/{id}/keys/{keyId}/revoke | Required | Optional | Stable | None | `PluginKey` | Revoke key |
| POST | /api/v1/marketplace/plugins/{id}/keys/{keyId}/activate | Required | Optional | Stable | None | `PluginKey` | Reactivate key |
| DELETE | /api/v1/marketplace/plugins/{id}/keys/{keyId} | Required | Optional | Stable | None | `DeleteResponse` | Delete key |

### Plugin Versions & Installs

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/marketplace/plugins/{id}/versions | Optional | Optional | Stable | None | `PluginVersionListResponse` | List plugin versions |
| GET | /api/v1/marketplace/plugins/{id}/versions/{version} | Optional | Optional | Stable | None | `PluginVersion` | Version detail |
| POST | /api/v1/marketplace/plugins/{id}/versions | Required | Optional | Stable | `PluginVersionCreateRequest` | `PluginVersion` | Publish new plugin version |
| GET | /api/v1/marketplace/plugins/{id}/installs | Optional | Optional | Stable | Query: `tenant_id` | `PluginInstallListResponse` | List installs |

### Snapshots

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| POST | /api/v1/marketplace/snapshots | Required | No | Stable | None | `SnapshotCreateResponse` | Create snapshot of current marketplace state |
| GET | /api/v1/marketplace/snapshots | Optional | No | Stable | None | `SnapshotListResponse` | List snapshots |

### Tenants & Platform Overview

| Method | Path | Auth | Tenant Scope | Status | Request | Response | Notes |
|---|---|---|---|---|---|---|---|
| GET | /api/v1/marketplace/tenants | Optional | No | Stable | None | `TenantListResponse` | List tenant IDs |
| GET | /api/v1/marketplace/platform/dashboard | Optional | No | Stable | Query: `refresh` | `PlatformDashboardResponse` | Cached overview data |
| GET | /api/v1/marketplace/platform/tenants-overview | Optional | No | Stable | Query: `refresh` | `PlatformTenantOverviewResponse` | Tenant-level overview |
| GET | /api/v1/marketplace/platform/rankings | Optional | No | Stable | None | `PlatformRankingResponse` | Health/rankings summary |
| GET | /api/v1/marketplace/platform/drift-summary | Optional | No | Stable | None | `PlatformDriftSummaryResponse` | Drift summary data |
| GET | /api/v1/marketplace/platform/timeseries | Optional | Optional | Stable | Query: `tenant_id`, `tenant_ids`, `metric`, `period`, `days_back`, `forecast_horizon` | `TimeSeriesResponse` | Time-series or tenant comparison |
| GET | /api/v1/marketplace/platform/overview | Optional | No | Stable | None | `PlatformOverviewResponse` | Health vs volatility matrix data |
| POST | /api/v1/marketplace/test/scenario | Optional | No | Experimental | `TestScenarioRequest` | `TestScenarioResponse` | Synthetic scenario generation and reset |
| GET | /api/v1/marketplace/installs | Optional | Optional | Stable | Query: `tenant_id` | `PluginInstallListResponse` | List installs across tenants |
| GET | /api/v1/marketplace/tenants/{id} | Optional | No | Stable | None | `TenantStatsResponse` | Tenant health and remediation stats |
| POST | /api/v1/marketplace/tenants/{id}/remediate/install-missing-deps | Required | Yes | Experimental | `TenantRemediationRequest` | `RemediationActionResponse` | Tenant-specific remediation action |

### UI / Static Routes (Non-API)

| Method | Path | Purpose | Status |
|---|---|---|---|
| GET | /operations-center | UI page | Internal |
| GET | /marketplace-ui | UI page | Internal |
| GET | /tenant-trend-timeline | UI page | Internal |
| GET | /drift-analysis-grid | UI page | Internal |
| GET | /risk-zones.js | JS asset | Internal |
| GET | /health-volatility-matrix | UI page | Internal |

## Initial Common Schema Library — Phase 1.1B

Recommended shared schema names for reuse in OpenAPI components:

- `HealthResponse`
- `ErrorResponse`
- `Tenant`
- `TenantStatsResponse`
- `Plugin`
- `PluginKey`
- `PluginVersion`
- `PluginInstall`
- `Product`
- `Snapshot`
- `Recommendation`
- `EffectivenessSummary`
- `LearningPerformance`
- `AdoptionGap`
- `RecurringIssue`
- `TrendPoint`
- `RiskZone`
- `DriftAnalysisResult`
- `IntelligenceHealthResponse`
- `PlatformDashboardResponse`
- `PlatformOverviewResponse`
- `TimeSeriesResponse`
- `RemediationEvent`
- `RemediationResolveRequest`
- `RemediationActionResponse`
- `TestScenarioRequest`
- `TestScenarioResponse`
- `PaginationResponse`

## Next step

1. Convert this inventory into a modular OpenAPI file structure under `openapi/paths/` and `openapi/components/` if further splitting is desired.
2. Refine the shared schema library in `openapi/components/schemas.yaml`.
3. Use `openapi/openapi.yaml` as the canonical contract for SDK generation and compatibility gating.
