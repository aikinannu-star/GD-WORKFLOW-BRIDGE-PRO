# Quick setup script for reference client certification (Windows)
# Usage: powershell -ExecutionPolicy Bypass -File setup.ps1 [-AuthUrl "http://localhost:8002"]

param(
    [string]$AuthUrl = "http://localhost:8002"
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$envFile = Join-Path $scriptDir ".env"

Write-Host "=================================================="
Write-Host "Reference Client Setup Script (Windows)"
Write-Host "=================================================="
Write-Host ""

# Check if auth service is accessible
Write-Host "1. Checking auth service at $AuthUrl..."
try {
    $healthResponse = Invoke-WebRequest -Uri "$AuthUrl/health" -TimeoutSec 5 -ErrorAction Stop
    if ($healthResponse.StatusCode -ne 200) {
        throw "Auth service returned status $($healthResponse.StatusCode)"
    }
    Write-Host "✓ Auth service is reachable"
} catch {
    Write-Host "❌ Auth service not reachable at $AuthUrl"
    Write-Host "   Make sure the auth service is running (port 8002)"
    Write-Host "   Error: $_"
    exit 1
}
Write-Host ""

# Generate JWT token
Write-Host "2. Logging in to generate JWT token..."
$body = @{
    email = "ci@example.com"
    password = "password123"
    tenant_id = "ci-tenant"
} | ConvertTo-Json

try {
    $response = Invoke-WebRequest -Uri "$AuthUrl/api/v1/auth/login" `
        -Method POST `
        -Headers @{"Content-Type" = "application/json"} `
        -Body $body `
        -TimeoutSec 10 `
        -ErrorAction Stop
    
    $json = $response.Content | ConvertFrom-Json
    $token = $json.token
    
    if (-not $token) {
        throw "No token in response"
    }
    
    Write-Host "✓ Token generated successfully"
    Write-Host "  Token: $($token.Substring(0, [Math]::Min(30, $token.Length)))..."
} catch {
    Write-Host "❌ Failed to generate token"
    Write-Host "Error: $_"
    exit 1
}
Write-Host ""

# Update .env file
Write-Host "3. Updating .env file..."
if (Test-Path $envFile) {
    $content = Get-Content $envFile
    $content = $content -replace '^API_TOKEN=.*', "API_TOKEN=$token"
    Set-Content $envFile $content -Encoding UTF8
    Write-Host "✓ .env file updated"
} else {
    Write-Host "❌ .env file not found at $envFile"
    exit 1
}
Write-Host ""

# Verify
Write-Host "4. Verifying .env configuration..."
$content = Get-Content $envFile
if ($content -match "API_TOKEN=$([regex]::Escape($token))") {
    Write-Host "✓ Configuration verified"
    Write-Host ""
    Write-Host "=================================================="
    Write-Host "✅ Setup Complete!"
    Write-Host "=================================================="
    Write-Host ""
    Write-Host "Run tests with:"
    Write-Host "  npm run ci:validate    (build + test)"
    Write-Host "  npm run test:workflows (test only)"
    Write-Host ""
    Write-Host "Token expires in 1 hour. Re-run this script if needed."
    Write-Host ""
} else {
    Write-Host "❌ Failed to verify .env configuration"
    exit 1
}
