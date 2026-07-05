"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.GDClient = void 0;
const httpClient_1 = require("./httpClient");
class GDClient {
    constructor(options = {}) {
        this.baseUrls = options.baseUrls || {};
        this.token = options.token || null;
    }
    setToken(token) { this.token = token; }
    getToken() { return this.token; }
    _base(service) {
        return this.baseUrls[service] || this.baseUrls['default'] || `http://127.0.0.1:${this._defaultPortFor(service)}`;
    }
    _defaultPortFor(service) {
        switch (service) {
            case 'auth': return 8002;
            case 'billing': return 8003;
            case 'cms': return 8004;
            case 'marketplace': return 8006;
            case 'usage': return 8007;
            case 'control-plane': return 8080;
            default: return 8000;
        }
    }
    _headers(extra = {}) {
        const h = Object.assign({}, extra);
        if (this.token)
            h['Authorization'] = `Bearer ${this.token}`;
        h['Accept'] = 'application/json';
        return h;
    }
    // Basic
    async getHealth(service) { return (0, httpClient_1.get)(`${this._base(service)}/health`, this._headers()); }
    async listMarketplaceProducts() { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/products`, this._headers()); }
    async evaluatePolicy(input) { return (0, httpClient_1.post)(`${this._base('control-plane')}/evaluate`, input, this._headers()); }
    async trackUsage(tenantId, event, count = 1) { return (0, httpClient_1.post)(`${this._base('usage')}/api/v1/usage/track`, { tenant_id: tenantId, event, count }, this._headers()); }
    // Auth
    async authRegister(tenantId, email, password) { return (0, httpClient_1.post)(`${this._base('auth')}/api/v1/auth/register`, { tenant_id: tenantId, email, password }, this._headers()); }
    async authLogin(tenantId, email, password) {
        const res = await (0, httpClient_1.post)(`${this._base('auth')}/api/v1/auth/login`, { tenant_id: tenantId, email, password }, this._headers());
        if (res && res.token)
            this.setToken(res.token);
        return res;
    }
    async authIntrospect(token) { const t = token || this.token; if (!t)
        throw new Error('No token for introspect'); return (0, httpClient_1.post)(`${this._base('auth')}/api/v1/auth/introspect`, { token: t }, this._headers()); }
    async authRefresh(token) { const t = token || this.token; if (!t)
        throw new Error('No token for refresh'); const res = await (0, httpClient_1.post)(`${this._base('auth')}/api/v1/auth/refresh`, { token: t }, this._headers()); if (res && res.token)
        this.setToken(res.token); return res; }
    // Billing
    async billingCreateSubscription(tenantId, customerId, planId, gateway = 'stripe', currency = 'USD') { return (0, httpClient_1.post)(`${this._base('billing')}/api/v1/subscriptions`, { tenant_id: tenantId, customer_id: customerId, plan_id: planId, gateway, currency }, this._headers()); }
    // CMS
    async listProjects(query = {}, asUserId = null) {
        const qs = Object.keys(query).map(k => `${encodeURIComponent(k)}=${encodeURIComponent(String(query[k]))}`).join('&');
        const url = `${this._base('cms')}/api/v1/projects${qs ? ('?' + qs) : ''}`;
        return (0, httpClient_1.get)(url, this._headers(asUserId ? { 'X-User-Id': asUserId } : {}));
    }
    async getProject(projectId, asUserId = null) { return (0, httpClient_1.get)(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}`, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    async createProject(tenantId, title = 'Untitled Project', orderId = null, asUserId = null) { const body = { tenant_id: tenantId, title }; if (orderId)
        body.order_id = orderId; return (0, httpClient_1.post)(`${this._base('cms')}/api/v1/projects`, body, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    async grantProjectAccess(projectId, targetUserId, asUserId = null) { return (0, httpClient_1.post)(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/grant`, { user_id: targetUserId }, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    async revokeProjectAccess(projectId, targetUserId, asUserId = null) { return (0, httpClient_1.post)(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/revoke`, { user_id: targetUserId }, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    async getVaultFiles(projectId, asUserId = null) { return (0, httpClient_1.get)(`${this._base('cms')}/api/v1/vault/${encodeURIComponent(projectId)}/files`, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    async uploadProjectFile(projectId, fileMeta, asUserId = null) { return (0, httpClient_1.post)(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/upload`, fileMeta, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
    // Marketplace
    async listPlugins() { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins`, this._headers()); }
    async registerPlugin(pluginMeta = {}) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins`, pluginMeta, this._headers()); }
    async marketplacePurchase(productId, quantity = 1) { return (0, httpClient_1.post)(`${this._base('marketplace')}/products/${encodeURIComponent(productId)}/purchase`, { quantity }, this._headers()); }
}
exports.GDClient = GDClient;
exports.default = GDClient;
