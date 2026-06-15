$log = Join-Path $env:TEMP "dism_run_latest.log"
"DISM run started: $(Get-Date -Format o)" | Out-File -FilePath $log -Encoding UTF8

try {
    & dism /Online /Cleanup-Image /CheckHealth 2>&1 | Out-File -FilePath $log -Append -Encoding UTF8
    "CheckHealth ExitCode: $LASTEXITCODE" | Out-File -FilePath $log -Append -Encoding UTF8
} catch {
    "CheckHealth failed: $_" | Out-File -FilePath $log -Append -Encoding UTF8
}

try {
    & dism /Online /Cleanup-Image /ScanHealth 2>&1 | Out-File -FilePath $log -Append -Encoding UTF8
    "ScanHealth ExitCode: $LASTEXITCODE" | Out-File -FilePath $log -Append -Encoding UTF8
} catch {
    "ScanHealth failed: $_" | Out-File -FilePath $log -Append -Encoding UTF8
}

try {
    & dism /Online /Cleanup-Image /RestoreHealth 2>&1 | Out-File -FilePath $log -Append -Encoding UTF8
    "RestoreHealth ExitCode: $LASTEXITCODE" | Out-File -FilePath $log -Append -Encoding UTF8
} catch {
    "RestoreHealth failed: $_" | Out-File -FilePath $log -Append -Encoding UTF8
}

"DISM run finished: $(Get-Date -Format o)" | Out-File -FilePath $log -Append -Encoding UTF8
Write-Output $log
