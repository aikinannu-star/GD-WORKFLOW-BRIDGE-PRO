Param(
    [Parameter(Mandatory=$true)][string]$ClientId,
    [string]$VaultAddr = $env:VAULT_ADDR,
    [string]$VaultToken = $env:VAULT_TOKEN,
    [string]$VaultPath = $env:VAULT_SECRET_PATH
)

if (-not $VaultAddr) { Write-Error "VAULT_ADDR not set"; exit 1 }
if (-not $VaultToken) { Write-Error "VAULT_TOKEN not set"; exit 1 }
if (-not $VaultPath) { $VaultPath = 'secret/data/gdwb' }

# $phpExe should be on PATH
$phpExe = 'php'
$genOutput = & $phpExe "license-server/generate_client.php" $ClientId 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "generate_client failed"
    Write-Output $genOutput
    exit 2
}

# parse secret from output
$secretLine = $genOutput | Select-String -Pattern 'Secret \(store securely, shown once\):\s*(.+)$' | ForEach-Object { $_.Matches[0].Groups[1].Value } | Select-Object -First 1
if (-not $secretLine) {
    Write-Error "Could not parse secret from generate_client output"
    Write-Output $genOutput
    exit 3
}
$secret = $secretLine.Trim()

$norm = $ClientId.ToUpper() -replace '[^A-Z0-9]','_'
$keyName = "CLIENT_${norm}_SECRET"

$body = @{ data = @{ } }
$body.data[$keyName] = $secret
$json = $body | ConvertTo-Json -Depth 10

$uri = $VaultAddr.TrimEnd('/') + '/v1/' + $VaultPath.TrimStart('/')
try {
    $resp = Invoke-RestMethod -Uri $uri -Method Post -Headers @{ 'X-Vault-Token' = $VaultToken } -Body $json -ContentType 'application/json'
    Write-Host "Stored secret for $ClientId at $uri"
} catch {
    Write-Error "Failed to write secret to Vault: $_"
    exit 4
}

Write-Host "Secret: $secret"
