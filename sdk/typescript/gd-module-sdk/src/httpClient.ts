import * as http from 'http';
import * as https from 'https';
import { URL } from 'url';

export async function request<T = any>(method: string, urlStr: string, headers: Record<string, string> = {}, body?: any): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    try {
      const url = new URL(urlStr);
      const opts: http.RequestOptions = {
        method,
        hostname: url.hostname,
        port: url.port ? Number(url.port) : (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname + url.search,
        headers: Object.assign({}, headers),
      };
      const lib = url.protocol === 'https:' ? https : http;
      const req = lib.request(opts, (res) => {
        const chunks: Buffer[] = [];
        res.on('data', (c: Buffer) => chunks.push(c));
        res.on('end', () => {
          const buf = Buffer.concat(chunks);
          const text = buf.toString('utf8');
          const ct = (res.headers['content-type'] || '') as string;
          let result: any = text;
          if (ct.includes('application/json')) {
            try { result = JSON.parse(text || '{}'); } catch (e) { /* ignore */ }
          }
          if ((res.statusCode || 0) >= 200 && (res.statusCode || 0) < 300) {
            resolve(result as T);
          } else {
            const err: any = new Error('HTTP Error: ' + res.statusCode);
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
    } catch (e) {
      reject(e);
    }
  });
}

export function get<T = any>(url: string, headers: Record<string,string> = {}) { return request<T>('GET', url, headers); }
export function post<T = any>(url: string, body: any, headers: Record<string,string> = {}) { return request<T>('POST', url, headers, body); }
export function put<T = any>(url: string, body: any, headers: Record<string,string> = {}) { return request<T>('PUT', url, headers, body); }
