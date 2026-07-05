import { get, post, put, request } from './httpClient';
import * as crypto from 'crypto';

export interface GDClientOptions {
  baseUrls?: Record<string,string>;
  token?: string | null;
}

export class GDClient {
  private baseUrls: Record<string,string>;
  private token: string | null;

  constructor(options: GDClientOptions = {}) {
    this.baseUrls = options.baseUrls || {};
    this.token = options.token || null;
  }

  setToken(token: string | null) { this.token = token; }
  getToken(): string | null { return this.token; }

  private _base(service: string) {
    return this.baseUrls[service] || this.baseUrls['default'] || `http://127.0.0.1:${this._defaultPortFor(service)}`;
  }

  private _defaultPortFor(service: string) {
    switch(service) {
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

  private _headers(extra: Record<string,string> = {}) {
    const h = Object.assign({}, extra);
    if (this.token) h['Authorization'] = `Bearer ${this.token}`;
    h['Accept'] = 'application/json';
    return h;
  }

  // Manifest canonicalization/signing helpers
  private _sortKeysRecursively(val: any): any {
    if (Array.isArray(val)) return val.map(v => this._sortKeysRecursively(v));
    if (val && typeof val === 'object') {
      const out: Record<string, any> = {};
      Object.keys(val).sort().forEach(k => { out[k] = this._sortKeysRecursively(val[k]); });
      return out;
    }
    return val;
  }

  private _canonicalJson(obj: any): string {
    return JSON.stringify(this._sortKeysRecursively(obj));
  }

  signManifest(manifest: any, privateKeyPem: string): string {
    const canonical = this._canonicalJson(manifest);
    const sign = crypto.createSign('RSA-SHA256');
    sign.update(canonical);
    sign.end();
    const sig = sign.sign(privateKeyPem);
    return Buffer.from(sig).toString('base64');
  }

  // Basic
  async getHealth(service: string) { return get(`${this._base(service)}/health`, this._headers()); }
  async listMarketplaceProducts() { return get(`${this._base('marketplace')}/api/v1/marketplace/products`, this._headers()); }
  async evaluatePolicy(input: { filePath: string; content: string }) { return post(`${this._base('control-plane')}/evaluate`, input, this._headers()); }
  async trackUsage(tenantId: string, event: string, count = 1) { return post(`${this._base('usage')}/api/v1/usage/track`, { tenant_id: tenantId, event, count }, this._headers()); }

  // Auth
  async authRegister(tenantId: string, email: string, password: string) { return post(`${this._base('auth')}/api/v1/auth/register`, { tenant_id: tenantId, email, password }, this._headers()); }
  async authLogin(tenantId: string, email: string, password: string) {
    const res = await post(`${this._base('auth')}/api/v1/auth/login`, { tenant_id: tenantId, email, password }, this._headers());
    if (res && (res as any).token) this.setToken((res as any).token);
    return res;
  }
  async authIntrospect(token?: string|null) { const t = token || this.token; if (!t) throw new Error('No token for introspect'); return post(`${this._base('auth')}/api/v1/auth/introspect`, { token: t }, this._headers()); }
  async authRefresh(token?: string|null) { const t = token || this.token; if (!t) throw new Error('No token for refresh'); const res = await post(`${this._base('auth')}/api/v1/auth/refresh`, { token: t }, this._headers()); if (res && (res as any).token) this.setToken((res as any).token); return res; }

  // Billing
  async billingCreateSubscription(tenantId: string, customerId: string, planId: string, gateway = 'stripe', currency = 'USD') { return post(`${this._base('billing')}/api/v1/subscriptions`, { tenant_id: tenantId, customer_id: customerId, plan_id: planId, gateway, currency }, this._headers()); }

  // CMS
  async listProjects(query: Record<string,any> = {}, asUserId: string | null = null) {
    const qs = Object.keys(query).map(k => `${encodeURIComponent(k)}=${encodeURIComponent(String(query[k]))}`).join('&');
    const url = `${this._base('cms')}/api/v1/projects${qs ? ('?' + qs) : ''}`;
    return get(url, this._headers(asUserId ? { 'X-User-Id': asUserId } : {}));
  }
  async getProject(projectId: string, asUserId: string | null = null) { return get(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}`, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
  async createProject(tenantId: string, title = 'Untitled Project', orderId: string|null = null, asUserId: string|null = null) { const body: any = { tenant_id: tenantId, title }; if (orderId) body.order_id = orderId; return post(`${this._base('cms')}/api/v1/projects`, body, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
  async grantProjectAccess(projectId: string, targetUserId: string, asUserId: string|null = null) { return post(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/grant`, { user_id: targetUserId }, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
  async revokeProjectAccess(projectId: string, targetUserId: string, asUserId: string|null = null) { return post(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/revoke`, { user_id: targetUserId }, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
  async getVaultFiles(projectId: string, asUserId: string|null = null) { return get(`${this._base('cms')}/api/v1/vault/${encodeURIComponent(projectId)}/files`, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }
  async uploadProjectFile(projectId: string, fileMeta: Record<string, any>, asUserId: string|null = null) { return post(`${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/upload`, fileMeta, this._headers(asUserId ? { 'X-User-Id': asUserId } : {})); }

  // Marketplace
  async listPlugins() { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins`, this._headers()); }
  async registerPlugin(pluginMeta: Record<string, any> = {}) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins`, pluginMeta, this._headers()); }
  async marketplacePurchase(productId: string, quantity = 1) { return post(`${this._base('marketplace')}/products/${encodeURIComponent(productId)}/purchase`, { quantity }, this._headers()); }

  async listPluginVersions(pluginId: string) { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`, this._headers()); }
  async addPluginVersion(pluginId: string, versionMeta: Record<string, any> = {}) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`, versionMeta, this._headers()); }
  async registerPluginKey(pluginId: string, publicKeyPem: string, label = '') { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`, { public_key: publicKeyPem, label }, this._headers()); }
  async listPluginKeys(pluginId: string) { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`, this._headers()); }
  async revokePluginKey(pluginId: string, keyId: string) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/revoke`, {}, this._headers()); }
  async activatePluginKey(pluginId: string, keyId: string) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/activate`, {}, this._headers()); }
  async deletePluginKey(pluginId: string, keyId: string) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}`;
    return request('DELETE', url, this._headers());
  }
  async uploadPluginArtifact(pluginId: string, version: string, artifactMeta: Record<string, any> = {}) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`, artifactMeta, this._headers()); }
  async listPluginArtifacts(pluginId: string, version: string) { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`, this._headers()); }
  async downloadPluginArtifact(pluginId: string, version: string, artifactId: string) { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact/${encodeURIComponent(artifactId)}`, this._headers()); }
  async verifyArtifact(pluginId: string, artifactPayload: any): Promise<boolean> {
    const rawB64 = artifactPayload.download_base64;
    const sigB64 = artifactPayload.signature || null;
    const pubKey = artifactPayload.public_key || null;
    if (!rawB64 || !sigB64) return false;
    const raw = Buffer.from(rawB64, 'base64');
    const sig = Buffer.from(sigB64, 'base64');
    const verify = crypto.createVerify('RSA-SHA256');
    verify.update(raw);
    verify.end();
    if (pubKey) {
      try { return verify.verify(pubKey, sig); } catch (e) { /* ignore and fallback */ }
    }
    const res = await this.listPluginKeys(pluginId) as any;
    const keys = res && (res as any).items ? (res as any).items : [];
    for (const k of keys) {
      if (k.revoked) continue;
      try { if (verify.verify(k.public_key, sig)) return true; } catch (e) { /* ignore */ }
    }
    return false;
  }
  async installPlugin(pluginId: string, tenantId: string, version?: string|null, options: Record<string, any> = {}) { const body: any = Object.assign({ tenant_id: tenantId }, options || {}); if (version) body.version = version; return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/install`, body, this._headers()); }
  async uninstallPlugin(pluginId: string, tenantId: string) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/uninstall`, { tenant_id: tenantId }, this._headers()); }
  async listPluginInstalls(pluginId: string, tenantId?: string|null) { let url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/installs`; if (tenantId) url += `?tenant_id=${encodeURIComponent(tenantId)}`; return get(url, this._headers()); }
  async updatePlugin(pluginId: string, payload: Record<string, any> = {}) { return put(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}`, payload, this._headers()); }
  async publishPlugin(pluginId: string) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/publish`, {}, this._headers()); }
  async unpublishPlugin(pluginId: string) { return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/unpublish`, {}, this._headers()); }
  async ratePlugin(pluginId: string, rating: number, comment = '', tenantId?: string|null) { const body: any = { rating, comment }; if (tenantId) body.tenant_id = tenantId; return post(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`, body, this._headers()); }
  async listPluginRatings(pluginId: string) { return get(`${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`, this._headers()); }

  // Tenant management
  async createTenant(name: string, domain: string, branding: Record<string, any> = {}, settings: Record<string, any> = {}, feature_flags: Record<string, any> = {}) {
    return post(`${this._base('tenant')}/api/v1/tenants`, { name, domain, branding, settings, feature_flags }, this._headers());
  }

  async getTenant(tenantId: string) { return get(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`, this._headers()); }

  async updateTenant(tenantId: string, payload: Record<string, any> = {}) { return put(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`, payload, this._headers()); }

  async getTenantSettings(tenantId: string) { return get(`${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}/settings`, this._headers()); }

  async listTenants() { return get(`${this._base('tenant')}/api/v1/tenants`, this._headers()); }
}

export default GDClient;
