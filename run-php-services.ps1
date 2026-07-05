# Starts local PHP built-in servers for core services for quick dev (no Docker required)
param()

$root = Split-Path -Parent $MyInvocation.MyCommand.Definition
Set-Location $root

$logs = Join-Path $root 'logs'
if (-not (Test-Path $logs)) { New-Item -ItemType Directory -Path $logs | Out-Null }

$env:GATEWAY_USE_LOCALHOST = '1'
$env:AUTH_JWT_SECRET = 'local-development-secret'
$env:GATEWAY_AUTH_BASE = 'http://127.0.0.1:8002'
$env:GATEWAY_TENANT_BASE = 'http://127.0.0.1:8009'
$env:GATEWAY_CMS_BASE = 'http://127.0.0.1:8004'
$env:GATEWAY_BILLING_BASE = 'http://127.0.0.1:8003'
$env:GATEWAY_MARKETPLACE_BASE = 'http://127.0.0.1:8006'
$env:GATEWAY_MEDIA_BASE = 'http://127.0.0.1:8010'
$env:GATEWAY_SOCIAL_BASE = 'http://127.0.0.1:8008'
$env:GATEWAY_FEED_BASE = 'http://127.0.0.1:8011'
$env:GATEWAY_REALTIME_BASE = 'http://127.0.0.1:8012'
$env:GATEWAY_USAGE_BASE = 'http://127.0.0.1:8007'
$env:GATEWAY_LICENSE_BASE = 'http://127.0.0.1:8001'

$services = @(
    @{name='gateway'; port=8000; dir='services/gateway'; file='services/gateway/server.php'},
    @{name='license'; port=8001; dir='license-server'; file='license-server/server.php'},
    @{name='auth'; port=8002; dir='services/auth'; file='services/auth/server.php'},
    @{name='billing'; port=8003; dir='services/billing'; file='services/billing/server.php'},
    @{name='cms'; port=8004; dir='services/cms'; file='services/cms/server.php'},
    @{name='marketplace'; port=8006; dir='services/marketplace'; file='services/marketplace/server.php'},
    @{name='usage'; port=8007; dir='services/usage'; file='services/usage/server.php'},
    @{name='social'; port=8008; dir='services/social'; file='services/social/server.php'},
    @{name='tenant'; port=8009; dir='services/tenant'; file='services/tenant/server.php'},
    @{name='media'; port=8010; dir='services/media'; file='services/media/server.php'},
    @{name='feed'; port=8011; dir='services/feed'; file='services/feed/server.php'},
    @{name='realtime'; port=8012; dir='services/realtime'; file='services/realtime/server.php'}
)

Write-Host "Starting PHP services in background (logs -> $logs)"

foreach ($s in $services) {
    $outLog = Join-Path $logs "$($s.name).log"
    $errLog = Join-Path $logs "$($s.name).err"
    $args = "-S 0.0.0.0:$($s.port) -t $($s.dir) $($s.file)"
    Write-Host "Starting $($s.name) on port $($s.port)..."
    Start-Process -NoNewWindow -FilePath "php" -ArgumentList $args -RedirectStandardOutput $outLog -RedirectStandardError $errLog
}

Write-Host "All service processes started. Use `Get-Process php` to inspect or stop them as needed."