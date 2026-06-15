param(
  [string]$Base = "http://127.0.0.1:8000"
)

Write-Host "Waiting for gateway health..."
$up = $false
for ($i=0; $i -lt 30; $i++) {
  try {
    $r = Invoke-RestMethod -Uri "$Base/health" -Method Get -TimeoutSec 2
    $up = $true; break
  } catch {
    Start-Sleep -Seconds 1
  }
}
if (-not $up) { Write-Error "gateway health failed"; exit 1 }

Write-Host "Checking license health..."
try { Invoke-RestMethod -Uri "$Base/api/v1/license/health" -Method Get -TimeoutSec 5 } catch { Write-Error "license health failed"; exit 1 }

Write-Host "Checking openapi..."
try {
  $openapi = (Invoke-WebRequest -Uri "$Base/api/v1/license/openapi.yaml" -Method Get -TimeoutSec 5).Content
} catch { Write-Error "openapi not found"; exit 1 }
if ($openapi -notmatch '^[\s]*openapi:') { Write-Error "openapi not found"; exit 1 }

Write-Host "Checking tenant protected returns 401 without token..."
$code = 0
try {
  $resp = Invoke-WebRequest -Uri "$Base/api/v1/tenant/health" -Method Get -TimeoutSec 5 -ErrorAction Stop
  $code = $resp.StatusCode
} catch [System.Net.WebException] {
  if ($_.Exception.Response) { $code = [int]($_.Exception.Response.StatusCode.value__ ) } else { $code = 0 }
} catch {
  $code = 0
}
if ($code -ne 401) { Write-Error "expected 401, got $code"; exit 1 }

Write-Host "Registering test user..."
$body = @{ tenant_id = 'ci-tenant'; email = 'ci@example.com'; password = 'password123' } | ConvertTo-Json
try {
  $json = Invoke-RestMethod -Uri "$Base/api/v1/auth/register" -Method Post -Body $body -ContentType 'application/json' -TimeoutSec 5
} catch {
  Write-Error "register failed: $_"; exit 1
}
$token = $json.token
if (-not $token) { Write-Error "failed to get token"; Write-Host ($json | ConvertTo-Json -Depth 4); exit 1 }

Write-Host "Checking tenant health with token..."
try {
  $resp = Invoke-WebRequest -Uri "$Base/api/v1/tenant/health" -Method Get -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 5 -ErrorAction Stop
  if ($resp.StatusCode -ne 200) { Write-Error "tenant health with auth failed: $($resp.StatusCode)"; exit 1 }
} catch {
  Write-Error "tenant health with auth failed: $_"; exit 1
}

Write-Host "Checking aggregate health contains license..."
try {
  $agg = Invoke-RestMethod -Uri "$Base/health/services" -Method Get -TimeoutSec 5
} catch { Write-Error "aggregate health fetch failed: $_"; exit 1 }
if (-not $agg.services.license) { Write-Error "aggregate missing license"; exit 1 }

Write-Host "All gateway smoke tests passed." 
