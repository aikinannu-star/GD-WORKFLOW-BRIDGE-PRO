param(
    [string] $Host = '127.0.0.1',
    [int] $Port = 8001
)

Write-Output "Starting local services (Redis + Postgres) via docker-compose..."
docker-compose up -d redis postgres

Write-Output "Waiting for Postgres to accept connections..."
while ($true) {
    try {
        & psql -h 127.0.0.1 -U ${env:POSTGRES_USER:-gdwb_user} -d ${env:POSTGRES_DB:-gdwb_app} -c '\q' -W:$false 2>$null
        break
    } catch {
        Start-Sleep -Seconds 1
    }
}

Write-Output "Starting PHP dev server (license-server) on http://$Host:$Port"
$env:LICENSE_DB_DSN = "pgsql:host=127.0.0.1;port=5432;dbname=${env:POSTGRES_DB:-gdwb_app}";
$env:LICENSE_DB_USER = ${env:POSTGRES_USER:-gdwb_user};
$env:LICENSE_DB_PASSWORD = ${env:POSTGRES_PASSWORD:-/FdCDrG6wWczmjJvgXl28w==};

# Pushgateway defaults for local development (editable via environment)
if (-not $env:PUSHGATEWAY_URL) { $env:PUSHGATEWAY_URL = 'http://localhost:9091' }
if (-not $env:PUSHGATEWAY_JOB) { $env:PUSHGATEWAY_JOB = 'license_server' }
if (-not $env:PUSHGATEWAY_INSTANCE) { $env:PUSHGATEWAY_INSTANCE = "dev-$($env:COMPUTERNAME)" }
Write-Output "Using Pushgateway: $env:PUSHGATEWAY_URL (job=$env:PUSHGATEWAY_JOB instance=$env:PUSHGATEWAY_INSTANCE)"

& php -S $Host:$Port -t license-server
