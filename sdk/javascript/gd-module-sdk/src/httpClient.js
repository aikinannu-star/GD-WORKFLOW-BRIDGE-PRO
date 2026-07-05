const http = require('http');
const https = require('https');
const { URL } = require('url');

function request(method, urlStr, headers = {}, body = null) {
  return new Promise((resolve, reject) => {
    try {
      const url = new URL(urlStr);
      const opts = {
        method,
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
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
          const ct = res.headers['content-type'] || '';
          let result = text;
          if (ct.includes('application/json')) {
            try {
              result = JSON.parse(text || '{}');
            } catch (e) {
              // fall through
            }
          }
          if (res.statusCode >= 200 && res.statusCode < 300) {
            resolve(result);
          } else {
            const err = new Error('HTTP Error: ' + res.statusCode);
            err.status = res.statusCode;
            err.body = result;
            reject(err);
          }
        });
      });
      req.on('error', (err) => reject(err));
      if (body) {
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

function get(url, headers) { return request('GET', url, headers); }
function post(url, body, headers) { return request('POST', url, headers, body); }
function put(url, body, headers) { return request('PUT', url, headers, body); }

module.exports = { get, post, put, request };
