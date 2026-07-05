#!/bin/bash
# Quick setup script for reference client certification
# Usage: bash setup.sh [auth-service-url]

set -e

AUTH_URL="${1:-http://localhost:8002}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"

echo "=================================================="
echo "Reference Client Setup Script"
echo "=================================================="
echo ""

# Check if auth service is accessible
echo "1. Checking auth service at $AUTH_URL..."
if ! curl -sf "$AUTH_URL/health" > /dev/null 2>&1; then
    echo "❌ Auth service not reachable at $AUTH_URL"
    echo "   Make sure the auth service is running (port 8002)"
    exit 1
fi
echo "✓ Auth service is reachable"
echo ""

# Generate JWT token
echo "2. Logging in to generate JWT token..."
RESPONSE=$(curl -s -X POST "$AUTH_URL/api/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d '{
        "email": "ci@example.com",
        "password": "password123",
        "tenant_id": "ci-tenant"
    }')

# Extract token
TOKEN=$(echo "$RESPONSE" | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ -z "$TOKEN" ]; then
    echo "❌ Failed to generate token"
    echo "Response: $RESPONSE"
    exit 1
fi

echo "✓ Token generated successfully"
echo "  Token: ${TOKEN:0:30}..."
echo ""

# Update .env file
echo "3. Updating .env file..."
if [ -f "$ENV_FILE" ]; then
    # Use sed to replace the API_TOKEN value
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s/^API_TOKEN=.*/API_TOKEN=$TOKEN/" "$ENV_FILE"
    else
        # Linux
        sed -i "s/^API_TOKEN=.*/API_TOKEN=$TOKEN/" "$ENV_FILE"
    fi
    echo "✓ .env file updated"
else
    echo "❌ .env file not found at $ENV_FILE"
    exit 1
fi
echo ""

# Verify
echo "4. Verifying .env configuration..."
if grep -q "API_TOKEN=$TOKEN" "$ENV_FILE"; then
    echo "✓ Configuration verified"
    echo ""
    echo "=================================================="
    echo "✅ Setup Complete!"
    echo "=================================================="
    echo ""
    echo "Run tests with:"
    echo "  npm run ci:validate    (build + test)"
    echo "  npm run test:workflows (test only)"
    echo ""
    echo "Token expires in 1 hour. Re-run this script if needed."
    echo ""
else
    echo "❌ Failed to verify .env configuration"
    exit 1
fi
