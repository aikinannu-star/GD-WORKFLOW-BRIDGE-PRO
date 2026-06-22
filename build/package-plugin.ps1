<#
Build script for Windows/PowerShell environments.
Creates a clean plugin package zip in the `release/` folder.
#>
[CmdletBinding()]
param()

$pluginSlug = 'gd-workflow-bridge-pro'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
$repoRoot = (Resolve-Path (Join-Path $scriptDir '..')).Path
$buildDir = Join-Path $repoRoot "build\$pluginSlug"
$releaseDir = Join-Path $repoRoot 'release'
$zipPath = Join-Path $releaseDir "$pluginSlug.zip"

Write-Host "Repo root: $repoRoot"
Write-Host "Building plugin: $pluginSlug"

# Run composer if available
if (Get-Command composer -ErrorAction SilentlyContinue) {
    Write-Host "Running composer install --no-dev ..."
    Push-Location $repoRoot
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    Pop-Location
} else {
    Write-Host "composer not found: skipping vendor install. Ensure vendor/ exists if you need dependencies vendored."
}

if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir -ErrorAction SilentlyContinue }
New-Item -ItemType Directory -Path $buildDir -Force | Out-Null
New-Item -ItemType Directory -Path $releaseDir -Force | Out-Null

$items = @('gd-workflow-bridge-pro.php','readme.txt','includes','assets','languages','templates','vendor','composer.json','composer.lock','phinx.php','phpcs.xml')
foreach ($item in $items) {
    $src = Join-Path $repoRoot $item
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination $buildDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# cleanup sensitive / unnecessary directories
$removes = @('keys','.env','.github','tests','license-server','services','infra')
foreach ($r in $removes) {
    $p = Join-Path $buildDir $r
    if (Test-Path $p) { Remove-Item -Recurse -Force $p -ErrorAction SilentlyContinue }
}

if (Test-Path $zipPath) { Remove-Item -Force $zipPath -ErrorAction SilentlyContinue }

# Create ZIP containing the plugin folder
Push-Location (Join-Path $repoRoot 'build')
if (Test-Path "$pluginSlug") {
    Compress-Archive -LiteralPath $pluginSlug -DestinationPath $zipPath -Force
} else {
    Write-Error "Build folder not found: $pluginSlug"
    Pop-Location
    exit 1
}
Pop-Location

Write-Host "Package created: $zipPath"
Get-ChildItem $zipPath | Select-Object Name, Length, LastWriteTime
