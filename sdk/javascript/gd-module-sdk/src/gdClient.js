const { get, post, put, request } = require('./httpClient');
const crypto = require('crypto');

class GDClient {
  constructor(options = {}) {
    this.baseUrls = options.baseUrls || {};
    this.token = options.token || null;
  }

  // Manifest canonicalization/signing helpers
  _sortKeysRecursively(val) {
    if (Array.isArray(val)) return val.map(v => this._sortKeysRecursively(v));
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
    return sig.toString('base64');
  }

  setToken(token) { this.token = token; }
  getToken() { return this.token; }

  _base(service) {
    return this.baseUrls[service] || this.baseUrls['default'] || `http://127.0.0.1:${this._defaultPortFor(service)}`;
  }

  _defaultPortFor(service) {
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

  _headers(extra = {}) {
    const h = Object.assign({}, extra);
    if (this.token) h['Authorization'] = `Bearer ${this.token}`;
    h['Accept'] = 'application/json';
    return h;
  }

  // Basic helpers
  async getHealth(service) {
    const url = `${this._base(service)}/health`;
    return get(url, this._headers());
  }

  async listMarketplaceProducts() {
    const url = `${this._base('marketplace')}/api/v1/marketplace/products`;
    return get(url, this._headers());
  }

  async evaluatePolicy(input) {
    const url = `${this._base('control-plane')}/evaluate`;
    return post(url, input, this._headers());
  }

  async trackUsage(tenantId, event, count = 1) {
    const url = `${this._base('usage')}/api/v1/usage/track`;
    return post(url, { tenant_id: tenantId, event, count }, this._headers());
  }

  // Auth convenience methods
  async authRegister(tenantId, email, password) {
    const url = `${this._base('auth')}/api/v1/auth/register`;
    return post(url, { tenant_id: tenantId, email, password }, this._headers());
  }

  async authLogin(tenantId, email, password) {
    const url = `${this._base('auth')}/api/v1/auth/login`;
    const res = await post(url, { tenant_id: tenantId, email, password }, this._headers());
    // Token commonly returned as `token` in response
    if (res && res.token) {
      this.setToken(res.token);
    }
    return res;
  }

  async authIntrospect(token = null) {
    const url = `${this._base('auth')}/api/v1/auth/introspect`;
    const t = token || this.token;
    if (!t) throw new Error('No token provided for introspect');
    return post(url, { token: t }, this._headers());
  }

  async authRefresh(token = null) {
    const url = `${this._base('auth')}/api/v1/auth/refresh`;
    const t = token || this.token;
    if (!t) throw new Error('No token provided for refresh');
    const res = await post(url, { token: t }, this._headers());
    if (res && res.token) this.setToken(res.token);
    return res;
  }

  // Billing convenience
  async billingCreateSubscription(tenantId, customerId, planId, gateway = 'stripe', currency = 'USD') {
    const url = `${this._base('billing')}/api/v1/subscriptions`;
    return post(url, { tenant_id: tenantId, customer_id: customerId, plan_id: planId, gateway, currency }, this._headers());
  }

  // Marketplace convenience
  async marketplacePurchase(productId, quantity = 1) {
    const url = `${this._base('marketplace')}/products/${encodeURIComponent(productId)}/purchase`;
    return post(url, { quantity }, this._headers());
  }

  // Marketplace plugin/extension helpers
  async listPlugins() {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins`;
    return get(url, this._headers());
  }

  async registerPlugin(pluginMeta = {}) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins`;
    return post(url, pluginMeta, this._headers());
  }

  async listPluginVersions(pluginId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`;
    return get(url, this._headers());
  }

  async addPluginVersion(pluginId, versionMeta = {}) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions`;
    return post(url, versionMeta, this._headers());
  }

  async registerPluginKey(pluginId, publicKeyPem, label = '') {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`;
    return post(url, { public_key: publicKeyPem, label }, this._headers());
  }

  async listPluginKeys(pluginId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys`;
    return get(url, this._headers());
  }

  async revokePluginKey(pluginId, keyId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/revoke`;
    return post(url, {}, this._headers());
  }

  async activatePluginKey(pluginId, keyId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}/activate`;
    return post(url, {}, this._headers());
  }

  async deletePluginKey(pluginId, keyId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/keys/${encodeURIComponent(keyId)}`;
    return request('DELETE', url, this._headers());
  }

  async uploadPluginArtifact(pluginId, version, artifactMeta = {}) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`;
    return post(url, artifactMeta, this._headers());
  }

  async listPluginArtifacts(pluginId, version) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact`;
    return get(url, this._headers());
  }

  async downloadPluginArtifact(pluginId, version, artifactId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/versions/${encodeURIComponent(version)}/artifact/${encodeURIComponent(artifactId)}`;
    return get(url, this._headers());
  }

  verifyArtifact(pluginId, artifactPayload) {
    // artifactPayload should include download_base64, signature and optionally public_key
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
    // try registered keys
    return this.listPluginKeys(pluginId).then(res => {
      const keys = res && res.items ? res.items : [];
      for (const k of keys) {
        if (k.revoked) continue;
        try {
          if (verify.verify(k.public_key, sig)) return true;
        } catch (e) { /* ignore */ }
      }
      return false;
    });
  }

  async installPlugin(pluginId, tenantId, version = null, options = {}) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/install`;
    const body = Object.assign({ tenant_id: tenantId }, options || {});
    if (version) body.version = version;
    return post(url, body, this._headers());
  }

  async uninstallPlugin(pluginId, tenantId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/uninstall`;
    return post(url, { tenant_id: tenantId }, this._headers());
  }

  async listPluginInstalls(pluginId, tenantId = null) {
    let url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/installs`;
    if (tenantId) url += `?tenant_id=${encodeURIComponent(tenantId)}`;
    return get(url, this._headers());
  }

  async updatePlugin(pluginId, payload = {}) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}`;
    return put(url, payload, this._headers());
  }

  async publishPlugin(pluginId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/publish`;
    return post(url, {}, this._headers());
  }

  async unpublishPlugin(pluginId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/unpublish`;
    return post(url, {}, this._headers());
  }

  async ratePlugin(pluginId, rating, comment = '', tenantId = null) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`;
    const body = { rating, comment };
    if (tenantId) body.tenant_id = tenantId;
    return post(url, body, this._headers());
  }

  async listPluginRatings(pluginId) {
    const url = `${this._base('marketplace')}/api/v1/marketplace/plugins/${encodeURIComponent(pluginId)}/ratings`;
    return get(url, this._headers());
  }

  // CMS convenience methods
  async listProjects(query = {}, asUserId = null) {
    const qs = Object.keys(query).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(query[k])).join('&');
    const url = `${this._base('cms')}/api/v1/projects${qs ? ('?' + qs) : ''}`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return get(url, headers);
  }

  async getProject(projectId, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return get(url, headers);
  }

  async createProject(tenantId, title = 'Untitled Project', orderId = null, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/projects`;
    const body = { tenant_id: tenantId, title };
    if (orderId) body.order_id = orderId;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return post(url, body, headers);
  }

  async grantProjectAccess(projectId, targetUserId, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/grant`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return post(url, { user_id: targetUserId }, headers);
  }

  async revokeProjectAccess(projectId, targetUserId, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/access/revoke`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return post(url, { user_id: targetUserId }, headers);
  }

  async getVaultFiles(projectId, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/vault/${encodeURIComponent(projectId)}/files`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return get(url, headers);
  }

  async uploadProjectFile(projectId, fileMeta = {}, asUserId = null) {
    const url = `${this._base('cms')}/api/v1/projects/${encodeURIComponent(projectId)}/upload`;
    const headers = this._headers(asUserId ? { 'X-User-Id': asUserId } : {});
    return post(url, fileMeta, headers);
  }

  // Tenant management
  async createTenant(name, domain, branding = {}, settings = {}, feature_flags = {}) {
    const url = `${this._base('tenant')}/api/v1/tenants`;
    return post(url, { name, domain, branding, settings, feature_flags }, this._headers());
  }

  async getTenant(tenantId) {
    const url = `${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`;
    return get(url, this._headers());
  }

  async updateTenant(tenantId, payload = {}) {
    const url = `${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}`;
    return put(url, payload, this._headers());
  }

  async getTenantSettings(tenantId) {
    const url = `${this._base('tenant')}/api/v1/tenants/${encodeURIComponent(tenantId)}/settings`;
    return get(url, this._headers());
  }

  async listTenants() {
    const url = `${this._base('tenant')}/api/v1/tenants`;
    return get(url, this._headers());
  }
}

module.exports = { GDClient };
