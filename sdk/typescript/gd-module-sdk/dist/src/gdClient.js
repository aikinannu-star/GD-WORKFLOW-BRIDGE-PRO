"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.GDClient = void 0;
const httpClient_1 = require("./httpClient");
const crypto = __importStar(require("crypto"));
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
            case 'tenant': return 8009;
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
    // Manifest canonicalization/signing helpers
    _sortKeysRecursively(val) {
        if (Array.isArray(val))
            return val.map(v => this._sortKeysRecursively(v));
        if (val && typeof val === 'object') {
            const out = {};
            Object.keys(val).sort().forEach(k => { out[k] = this._sortKeysRecursively(val[k]); });
            return out;
        }
        return val;
    }
    _canonicalJson(obj) {
        return JSON.stringify(this._sortKeysRecursively(obj));
    }
    signManifest(manifest, privateKeyPem) {
        const canonical = this._canonicalJson(manifest);
        const sign = crypto.createSign('RSA-SHA256');
        sign.update(canonical);
        sign.end();
        const sig = sign.sign(privateKeyPem);
        return Buffer.from(sig).toString('base64');
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
    async listPluginVersions(pluginId) { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`, this._headers()); }
    async addPluginVersion(pluginId, versionMeta = {}) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`, versionMeta, this._headers()); }
    async registerPluginKey(pluginId, publicKeyPem, label = '') { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`, { public_key: publicKeyPem, label }, this._headers()); }
    async listPluginKeys(pluginId) { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`, this._headers()); }
    async revokePluginKey(pluginId, keyId) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/revoke`, {}, this._headers()); }
    async activatePluginKey(pluginId, keyId) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/activate`, {}, this._headers()); }
    async deletePluginKey(pluginId, keyId) {
        const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}`;
        return (0, httpClient_1.request)('DELETE', url, this._headers());
    }
    async uploadPluginArtifact(pluginId, version, artifactMeta = {}) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`, artifactMeta, this._headers()); }
    async listPluginArtifacts(pluginId, version) { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`, this._headers()); }
    async downloadPluginArtifact(pluginId, version, artifactId) { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact/${encodeURIComponent(artifactId)}`, this._headers()); }
    async verifyArtifact(pluginId, artifactPayload) {
        const rawB64 = artifactPayload.download_base64;
        const sigB64 = artifactPayload.signature || null;
        const pubKey = artifactPayload.public_key || null;
        if (!rawB64 || !sigB64)
            return false;
        const raw = Buffer.from(rawB64, 'base64');
        const sig = Buffer.from(sigB64, 'base64');
        const verify = crypto.createVerify('RSA-SHA256');
        verify.update(raw);
        verify.end();
        if (pubKey) {
            try {
                return verify.verify(pubKey, sig);
            }
            catch (e) { /* ignore and fallback */ }
        }
        const res = await this.listPluginKeys(pluginId);
        const keys = res && res.items ? res.items : [];
        for (const k of keys) {
            if (k.revoked)
                continue;
            try {
                if (verify.verify(k.public_key, sig))
                    return true;
            }
            catch (e) { /* ignore */ }
        }
        return false;
    }
    async installPlugin(pluginId, tenantId, version, options = {}) { const body = Object.assign({ tenant_id: tenantId }, options || {}); if (version)
        body.version = version; return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/install`, body, this._headers()); }
    async uninstallPlugin(pluginId, tenantId) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/uninstall`, { tenant_id: tenantId }, this._headers()); }
    async listPluginInstalls(pluginId, tenantId) { let url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/installs`; if (tenantId)
        url += `?tenant_id=${encodeURIComponent(tenantId)}`; return (0, httpClient_1.get)(url, this._headers()); }
    async updatePlugin(pluginId, payload = {}) { return (0, httpClient_1.put)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}`, payload, this._headers()); }
    async publishPlugin(pluginId) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/publish`, {}, this._headers()); }
    async unpublishPlugin(pluginId) { return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/unpublish`, {}, this._headers()); }
    async ratePlugin(pluginId, rating, comment = '', tenantId) { const body = { rating, comment }; if (tenantId)
        body.tenant_id = tenantId; return (0, httpClient_1.post)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`, body, this._headers()); }
    async listPluginRatings(pluginId) { return (0, httpClient_1.get)(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`, this._headers()); }
    // Tenant management
    async createTenant(name, domain, branding = {}, settings = {}, feature_flags = {}) {
        return (0, httpClient_1.post)(`${this._base('tenant')}/api/v1/tenants`, { name, domain, branding, settings, feature_flags }, this._headers());
    }
    async getTenant(tenantId) { return (0, httpClient_1.get)(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`, this._headers()); }
    async updateTenant(tenantId, payload = {}) { return (0, httpClient_1.put)(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`, payload, this._headers()); }
    async getTenantSettings(tenantId) { return (0, httpClient_1.get)(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}/settings`, this._headers()); }
    async listTenants() { return (0, httpClient_1.get)(`${this._base('tenant')}/api/v1/tenants`, this._headers()); }
}
exports.GDClient = GDClient;
exports.default = GDClient;
