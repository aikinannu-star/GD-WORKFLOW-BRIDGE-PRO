// Simple SSE-based realtime prototype (no external deps)
const http = require('http');
const url = require('url');
const PORT = process.env.PORT ? parseInt(process.env.PORT) : 8012;

const channels = new Map();

function sendEvent(res, event, data) {
  res.write('event: ' + event + '\n');
  res.write('data: ' + JSON.stringify(data) + '\n\n');
}

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url, true);
  if (req.method === 'GET' && parsed.pathname === '/sse') {
    const channel = parsed.query.channel || 'global';
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'Access-Control-Allow-Origin': '*'
    });
    res.write(':ok\n\n');

    if (!channels.has(channel)) channels.set(channel, []);
    channels.get(channel).push(res);

    req.on('close', () => {
      const arr = channels.get(channel) || [];
      const idx = arr.indexOf(res);
      if (idx !== -1) arr.splice(idx, 1);
    });

    return;
  }

  if (req.method === 'POST' && parsed.pathname === '/publish') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      let payload;
      try { payload = JSON.parse(body); } catch (e) { res.writeHead(400); res.end(JSON.stringify({ error: 'invalid_json' })); return; }
      const channel = payload.channel || 'global';
      const event = payload.event || 'message';
      const data = payload.data || {};
      const subs = channels.get(channel) || [];
      subs.forEach(r => sendEvent(r, event, data));
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, delivered: subs.length }));
    });
    return;
  }

  if (req.method === 'OPTIONS') {
    res.writeHead(204, { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Methods': 'GET,POST,OPTIONS', 'Access-Control-Allow-Headers': 'Content-Type' });
    res.end();
    return;
  }

  res.writeHead(404);
  res.end('not_found');
});

server.listen(PORT, () => console.log(`Realtime SSE server listening on ${PORT}`));
