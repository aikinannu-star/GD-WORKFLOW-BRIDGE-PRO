// Auto-generated API client from OpenAPI specification
// All 62 operations mapped from canonical contract
import axios, { AxiosInstance, AxiosRequestConfig } from 'axios';
import * as types from './types';

export interface ApiClientConfig {
  basePath?: string;
  accessToken?: string;
  timeout?: number;
}

export class ApiClient {
  private client: AxiosInstance;
  private basePath: string;

  constructor(config: ApiClientConfig = {}) {
    this.basePath = config.basePath || 'https://api.example.com';
    
    this.client = axios.create({
      baseURL: this.basePath,
      timeout: config.timeout || 30000,
      headers: {
        'Content-Type': 'application/json',
        ...(config.accessToken ? { 'Authorization': `Bearer ${config.accessToken}` } : {})
      }
    });
  }

  // Generated API methods from OpenAPI operations

  /**
   * Compute drift analysis across tenants
   * @maturity beta
   * @method GET
   * @path /api/v1/drift-analysis
   */
  async driftanalysisListDriftanalysis(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/drift-analysis", params, config);
  }

  /**
   * Service health probe
   * @maturity stable
   * @method GET
   * @path /health
   */
  async healthList(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/health", params, config);
  }

  /**
   * Comprehensive intelligence effectiveness summary
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness
   */
  async intelligenceListIntelligenceeffectiveness(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness", params, config);
  }

  /**
   * Recommendation acceptance rate
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness/acceptance-rate
   */
  async intelligenceListIntelligenceeffectivenessAcceptancerate(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness/acceptance-rate", params, config);
  }

  /**
   * Recommendation accuracy metric
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness/accuracy
   */
  async intelligenceListIntelligenceeffectivenessAccuracy(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness/accuracy", params, config);
  }

  /**
   * Mean time to detect (MTTD)
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness/mttd
   */
  async intelligenceListIntelligenceeffectivenessMttd(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness/mttd", params, config);
  }

  /**
   * Mean time to remediate (MTTR)
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness/mttr
   */
  async intelligenceListIntelligenceeffectivenessMttr(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness/mttr", params, config);
  }

  /**
   * Recommendation effectiveness metrics
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-effectiveness/recommendations
   */
  async intelligenceListIntelligenceeffectivenessRecommendations(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-effectiveness/recommendations", params, config);
  }

  /**
   * Intelligence health and anomaly summary
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-health
   */
  async intelligenceListIntelligencehealth(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-health", params, config);
  }

  /**
   * Consolidated intelligence learning report
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning
   */
  async intelligenceListIntelligencelearning(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning", params, config);
  }

  /**
   * Recommendation adoption gaps
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning/adoption-gaps
   */
  async intelligenceListIntelligencelearningAdoptiongaps(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning/adoption-gaps", params, config);
  }

  /**
   * Intelligence effectiveness score
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning/effectiveness-score
   */
  async intelligenceListIntelligencelearningEffectivenessscore(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning/effectiveness-score", params, config);
  }

  /**
   * Learning performance metrics
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning/performance
   */
  async intelligenceListIntelligencelearningPerformance(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning/performance", params, config);
  }

  /**
   * Recurring issue detection
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning/recurring-issues
   */
  async intelligenceListIntelligencelearningRecurringissues(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning/recurring-issues", params, config);
  }

  /**
   * Intelligence improvement trends
   * @maturity beta
   * @method GET
   * @path /api/v1/intelligence-learning/trends
   */
  async intelligenceListIntelligencelearningTrends(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/intelligence-learning/trends", params, config);
  }

  /**
   * Create a plugin metadata record
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins
   */
  async marketplaceCreateMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins", params, config);
  }

  /**
   * Install a plugin for a tenant
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/install
   */
  async marketplaceCreateMarketplacePluginsInstall(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/install", params, config);
  }

  /**
   * Register a new plugin key
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/keys
   */
  async marketplaceCreateMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/keys", params, config);
  }

  /**
   * Reactivate a revoked plugin key
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/activate
   */
  async marketplaceCreateMarketplacePluginsKeysActivate(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/activate", params, config);
  }

  /**
   * Revoke a plugin key
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/revoke
   */
  async marketplaceCreateMarketplacePluginsKeysRevoke(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/revoke", params, config);
  }

  /**
   * Publish a plugin
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/publish
   */
  async marketplaceCreateMarketplacePluginsPublish(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/publish", params, config);
  }

  /**
   * Submit a plugin rating
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/ratings
   */
  async marketplaceCreateMarketplacePluginsRatings(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/ratings", params, config);
  }

  /**
   * Uninstall a plugin for a tenant
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/uninstall
   */
  async marketplaceCreateMarketplacePluginsUninstall(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/uninstall", params, config);
  }

  /**
   * Unpublish a plugin
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/unpublish
   */
  async marketplaceCreateMarketplacePluginsUnpublish(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/unpublish", params, config);
  }

  /**
   * Publish a new plugin version
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/versions
   */
  async marketplaceCreateMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/versions", params, config);
  }

  /**
   * Upload a plugin artifact for a version
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact
   */
  async marketplaceCreateMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact", params, config);
  }

  /**
   * Create a marketplace product
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/products
   */
  async marketplaceCreateMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/products", params, config);
  }

  /**
   * Simulate product purchase
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/products/{productId}/purchase
   */
  async marketplaceCreateMarketplaceProductsPurchase(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/products/{productId}/purchase", params, config);
  }

  /**
   * Create a marketplace snapshot
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/snapshots
   */
  async marketplaceCreateMarketplaceSnapshots(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/snapshots", params, config);
  }

  /**
   * Activate revoked keys for a tenant
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/tenants/{tenantId}/remediate/activate-keys
   */
  async marketplaceCreateMarketplaceTenantsRemediateActivatekeys(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/tenants/{tenantId}/remediate/activate-keys", params, config);
  }

  /**
   * Install missing dependencies for a tenant
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/tenants/{tenantId}/remediate/install-missing-deps
   */
  async marketplaceCreateMarketplaceTenantsRemediateInstallmissingdeps(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/tenants/{tenantId}/remediate/install-missing-deps", params, config);
  }

  /**
   * Preview a remediation action for a tenant
   * @maturity stable
   * @method POST
   * @path /api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview
   */
  async marketplaceCreateMarketplaceTenantsRemediatePreview(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview", params, config);
  }

  /**
   * Delete a plugin key
   * @maturity stable
   * @method DELETE
   * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}
   */
  async marketplaceDeleteMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.delete("/api/v1/marketplace/plugins/{pluginId}/keys/{keyId}", params, config);
  }

  /**
   * Get plugin metadata
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}
   */
  async marketplaceGetMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}", params, config);
  }

  /**
   * List installs for a plugin
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/installs
   */
  async marketplaceGetMarketplacePluginsInstalls(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/installs", params, config);
  }

  /**
   * Get a plugin key by ID
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}
   */
  async marketplaceGetMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/keys/{keyId}", params, config);
  }

  /**
   * List plugin ratings
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/ratings
   */
  async marketplaceGetMarketplacePluginsRatings(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/ratings", params, config);
  }

  /**
   * Get plugin version detail by version identifier or ID
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/versions/{identifier}
   */
  async marketplaceGetMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/versions/{identifier}", params, config);
  }

  /**
   * Download plugin artifact metadata and base64 payload
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact/{artifactId}
   */
  async marketplaceGetMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact/{artifactId}", params, config);
  }

  /**
   * Get product by ID
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/products/{productId}
   */
  async marketplaceGetMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/products/{productId}", params, config);
  }

  /**
   * Get tenant health and stats
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/tenants/{tenantId}
   */
  async marketplaceGetMarketplaceTenants(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/tenants/{tenantId}", params, config);
  }

  /**
   * Tenant trend analysis
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/tenants/{tenantId}/trends
   */
  async marketplaceGetMarketplaceTenantsTrends(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/tenants/{tenantId}/trends", params, config);
  }

  /**
   * List installs across tenants
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/installs
   */
  async marketplaceListMarketplaceInstalls(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/installs", params, config);
  }

  /**
   * List plugins
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins
   */
  async marketplaceListMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins", params, config);
  }

  /**
   * List plugin signing keys
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/keys
   */
  async marketplaceListMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/keys", params, config);
  }

  /**
   * List plugin versions
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/versions
   */
  async marketplaceListMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/versions", params, config);
  }

  /**
   * List plugin artifacts for a version
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact
   */
  async marketplaceListMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact", params, config);
  }

  /**
   * List marketplace products
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/products
   */
  async marketplaceListMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/products", params, config);
  }

  /**
   * List saved marketplace snapshots
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/snapshots
   */
  async marketplaceListMarketplaceSnapshots(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/snapshots", params, config);
  }

  /**
   * List tenant IDs
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/tenants
   */
  async marketplaceListMarketplaceTenants(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/tenants", params, config);
  }

  /**
   * Update plugin metadata
   * @maturity stable
   * @method PUT
   * @path /api/v1/marketplace/plugins/{pluginId}
   */
  async marketplaceUpdateMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.put("/api/v1/marketplace/plugins/{pluginId}", params, config);
  }

  /**
   * Platform dashboard overview
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/dashboard
   */
  async platformListMarketplacePlatformDashboard(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/dashboard", params, config);
  }

  /**
   * Platform drift summary
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/drift-summary
   */
  async platformListMarketplacePlatformDriftsummary(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/drift-summary", params, config);
  }

  /**
   * Health vs volatility overview
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/overview
   */
  async platformListMarketplacePlatformOverview(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/overview", params, config);
  }

  /**
   * Platform ranking reports
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/rankings
   */
  async platformListMarketplacePlatformRankings(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/rankings", params, config);
  }

  /**
   * Platform tenants overview
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/tenants-overview
   */
  async platformListMarketplacePlatformTenantsoverview(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/tenants-overview", params, config);
  }

  /**
   * Platform time-series data
   * @maturity stable
   * @method GET
   * @path /api/v1/marketplace/platform/timeseries
   */
  async platformListMarketplacePlatformTimeseries(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/marketplace/platform/timeseries", params, config);
  }

  /**
   * Record a remediation recommendation or action
   * @maturity stable
   * @method POST
   * @path /api/v1/remediation-events
   */
  async remediationCreateRemediationevents(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/remediation-events", params, config);
  }

  /**
   * Mark a remediation event as resolved
   * @maturity stable
   * @method POST
   * @path /api/v1/remediation-events/{eventId}/resolve
   */
  async remediationCreateRemediationeventsResolve(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/remediation-events/{eventId}/resolve", params, config);
  }

  /**
   * List configured risk zones
   * @maturity beta
   * @method GET
   * @path /api/v1/risk-zones
   */
  async riskzonesListRiskzones(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/risk-zones", params, config);
  }

  /**
   * Classify health and volatility into a risk zone
   * @maturity beta
   * @method GET
   * @path /api/v1/risk-zones/classify
   */
  async riskzonesListRiskzonesClassify(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.get("/api/v1/risk-zones/classify", params, config);
  }

  /**
   * Create or reset a synthetic test scenario
   * @maturity experimental
   * @method POST
   * @path /api/v1/marketplace/test/scenario
   */
  async testingCreateMarketplaceTestScenario(params?: any, config?: AxiosRequestConfig): Promise<any> {
    return this.client.post("/api/v1/marketplace/test/scenario", params, config);
  }

}
