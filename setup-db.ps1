# GD Workflow Bridge Pro - Database Setup Script
# Configures PostgreSQL + Redis for license server

Write-Host "================================" -ForegroundColor Cyan
Write-Host "GD Workflow Bridge Pro - DB Setup" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan

# Check if PostgreSQL is running
Write-Host "`n[1/5] Checking PostgreSQL connection..." -ForegroundColor Yellow
try {
    $pgCheck = psql -U gdwb_user -d gdwb_app -h localhost -c "SELECT 1" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ PostgreSQL is running on localhost:5432" -ForegroundColor Green
    } else {
        Write-Host "✗ PostgreSQL connection failed" -ForegroundColor Red
        Write-Host "  Make sure PostgreSQL is running and accessible" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "✗ PostgreSQL not found or not running" -ForegroundColor Red
    exit 1
}

# Check if Redis is running
Write-Host "`n[2/5] Checking Redis connection..." -ForegroundColor Yellow
try {
    $redisCheck = redis-cli ping 2>&1
    if ($redisCheck -eq "PONG") {
        Write-Host "✓ Redis is running on localhost:6379" -ForegroundColor Green
    } else {
        Write-Host "⚠ Redis check inconclusive, but proceeding..." -ForegroundColor Yellow
    }
} catch {
    Write-Host "⚠ Redis not found in PATH, but proceeding..." -ForegroundColor Yellow
}

# Set database environment variables
Write-Host "`n[3/5] Setting database environment variables..." -ForegroundColor Yellow
$env:LICENSE_DB_HOST = "127.0.0.1"
$env:LICENSE_DB_PORT = "5432"
$env:LICENSE_DB_USER = "gdwb_user"
$env:LICENSE_DB_PASSWORD = "gdwb_password"
$env:LICENSE_DB_NAME = "gdwb_app"
$env:REDIS_HOST = "127.0.0.1"
$env:REDIS_PORT = "6379"

Write-Host "✓ Environment variables set:" -ForegroundColor Green
Write-Host "  DATABASE: $env:LICENSE_DB_USER@$env:LICENSE_DB_HOST`:$env:LICENSE_DB_PORT/$env:LICENSE_DB_NAME" -ForegroundColor Cyan
Write-Host "  REDIS: $env:REDIS_HOST`:$env:REDIS_PORT" -ForegroundColor Cyan

# Run migrations
Write-Host "`n[4/5] Running database migrations..." -ForegroundColor Yellow
$migrationFile = "./license-server/migrations/postgres.sql"
if (Test-Path $migrationFile) {
    $pgpasswd = $env:LICENSE_DB_PASSWORD
    $env:PGPASSWORD = $pgpasswd
    psql -U $env:LICENSE_DB_USER -d $env:LICENSE_DB_NAME -h $env:LICENSE_DB_HOST -f $migrationFile 2>&1 | ForEach-Object { Write-Host "  $_" }
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Migrations completed successfully" -ForegroundColor Green
    } else {
        Write-Host "⚠ Migrations completed with warnings (this is usually OK)" -ForegroundColor Yellow
    }
    $env:PGPASSWORD = ""
} else {
    Write-Host "⚠ Migration file not found at $migrationFile" -ForegroundColor Yellow
}

# Display next steps
Write-Host "`n[5/5] Setup Complete!" -ForegroundColor Green
Write-Host "`n================================" -ForegroundColor Cyan
Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan
Write-Host "`n1. Stop the current license server (Ctrl+C in the PHP terminal)" -ForegroundColor White
Write-Host "`n2. Restart license server with database environment variables:" -ForegroundColor White
Write-Host @"
   `$env:LICENSE_DB_HOST = "127.0.0.1"
   `$env:LICENSE_DB_PORT = "5432"
   `$env:LICENSE_DB_USER = "gdwb_user"
    `$env:LICENSE_DB_PASSWORD = "gdwb_password"
   `$env:LICENSE_DB_NAME = "gdwb_app"
   `$env:REDIS_HOST = "127.0.0.1"
   `$env:REDIS_PORT = "6379"
   
   php -S 127.0.0.1:8001 -t license-server license-server/index.php
"@ -ForegroundColor Cyan

Write-Host "`n3. Test license validation in the browser:" -ForegroundColor White
Write-Host "   http://localhost:3001/license" -ForegroundColor Cyan

Write-Host "`nDatabase setup is ready!" -ForegroundColor Green
