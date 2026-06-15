#!/bin/bash
# Startup script for complete SaaS platform

set -e

echo "=== GDWB SaaS Platform Startup ==="

# Check Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is required. Please install Docker."
    exit 1
fi

# Check Docker Compose
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is required. Please install Docker Compose."
    exit 1
fi

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '#' | xargs)
fi

# Start all services
echo "🚀 Starting all services..."
docker-compose -f infra/docker/docker-compose.yml up -d

echo "⏳ Waiting for services to be healthy..."
sleep 10

# Run database migrations
echo "🗄️  Running database migrations..."
docker-compose exec -T postgres psql -U user -d gdwb_app -f /docker-entrypoint-initdb.d/001_init_platform.sql

# Verify all services are running
echo "✅ Checking service health..."
services=("license-server:8001" "auth-service:8002" "billing-service:8003" "cms-service:8004" "domains-service:8005" "marketplace-service:8006" "usage-service:8007" "admin-service:8008")

for service in "${services[@]}"; do
    host="${service%:*}"
    port="${service##*:}"
    if curl -s http://localhost:$port/health > /dev/null; then
        echo "  ✅ $host (port $port)"
    else
        echo "  ❌ $host (port $port) - not responding"
    fi
done

echo ""
echo "=== Platform Started Successfully ==="
echo ""
echo "API Gateway:        http://localhost"
echo "License Server:     http://localhost:8001"
echo "Auth Service:       http://localhost:8002"
echo "Billing Service:    http://localhost:8003"
echo "CMS Service:        http://localhost:8004"
echo "Domains Service:    http://localhost:8005"
echo "Marketplace:        http://localhost:8006"
echo "Usage Service:      http://localhost:8007"
echo "Admin Service:      http://localhost:8008"
echo ""
echo "PostgreSQL:         localhost:5432"
echo "Redis:              localhost:6379"
echo ""
echo "To stop all services: docker-compose -f infra/docker/docker-compose.yml down"
echo "To view logs: docker-compose -f infra/docker/docker-compose.yml logs -f"
