import { ApiClient } from '@gd-workflow-bridge-pro/api-sdk';
export class SdkError extends Error {
    constructor(message, options = {}) {
        super(message);
        this.name = 'SdkError';
        this.status = options.status ?? 0;
        this.code = options.code;
        this.requestId = options.requestId;
        this.details = options.details;
        this.retryable = options.retryable ?? false;
        this.response = options.response;
        this.original = options.original;
    }
    static from(error) {
        if (error instanceof SdkError) {
            return error;
        }
        if (error instanceof Error) {
            const axiosError = error;
            if (axiosError.response) {
                const status = axiosError.response.status;
                const responseData = axiosError.response.data;
                const requestId = axiosError.response.headers?.['x-request-id'] ?? axiosError.response.headers?.['request-id'];
                const code = responseData?.code ?? responseData?.error ?? undefined;
                const details = responseData?.details ?? responseData?.detail ?? responseData;
                const retryable = status === 429 || status >= 500;
                return new SdkError(error.message, {
                    status,
                    code,
                    requestId,
                    details,
                    retryable,
                    response: responseData,
                    original: error,
                });
            }
            return new SdkError(error.message, { original: error });
        }
        return new SdkError(typeof error === 'string' ? error : JSON.stringify(error), { original: error });
    }
    static wrap(fn) {
        return fn()
            .then((result) => {
            if (result && typeof result === 'object' && 'data' in result) {
                return result.data;
            }
            return result;
        })
            .catch((error) => {
            throw SdkError.from(error);
        });
    }
}
export function createSdk(config) {
    const client = new ApiClient(config);
    const axiosInstance = client.client;
    function buildPath(path, params) {
        return path.replace(/\{([^}]+)\}/g, (_, key) => {
            const value = params[key];
            if (value === undefined || value === null) {
                throw new Error(`Missing path parameter: ${key}`);
            }
            return encodeURIComponent(String(value));
        });
    }
    const rawGet = (path, params) => SdkError.wrap(() => axiosInstance.get(path, { params }));
    const rawPost = (path, data) => SdkError.wrap(() => axiosInstance.post(path, data));
    return {
        marketplace: {
            listProducts: (params) => SdkError.wrap(() => client.marketplaceListMarketplaceProducts(params)),
            getProduct: (productId) => rawGet(buildPath('/api/v1/marketplace/products/{productId}', { productId })),
            listPlugins: (params) => SdkError.wrap(() => client.marketplaceListMarketplacePlugins(params)),
            getPluginInstalls: (pluginId, params) => rawGet(buildPath('/api/v1/marketplace/plugins/{pluginId}/installs', { pluginId }), params),
            listSnapshots: (params) => SdkError.wrap(() => client.marketplaceListMarketplaceSnapshots(params)),
            listTenants: (params) => SdkError.wrap(() => client.marketplaceListMarketplaceTenants(params)),
            getTenant: (tenantId) => rawGet(buildPath('/api/v1/marketplace/tenants/{tenantId}', { tenantId })),
            getTenantTrends: (tenantId) => rawGet(buildPath('/api/v1/marketplace/tenants/{tenantId}/trends', { tenantId })),
            installPlugin: (pluginId, params) => rawPost(buildPath('/api/v1/marketplace/plugins/{pluginId}/install', { pluginId }), params),
            uninstallPlugin: (pluginId, params) => rawPost(buildPath('/api/v1/marketplace/plugins/{pluginId}/uninstall', { pluginId }), params),
            previewTenantRemediation: (tenantId, action, params) => rawPost(buildPath('/api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview', { tenantId, action }), params),
            executeTenantRemediation: (tenantId, action, params) => SdkError.wrap(() => {
                switch (action) {
                    case 'install-missing-deps':
                        return axiosInstance.post(buildPath('/api/v1/marketplace/tenants/{tenantId}/remediate/install-missing-deps', { tenantId }), params);
                    case 'activate-keys':
                        return axiosInstance.post(buildPath('/api/v1/marketplace/tenants/{tenantId}/remediate/activate-keys', { tenantId }), params);
                    default:
                        return axiosInstance.post(buildPath('/api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview', { tenantId, action }), params);
                }
            }),
        },
        intelligence: {
            health: (params) => SdkError.wrap(() => client.intelligenceListIntelligencehealth(params)),
            consolidated: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearning(params)),
            performance: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearningPerformance(params)),
            adoptionGaps: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearningAdoptiongaps(params)),
            recurringIssues: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearningRecurringissues(params)),
            trends: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearningTrends(params)),
            effectivenessScore: (params) => SdkError.wrap(() => client.intelligenceListIntelligencelearningEffectivenessscore(params)),
            effectivenessSummary: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectiveness(params)),
            accuracy: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessAccuracy(params)),
            acceptanceRate: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessAcceptancerate(params)),
            mttd: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessMttd(params)),
            mttr: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessMttr(params)),
            recommendations: (params) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessRecommendations(params)),
        },
        platform: {
            getDriftSummary: (params) => SdkError.wrap(() => client.platformListMarketplacePlatformDriftsummary(params)),
            getTenantOverview: (params) => SdkError.wrap(() => client.platformListMarketplacePlatformTenantsoverview(params)),
        },
        risk: {
            listZones: (params) => SdkError.wrap(() => client.riskzonesListRiskzones(params)),
            classify: (params) => SdkError.wrap(() => client.riskzonesListRiskzonesClassify(params)),
        },
        remediation: {
            recordEvent: (params) => SdkError.wrap(() => client.remediationCreateRemediationevents(params)),
            resolveEvent: (eventId, params) => rawPost(buildPath('/api/v1/remediation-events/{eventId}/resolve', { eventId }), params),
        },
    };
}
