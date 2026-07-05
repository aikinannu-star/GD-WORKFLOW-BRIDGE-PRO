import { AxiosRequestConfig } from 'axios';
export interface ApiClientConfig {
    basePath?: string;
    accessToken?: string;
    timeout?: number;
}
export declare class ApiClient {
    private client;
    private basePath;
    constructor(config?: ApiClientConfig);
    /**
     * Compute drift analysis across tenants
     * @maturity beta
     * @method GET
     * @path /api/v1/drift-analysis
     */
    driftanalysisListDriftanalysis(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Service health probe
     * @maturity stable
     * @method GET
     * @path /health
     */
    healthList(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Comprehensive intelligence effectiveness summary
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness
     */
    intelligenceListIntelligenceeffectiveness(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Recommendation acceptance rate
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness/acceptance-rate
     */
    intelligenceListIntelligenceeffectivenessAcceptancerate(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Recommendation accuracy metric
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness/accuracy
     */
    intelligenceListIntelligenceeffectivenessAccuracy(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Mean time to detect (MTTD)
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness/mttd
     */
    intelligenceListIntelligenceeffectivenessMttd(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Mean time to remediate (MTTR)
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness/mttr
     */
    intelligenceListIntelligenceeffectivenessMttr(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Recommendation effectiveness metrics
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-effectiveness/recommendations
     */
    intelligenceListIntelligenceeffectivenessRecommendations(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Intelligence health and anomaly summary
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-health
     */
    intelligenceListIntelligencehealth(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Consolidated intelligence learning report
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning
     */
    intelligenceListIntelligencelearning(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Recommendation adoption gaps
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning/adoption-gaps
     */
    intelligenceListIntelligencelearningAdoptiongaps(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Intelligence effectiveness score
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning/effectiveness-score
     */
    intelligenceListIntelligencelearningEffectivenessscore(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Learning performance metrics
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning/performance
     */
    intelligenceListIntelligencelearningPerformance(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Recurring issue detection
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning/recurring-issues
     */
    intelligenceListIntelligencelearningRecurringissues(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Intelligence improvement trends
     * @maturity beta
     * @method GET
     * @path /api/v1/intelligence-learning/trends
     */
    intelligenceListIntelligencelearningTrends(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Create a plugin metadata record
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins
     */
    marketplaceCreateMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Install a plugin for a tenant
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/install
     */
    marketplaceCreateMarketplacePluginsInstall(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Register a new plugin key
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/keys
     */
    marketplaceCreateMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Reactivate a revoked plugin key
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/activate
     */
    marketplaceCreateMarketplacePluginsKeysActivate(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Revoke a plugin key
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}/revoke
     */
    marketplaceCreateMarketplacePluginsKeysRevoke(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Publish a plugin
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/publish
     */
    marketplaceCreateMarketplacePluginsPublish(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Submit a plugin rating
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/ratings
     */
    marketplaceCreateMarketplacePluginsRatings(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Uninstall a plugin for a tenant
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/uninstall
     */
    marketplaceCreateMarketplacePluginsUninstall(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Unpublish a plugin
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/unpublish
     */
    marketplaceCreateMarketplacePluginsUnpublish(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Publish a new plugin version
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/versions
     */
    marketplaceCreateMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Upload a plugin artifact for a version
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact
     */
    marketplaceCreateMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Create a marketplace product
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/products
     */
    marketplaceCreateMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Simulate product purchase
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/products/{productId}/purchase
     */
    marketplaceCreateMarketplaceProductsPurchase(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Create a marketplace snapshot
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/snapshots
     */
    marketplaceCreateMarketplaceSnapshots(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Activate revoked keys for a tenant
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/tenants/{tenantId}/remediate/activate-keys
     */
    marketplaceCreateMarketplaceTenantsRemediateActivatekeys(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Install missing dependencies for a tenant
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/tenants/{tenantId}/remediate/install-missing-deps
     */
    marketplaceCreateMarketplaceTenantsRemediateInstallmissingdeps(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Preview a remediation action for a tenant
     * @maturity stable
     * @method POST
     * @path /api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview
     */
    marketplaceCreateMarketplaceTenantsRemediatePreview(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Delete a plugin key
     * @maturity stable
     * @method DELETE
     * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}
     */
    marketplaceDeleteMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Get plugin metadata
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}
     */
    marketplaceGetMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List installs for a plugin
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/installs
     */
    marketplaceGetMarketplacePluginsInstalls(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Get a plugin key by ID
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/keys/{keyId}
     */
    marketplaceGetMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List plugin ratings
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/ratings
     */
    marketplaceGetMarketplacePluginsRatings(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Get plugin version detail by version identifier or ID
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/versions/{identifier}
     */
    marketplaceGetMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Download plugin artifact metadata and base64 payload
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact/{artifactId}
     */
    marketplaceGetMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Get product by ID
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/products/{productId}
     */
    marketplaceGetMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Get tenant health and stats
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/tenants/{tenantId}
     */
    marketplaceGetMarketplaceTenants(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Tenant trend analysis
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/tenants/{tenantId}/trends
     */
    marketplaceGetMarketplaceTenantsTrends(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List installs across tenants
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/installs
     */
    marketplaceListMarketplaceInstalls(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List plugins
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins
     */
    marketplaceListMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List plugin signing keys
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/keys
     */
    marketplaceListMarketplacePluginsKeys(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List plugin versions
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/versions
     */
    marketplaceListMarketplacePluginsVersions(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List plugin artifacts for a version
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/plugins/{pluginId}/versions/{version}/artifact
     */
    marketplaceListMarketplacePluginsVersionsArtifact(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List marketplace products
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/products
     */
    marketplaceListMarketplaceProducts(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List saved marketplace snapshots
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/snapshots
     */
    marketplaceListMarketplaceSnapshots(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List tenant IDs
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/tenants
     */
    marketplaceListMarketplaceTenants(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Update plugin metadata
     * @maturity stable
     * @method PUT
     * @path /api/v1/marketplace/plugins/{pluginId}
     */
    marketplaceUpdateMarketplacePlugins(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Platform dashboard overview
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/dashboard
     */
    platformListMarketplacePlatformDashboard(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Platform drift summary
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/drift-summary
     */
    platformListMarketplacePlatformDriftsummary(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Health vs volatility overview
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/overview
     */
    platformListMarketplacePlatformOverview(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Platform ranking reports
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/rankings
     */
    platformListMarketplacePlatformRankings(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Platform tenants overview
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/tenants-overview
     */
    platformListMarketplacePlatformTenantsoverview(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Platform time-series data
     * @maturity stable
     * @method GET
     * @path /api/v1/marketplace/platform/timeseries
     */
    platformListMarketplacePlatformTimeseries(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Record a remediation recommendation or action
     * @maturity stable
     * @method POST
     * @path /api/v1/remediation-events
     */
    remediationCreateRemediationevents(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Mark a remediation event as resolved
     * @maturity stable
     * @method POST
     * @path /api/v1/remediation-events/{eventId}/resolve
     */
    remediationCreateRemediationeventsResolve(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * List configured risk zones
     * @maturity beta
     * @method GET
     * @path /api/v1/risk-zones
     */
    riskzonesListRiskzones(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Classify health and volatility into a risk zone
     * @maturity beta
     * @method GET
     * @path /api/v1/risk-zones/classify
     */
    riskzonesListRiskzonesClassify(params?: any, config?: AxiosRequestConfig): Promise<any>;
    /**
     * Create or reset a synthetic test scenario
     * @maturity experimental
     * @method POST
     * @path /api/v1/marketplace/test/scenario
     */
    testingCreateMarketplaceTestScenario(params?: any, config?: AxiosRequestConfig): Promise<any>;
}
//# sourceMappingURL=client.d.ts.map