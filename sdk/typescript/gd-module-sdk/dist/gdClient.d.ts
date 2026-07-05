export interface GDClientOptions {
    baseUrls?: Record<string, string>;
    token?: string | null;
}
export declare class GDClient {
    private baseUrls;
    private token;
    constructor(options?: GDClientOptions);
    setToken(token: string | null): void;
    getToken(): string | null;
    private _base;
    private _defaultPortFor;
    private _headers;
    getHealth(service: string): Promise<any>;
    listMarketplaceProducts(): Promise<any>;
    evaluatePolicy(input: {
        filePath: string;
        content: string;
    }): Promise<any>;
    trackUsage(tenantId: string, event: string, count?: number): Promise<any>;
    authRegister(tenantId: string, email: string, password: string): Promise<any>;
    authLogin(tenantId: string, email: string, password: string): Promise<any>;
    authIntrospect(token?: string | null): Promise<any>;
    authRefresh(token?: string | null): Promise<any>;
    billingCreateSubscription(tenantId: string, customerId: string, planId: string, gateway?: string, currency?: string): Promise<any>;
    listProjects(query?: Record<string, any>, asUserId?: string | null): Promise<any>;
    getProject(projectId: string, asUserId?: string | null): Promise<any>;
    createProject(tenantId: string, title?: string, orderId?: string | null, asUserId?: string | null): Promise<any>;
    grantProjectAccess(projectId: string, targetUserId: string, asUserId?: string | null): Promise<any>;
    revokeProjectAccess(projectId: string, targetUserId: string, asUserId?: string | null): Promise<any>;
    getVaultFiles(projectId: string, asUserId?: string | null): Promise<any>;
    uploadProjectFile(projectId: string, fileMeta: Record<string, any>, asUserId?: string | null): Promise<any>;
    listPlugins(): Promise<any>;
    registerPlugin(pluginMeta?: Record<string, any>): Promise<any>;
    marketplacePurchase(productId: string, quantity?: number): Promise<any>;
}
export default GDClient;
export { GDClient };
