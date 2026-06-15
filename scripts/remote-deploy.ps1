# Remote Deploy PowerShell Script
# Deploy the current repository to a remote host and run `docker compose up -d --build` there.
#
# Requirements:
#  - Local: OpenSSH (built-in on Windows 10+) or Git Bash
#  - Remote: Docker and docker compose
#
# Usage:
#  .\scripts\remote-deploy.ps1 -HostName myhost.example.com -UserName ubuntu -Port 22 -RemoteDir /home/ubuntu/gd-workflow-bridge-pro
#
# Example:
#  .\scripts\remote-deploy.ps1 -HostName 192.168.1.10 -UserName ubuntu -RemoteDir ~/gd-workflow-bridge-pro

param(
    [Parameter(Mandatory=$true, HelpMessage="Remote SSH host or IP")]
    [string]$HostName,
    
    [Parameter(Mandatory=$false, HelpMessage="SSH username (default: current user)")]
    [string]$UserName = ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name -split '\\')[-1],
    
    [Parameter(Mandatory=$false, HelpMessage="SSH port (default: 22)")]
    [int]$Port = 22,
    
    [Parameter(Mandatory=$false, HelpMessage="Remote directory for repo (default: ~/gd-workflow-bridge-pro)")]
    [string]$RemoteDir = "~/gd-workflow-bridge-pro",
    
    [Parameter(Mandatory=$false, HelpMessage="Skip upload if archive already on remote")]
    [switch]$SkipUpload,
    
    [Parameter(Mandatory=$false, HelpMessage="Keep remote tarball after extract")]
    [switch]$KeepRemoteTar,
    
    [Parameter(Mandatory=$false, HelpMessage="Do not clean local archive")]
    [switch]$NoCleanup
)

$ErrorActionPreference = "Stop"

function Test-SshConnection {
    param([string]$Host, [int]$Port)
    try {
        $null = ssh -p $Port -o BatchMode=yes -o ConnectTimeout=5 "${UserName}@${Host}" "echo ok" 2>$null
        return $?
    }
    catch {
        return $false
    }
}

function New-Archive {
    param([string]$OutputPath)
    Write-Host "Creating archive..."
    
    # Use git archive if available, else tar-like compression
    $gitAvailable = (git --version 2>$null)
    if ($gitAvailable -and (Test-Path .git)) {
        Write-Host "Using git archive..."
        git archive --format=tar HEAD | & tar -xzf - -O > $OutputPath
    }
    else {
        Write-Host "Compressing working tree..."
        # PowerShell 5.1+ Compress-Archive (exclude .git)
        $tempDir = [System.IO.Path]::GetTempFileName()
        Remove-Item $tempDir -Force
        New-Item -ItemType Directory -Path $tempDir | Out-Null
        
        # Copy repo excluding .git
        Copy-Item -Path .\ -Destination "$tempDir\repo" -Recurse -Exclude @('.git', '.gitignore', '.DS_Store', 'node_modules', 'vendor')
        
        Compress-Archive -Path "$tempDir\repo\*" -DestinationPath $OutputPath -CompressionLevel Optimal
        Remove-Item -Path $tempDir -Recurse -Force
    }
    
    if (Test-Path $OutputPath) {
        Write-Host "Archive created: $OutputPath"
        return $OutputPath
    }
    else {
        throw "Failed to create archive"
    }
}

function Invoke-RemoteCommand {
    param([string]$Command)
    ssh -p $Port "${UserName}@${HostName}" $Command
}

# Verify SSH connectivity
Write-Host "Testing SSH connection to ${UserName}@${HostName}:${Port}..."
if (-not (Test-SshConnection -Host $HostName -Port $Port)) {
    Write-Warning "SSH connection may require interactive auth. Attempting to continue..."
}

# Create archive
$timestamp = [int][double]::Parse((Get-Date -UFormat %s))
$archivePath = Join-Path $env:TEMP "gdwb-deploy-${timestamp}.zip"
$archivePath = New-Archive -OutputPath $archivePath

# Upload if needed
$remoteTmpPath = "/tmp/$(Split-Path -Leaf $archivePath)"
if (-not $SkipUpload) {
    Write-Host "Uploading archive to ${UserName}@${HostName}:${remoteTmpPath}..."
    scp -P $Port $archivePath "${UserName}@${HostName}:${remoteTmpPath}"
}
else {
    Write-Host "Skipping upload; assuming archive at $remoteTmpPath"
}

# Extract and deploy on remote
Write-Host "Extracting and starting Docker Compose on remote..."
$remoteScript = @"
set -euo pipefail
mkdir -p $RemoteDir
cd /tmp
# Handle .zip vs .tar.gz
if [[ '$remoteTmpPath' == *.zip ]]; then
  unzip -q '$remoteTmpPath' -d '$RemoteDir'
  # unzip extracts to a subdirectory; move contents up if needed
  if [ -d '$RemoteDir/repo' ]; then
    mv '$RemoteDir/repo'/* '$RemoteDir/' 2>/dev/null || true
    rmdir '$RemoteDir/repo' 2>/dev/null || true
  fi
else
  tar -xzf '$remoteTmpPath' -C '$RemoteDir'
fi
cd '$RemoteDir'
# Detect docker compose command
if command -v docker >/dev/null; then
  if docker compose version >/dev/null 2>&1; then
    docker compose pull || true
    docker compose up -d --build --remove-orphans
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose pull || true
    docker-compose up -d --build --remove-orphans
  else
    echo 'docker-compose not found' >&2
    exit 4
  fi
else
  echo 'docker not found on remote' >&2
  exit 5
fi
"@

Invoke-RemoteCommand -Command $remoteScript

# Cleanup
if (-not $KeepRemoteTar) {
    Write-Host "Removing remote archive..."
    Invoke-RemoteCommand -Command "rm -f $remoteTmpPath"
}

if (-not $NoCleanup) {
    Write-Host "Removing local archive..."
    Remove-Item -Path $archivePath -Force -ErrorAction SilentlyContinue
}

Write-Host "Deploy complete!"
Write-Host "Remote path: $RemoteDir"
Write-Host ""
Write-Host "To check services:"
Write-Host "  ssh -p $Port ${UserName}@${HostName} 'cd $RemoteDir && docker compose ps'"
Write-Host ""
Write-Host "To view logs:"
Write-Host "  ssh -p $Port ${UserName}@${HostName} 'cd $RemoteDir && docker compose logs -f gateway-service'"
