import axios, { type AxiosError, type AxiosInstance } from 'axios';
import { ApiClient, type ApiClientConfig } from '@gd-workflow-bridge-pro/api-sdk';

export class SdkError extends Error {
  status: number;
  code?: string;
  requestId?: string;
  details?: unknown;
  retryable: boolean;
  response?: any;
  original?: unknown;

  constructor(message: string, options: Partial<SdkError> = {}) {
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

  static from(error: unknown): SdkError {
    if (error instanceof SdkError) {
      return error;
    }

    if (error instanceof Error) {
      const axiosError = error as AxiosError;
      if (axiosError.response) {
        const status = axiosError.response.status;
        const responseData = axiosError.response.data as any;
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

  static wrap<T>(fn: () => Promise<T>): Promise<T> {
    return fn()
      .then((result) => {
        if (result && typeof result === 'object' && 'data' in result) {
          return (result as any).data;
        }
        return result;
      })
      .catch((error) => {
        throw SdkError.from(error);
      });
  }
}

export interface SdkConfig extends ApiClientConfig {}

export function createSdk(config: SdkConfig) {
  const client = new ApiClient(config);
  const axiosInstance = (client as any).client as AxiosInstance;

  function buildPath(path: string, params: Record<string, string>) {
    return path.replace(/\{([^}]+)\}/g, (_, key) => {
      const value = params[key];
      if (value === undefined || value === null) {
        throw new Error(`Missing path parameter: ${key}`);
      }
      return encodeURIComponent(String(value));
    });
  }

  const rawGet = (path: string, params?: any) => SdkError.wrap(() => axiosInstance.get(path, { params }));
  const rawPost = (path: string, data?: any) => SdkError.wrap(() => axiosInstance.post(path, data));

  return {
    marketplace: {
      listProducts: (params?: any) => SdkError.wrap(() => client.marketplaceListMarketplaceProducts(params)),
      getProduct: (productId: string) => rawGet(buildPath('/api/v1/marketplace/products/{productId}', { productId })),
      listPlugins: (params?: any) => SdkError.wrap(() => client.marketplaceListMarketplacePlugins(params)),
      getPluginInstalls: (pluginId: string, params?: any) => rawGet(buildPath('/api/v1/marketplace/plugins/{pluginId}/installs', { pluginId }), params),
      listSnapshots: (params?: any) => SdkError.wrap(() => client.marketplaceListMarketplaceSnapshots(params)),
      listTenants: (params?: any) => SdkError.wrap(() => client.marketplaceListMarketplaceTenants(params)),
      getTenant: (tenantId: string) => rawGet(buildPath('/api/v1/marketplace/tenants/{tenantId}', { tenantId })),
      getTenantTrends: (tenantId: string) => rawGet(buildPath('/api/v1/marketplace/tenants/{tenantId}/trends', { tenantId })),
      installPlugin: (pluginId: string, params?: any) => rawPost(buildPath('/api/v1/marketplace/plugins/{pluginId}/install', { pluginId }), params),
      uninstallPlugin: (pluginId: string, params?: any) => rawPost(buildPath('/api/v1/marketplace/plugins/{pluginId}/uninstall', { pluginId }), params),
      previewTenantRemediation: (tenantId: string, action: string, params?: any) => rawPost(buildPath('/api/v1/marketplace/tenants/{tenantId}/remediate/{action}/preview', { tenantId, action }), params),
      executeTenantRemediation: (tenantId: string, action: string, params?: any) => SdkError.wrap(() => {
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
      health: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencehealth(params)),
      consolidated: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearning(params)),
      performance: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearningPerformance(params)),
      adoptionGaps: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearningAdoptiongaps(params)),
      recurringIssues: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearningRecurringissues(params)),
      trends: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearningTrends(params)),
      effectivenessScore: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligencelearningEffectivenessscore(params)),
      effectivenessSummary: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectiveness(params)),
      accuracy: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessAccuracy(params)),
      acceptanceRate: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessAcceptancerate(params)),
      mttd: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessMttd(params)),
      mttr: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessMttr(params)),
      recommendations: (params?: any) => SdkError.wrap(() => client.intelligenceListIntelligenceeffectivenessRecommendations(params)),
    },
    platform: {
      getDriftSummary: (params?: any) => SdkError.wrap(() => client.platformListMarketplacePlatformDriftsummary(params)),
      getTenantOverview: (params?: any) => SdkError.wrap(() => client.platformListMarketplacePlatformTenantsoverview(params)),
    },
    risk: {
      listZones: (params?: any) => SdkError.wrap(() => client.riskzonesListRiskzones(params)),
      classify: (params?: any) => SdkError.wrap(() => client.riskzonesListRiskzonesClassify(params)),
    },
    remediation: {
      recordEvent: (params?: any) => SdkError.wrap(() => client.remediationCreateRemediationevents(params)),
      resolveEvent: (eventId: string, params?: any) => rawPost(buildPath('/api/v1/remediation-events/{eventId}/resolve', { eventId }), params),
    },
  };
}
