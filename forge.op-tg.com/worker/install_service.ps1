# Install Leads Worker as a Windows Service using sc.exe (runs worker.exe with auto-restart via service)
# Run PowerShell as Administrator
param(
  [string]$ServiceName = 'LeadsWorker',
  [string]$DisplayName = 'Leads Worker',
  [string]$Description = 'Leads internal worker that pulls jobs and processes maps',
  [string]$InstallDir = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'

# Prepare logs directory
$logs = Join-Path $InstallDir 'logs'
if (!(Test-Path $logs)) { New-Item -ItemType Directory -Force -Path $logs | Out-Null }

# We support three modes for the service command:
# 1) worker.exe present (preferred)
# 2) embedded portable Node at .\node\node.exe running index.js
# 3) system Node fallback running index.js
$exe = Join-Path $InstallDir 'worker.exe'
$embeddedNode = Join-Path $InstallDir 'node\node.exe'
$bat = Join-Path $InstallDir 'worker_service.bat'
if (!(Test-Path $bat)) { throw "worker_service.bat not found in $InstallDir" }

# sc.exe requires a wrapper when the process is console and not service-aware. We'll use srvany-like via NSSM if available.
$hasNssm = $null -ne (Get-Command nssm -ErrorAction SilentlyContinue)
if ($hasNssm) {
  Write-Host 'Installing service via NSSM...'
  # Point service to worker_service.bat (handles all fallbacks and logging)
  & nssm install $ServiceName (Join-Path $InstallDir 'worker_service.bat') | Out-Host
  & nssm set $ServiceName DisplayName $DisplayName | Out-Host
  & nssm set $ServiceName Description $Description | Out-Host
  & nssm set $ServiceName AppDirectory $InstallDir | Out-Host
  & nssm set $ServiceName Start SERVICE_AUTO_START | Out-Host
  & nssm set $ServiceName AppStdout (Join-Path $InstallDir 'logs\\service-out.log') | Out-Host
  & nssm set $ServiceName AppStderr (Join-Path $InstallDir 'logs\\service-err.log') | Out-Host
  & nssm set $ServiceName AppExit Default Restart | Out-Host
  & nssm set $ServiceName AppRestartDelay 60000 | Out-Host
  & nssm set $ServiceName AppThrottle 15000 | Out-Host
  & nssm start $ServiceName | Out-Host
  Write-Host "Service '$ServiceName' installed and started via NSSM."
} else {
  Write-Host 'NSSM not found. Falling back to sc.exe with worker_service.bat'
  # Create a basic service running cmd /c worker_service.bat
  $cmd = "cmd.exe /c `"$bat`""
  cmd /c "sc create $ServiceName binPath= '$cmd' start= auto DisplayName= '$DisplayName'" | Out-Host
  cmd /c "sc description $ServiceName '$Description'" | Out-Host
  # Configure recovery: restart after 60s on failure, do not reset counter
  cmd /c "sc failure $ServiceName reset=0 actions=restart/60000" | Out-Host
  cmd /c "sc failureflag $ServiceName 1" | Out-Host
  cmd /c "sc start $ServiceName" | Out-Host
  Write-Host "Service '$ServiceName' installed and started via sc.exe"
}
