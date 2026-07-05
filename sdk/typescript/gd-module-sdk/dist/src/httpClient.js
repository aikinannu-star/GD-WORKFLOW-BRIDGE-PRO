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
exports.request = request;
exports.get = get;
exports.post = post;
exports.put = put;
const http = __importStar(require("http"));
const https = __importStar(require("https"));
const url_1 = require("url");
async function request(method, urlStr, headers = {}, body) {
    return new Promise((resolve, reject) => {
        try {
            const url = new url_1.URL(urlStr);
            const opts = {
                method,
                hostname: url.hostname,
                port: url.port ? Number(url.port) : (url.protocol === 'https:' ? 443 : 80),
                path: url.pathname + url.search,
                headers: Object.assign({}, headers),
            };
            const lib = url.protocol === 'https:' ? https : http;
            const req = lib.request(opts, (res) => {
                const chunks = [];
                res.on('data', (c) => chunks.push(c));
                res.on('end', () => {
                    const buf = Buffer.concat(chunks);
                    const text = buf.toString('utf8');
                    const ct = (res.headers['content-type'] || '');
                    let result = text;
                    if (ct.includes('application/json')) {
                        try {
                            result = JSON.parse(text || '{}');
                        }
                        catch (e) { /* ignore */ }
                    }
                    if ((res.statusCode || 0) >= 200 && (res.statusCode || 0) < 300) {
                        resolve(result);
                    }
                    else {
                        const err = new Error('HTTP Error: ' + res.statusCode);
                        err.status = res.statusCode;
                        err.body = result;
                        reject(err);
                    }
                });
            });
            req.on('error', (err) => reject(err));
            if (body !== undefined) {
                const payload = typeof body === 'string' ? body : JSON.stringify(body);
                req.setHeader('Content-Type', 'application/json');
                req.setHeader('Content-Length', Buffer.byteLength(payload));
                req.write(payload);
            }
            req.end();
        }
        catch (e) {
            reject(e);
        }
    });
}
function get(url, headers = {}) { return request('GET', url, headers); }
function post(url, body, headers = {}) { return request('POST', url, headers, body); }
function put(url, body, headers = {}) { return request('PUT', url, headers, body); }
