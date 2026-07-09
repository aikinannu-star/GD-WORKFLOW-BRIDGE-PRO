#!/bin/bash
# Ollama model initialization script
# Ensures required models are available before assistant service starts
# Usage: ./ollama-init.sh [model_name] [ollama_url] [max_wait_seconds]

set -e

MODEL="${1:-gemma:2b}"
OLLAMA_URL="${2:-http://localhost:11434}"
MAX_WAIT="${3:-300}"
WAIT_INTERVAL=5
ELAPSED=0

echo "[ollama-init] Initializing Ollama with model: $MODEL"
echo "[ollama-init] Ollama URL: $OLLAMA_URL"
echo "[ollama-init] Max wait time: ${MAX_WAIT}s"

# Wait for Ollama to be healthy
echo "[ollama-init] Waiting for Ollama to be ready..."
while [ $ELAPSED -lt $MAX_WAIT ]; do
    if curl -sf "$OLLAMA_URL/api/tags" > /dev/null 2>&1; then
        echo "[ollama-init] Ollama is healthy"
        break
    fi
    echo "[ollama-init] Ollama not ready yet... (${ELAPSED}s/${MAX_WAIT}s)"
    sleep $WAIT_INTERVAL
    ELAPSED=$((ELAPSED + WAIT_INTERVAL))
done

if [ $ELAPSED -ge $MAX_WAIT ]; then
    echo "[ollama-init] ERROR: Ollama did not become healthy within ${MAX_WAIT}s"
    exit 1
fi

# Check if model is already available
echo "[ollama-init] Checking if $MODEL is available..."
MODELS=$(curl -sf "$OLLAMA_URL/api/tags" | grep -o '"name":"[^"]*"' | cut -d'"' -f4 || true)

if echo "$MODELS" | grep -q "^${MODEL}$"; then
    echo "[ollama-init] Model $MODEL is already available"
    exit 0
fi

# Pull the model if not available
echo "[ollama-init] Model $MODEL not found, pulling..."
if curl -X POST "$OLLAMA_URL/api/pull" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"$MODEL\",\"stream\":false}" \
    2>/dev/null | grep -q '"status":"success"'; then
    echo "[ollama-init] Successfully pulled $MODEL"
else
    echo "[ollama-init] ERROR: Failed to pull $MODEL"
    exit 1
fi

# Wait until the model can answer a minimal prompt.
echo "[ollama-init] Waiting for $MODEL to become responsive..."
PROBE_PAYLOAD="{\"model\":\"$MODEL\",\"prompt\":\"ping\",\"stream\":false}"
ELAPSED=0
while [ "$ELAPSED" -lt "$MAX_WAIT" ]; do
    if curl -sf -X POST "$OLLAMA_URL/api/generate" \
        -H "Content-Type: application/json" \
        -d "$PROBE_PAYLOAD" > /dev/null 2>&1; then
        echo "[ollama-init] Model $MODEL is now responding"
        exit 0
    fi

    echo "[ollama-init] Model $MODEL still warming up... (${ELAPSED}s/${MAX_WAIT}s)"
    sleep "$WAIT_INTERVAL"
    ELAPSED=$((ELAPSED + WAIT_INTERVAL))
done

echo "[ollama-init] ERROR: Model $MODEL did not become responsive within ${MAX_WAIT}s"
exit 1
