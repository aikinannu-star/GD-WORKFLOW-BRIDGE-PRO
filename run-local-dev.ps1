<#
run-local-dev.ps1 - Start local backend services without Docker

Usage: pwsh -NoProfile -ExecutionPolicy Bypass -File .\run-local-dev.ps1 [--NoDbStart]
#>

param(
    [switch]$NoDbStart
)

$root = Split-Path -Parent $MyInvocation.MyCommand.Definition
Set-Location $root

$logs = Join-Path $root 'logs'
if (-not (Test-Path $logs)) { New-Item -ItemType Directory -Path $logs | Out-Null }

function Write-Info([string]$m) { Write-Host "[*] $m" -ForegroundColor Cyan }
function Write-Err([string]$m) { Write-Host "[!] $m" -ForegroundColor Red }

function Command-Exists([string]$cmd) {
    return $null -ne (Get-Command $cmd -ErrorAction SilentlyContinue)
}

function Wait-ForTcpPort([string]$hostname, [int]$port, [int]$timeoutSeconds=30) {
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($sw.Elapsed.TotalSeconds -lt $timeoutSeconds) {
        try {
            $tcp = New-Object System.Net.Sockets.TcpClient
            $iar = $tcp.BeginConnect($hostname, $port, $null, $null)
            if ($iar.AsyncWaitHandle.WaitOne(500)) {
                try { $tcp.EndConnect($iar) } catch { }
                $tcp.Close()
                return $true
            }
            $tcp.Close()
        } catch { }
        Start-Sleep -Milliseconds 250
    }
    return $false
}

# Default local DB/Redis settings (match existing run-dev defaults)
$POSTGRES_USER = "gdwb_user"
$POSTGRES_PASSWORD = "/FdCDrG6wWczmjJvgXl28w=="
$POSTGRES_DB = "gdwb_app"
$PG_HOST = "127.0.0.1"
$PG_PORT = 5432
$REDIS_HOST = "127.0.0.1"
$REDIS_PORT = 6379

if (-not $NoDbStart) {
    Write-Info "Checking Postgres at $($PG_HOST):$PG_PORT..."
    if (-not (Wait-ForTcpPort $PG_HOST $PG_PORT 2)) {
        Write-Info "Postgres not responding on $($PG_HOST):$PG_PORT"
        if (Command-Exists "pg_ctl") {
            Write-Info "pg_ctl found but automatic start without a data directory is unsafe. Please start Postgres manually."
        } else {
            if ($IsWindows) {
                $svc = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'postgres' -or $_.DisplayName -match 'PostgreSQL' } | Select-Object -First 1
                if ($svc) {
                    if ($svc.Status -ne 'Running') { Write-Info "Starting service $($svc.Name)..."; Start-Service -Name $svc.Name -ErrorAction SilentlyContinue }
                    if (Wait-ForTcpPort $PG_HOST $PG_PORT 30) { Write-Info "Postgres is now reachable." } else { Write-Err "Failed to reach Postgres after starting service. Please start it manually." }
                } else {
                    Write-Err "No Postgres service found. Please install/start Postgres manually."
                }
            } else {
                Write-Err "Postgres not found and cannot be auto-started. Please start Postgres on $($PG_HOST):$PG_PORT (system service or pg_ctl)."
            }
        }
    } else {
        Write-Info "Postgres OK."
    }

    Write-Info "Checking Redis at $($REDIS_HOST):$REDIS_PORT..."
    if (-not (Wait-ForTcpPort $REDIS_HOST $REDIS_PORT 2)) {
        if (Command-Exists "redis-server") {
            Write-Info "Starting redis-server in background..."
            $redisLog = Join-Path $logs "redis.log"
            Start-Process -NoNewWindow -FilePath "redis-server" -ArgumentList "--port $REDIS_PORT" -RedirectStandardOutput $redisLog -RedirectStandardError $redisLog
            if (Wait-ForTcpPort $REDIS_HOST $REDIS_PORT 20) { Write-Info "Redis started." } else { Write-Err "Redis did not respond after start attempt." }
        } else {
            Write-Err "Redis not available. Install and run redis-server or run Redis as a service."
        }
    } else {
        Write-Info "Redis OK."
    }
} else {
    Write-Info "Skipping DB/Redis checks because -NoDbStart passed."
}

# Export environment variables for services
$env:LICENSE_DB_DSN = "pgsql:host=$PG_HOST;port=$PG_PORT;dbname=$POSTGRES_DB"
$env:LICENSE_DB_USER = $POSTGRES_USER
$env:LICENSE_DB_PASSWORD = $POSTGRES_PASSWORD
$env:REDIS_HOST = $REDIS_HOST
$env:REDIS_PORT = $REDIS_PORT

# Define PHP services to run
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
    @{name='realtime'; port=8012; dir='services/realtime'; file='services/realtime/server.php'},
    @{name='website-builder'; port=8013; dir='services/website-builder'; file='services/website-builder/server.php'},
    @{name='mobile-builder'; port=8014; dir='services/mobile-builder'; file='services/mobile-builder/server.php'},
    @{name='desktop-builder'; port=8015; dir='services/desktop-builder'; file='services/desktop-builder/server.php'},
    @{name='workflow'; port=8016; dir='services/workflow'; file='services/workflow/server.php'},
    @{name='assistant'; port=8017; dir='services/assistant'; file='services/assistant/server.php'},
    @{name='dispatcher'; port=8020; dir='services/dispatcher'; file='services/dispatcher/server.php'},
    @{name='analytics'; port=8018; dir='services/analytics'; file='services/analytics/server.php'},
    @{name='deployment'; port=8019; dir='services/deployment'; file='services/deployment/server.php'}
)

Write-Info "Starting PHP services (logs -> $logs)"
foreach ($s in $services) {
    $outLog = Join-Path $logs "$($s.name).log"
    $errLog = Join-Path $logs "$($s.name).err"
    $args = "-S 0.0.0.0:$($s.port) -t $($s.dir) $($s.file)"
    Write-Info "Starting $($s.name) on port $($s.port)..."
    Start-Process -NoNewWindow -FilePath "php" -ArgumentList $args -RedirectStandardOutput $outLog -RedirectStandardError $errLog
    Start-Sleep -Milliseconds 300
}

# Wait for services to become available
foreach ($s in $services) {
    Write-Info "Waiting for $($s.name) on port $($s.port)..."
    if (Wait-ForTcpPort "127.0.0.1" $s.port 10) { Write-Info "$($s.name) is up." } else { Write-Err "$($s.name) did not respond." }
}

Write-Host ""
Write-Host "Done. PHP processes started; check logs in $logs. Use Get-Process php or task manager to find/stop PHP processes."
