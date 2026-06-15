Write-Host "GD Workflow Bridge Pro - Database Setup" -ForegroundColor Cyan
Write-Host "=======================================" -ForegroundColor Cyan

Write-Host "`n[1/3] Checking PostgreSQL..." -ForegroundColor Yellow
try {
    psql -U gdwb_user -d gdwb_app -h localhost -c "SELECT 1" 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "OK - PostgreSQL running" -ForegroundColor Green
    }
}
catch {
    Write-Host "Error: $_" -ForegroundColor Red
}

Write-Host "`n[2/3] Setting environment variables..." -ForegroundColor Yellow
$env:LICENSE_DB_HOST = "127.0.0.1"
$env:LICENSE_DB_PORT = "5432"
$env:LICENSE_DB_USER = "gdwb_user"
$env:LICENSE_DB_PASS = "gdwb_password"
$env:LICENSE_DB_NAME = "gdwb_app"
$env:REDIS_HOST = "127.0.0.1"
$env:REDIS_PORT = "6379"
Write-Host "OK - Variables set" -ForegroundColor Green

Write-Host "`n[3/3] Running database migrations..." -ForegroundColor Yellow
$env:PGPASSWORD = "gdwb_password"
psql -U gdwb_user -d gdwb_app -h localhost -f "./license-server/migrations/postgres.sql" 2>&1
$env:PGPASSWORD = ""
Write-Host "OK - Migrations complete" -ForegroundColor Green

Write-Host "`n=======================================" -ForegroundColor Cyan
Write-Host "Setup Complete!" -ForegroundColor Green
Write-Host "=======================================" -ForegroundColor Cyan
Write-Host "`nNext: Restart license server with DB vars" -ForegroundColor Yellow
