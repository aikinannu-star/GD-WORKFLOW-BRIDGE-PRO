# Realtime (SSE) Service (MVP)

Simple Server-Sent-Events (SSE) prototype. No external Node.js dependencies required.

Start (requires Node.js):

```powershell
node services/realtime/server.js
```

Endpoints:

- `GET /sse?channel=...` — connect via `EventSource` to receive events for a channel
- `POST /publish` — publish an event (JSON: `channel`, `event`, `data`)

Notes:

- This prototype uses SSE to avoid adding WebSocket dependencies. SSE cannot be proxied through the current gateway implementation (it buffers responses). Connect clients directly to this service or update the gateway to support streaming proxying.
- For production, consider a WebSocket server or a managed realtime provider (Pusher, Ably) and integrate with pub/sub (Redis, NATS).
