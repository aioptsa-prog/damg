param(
  [string]$ServiceName = 'LeadsWorker',
  [string]$StatusUrl = 'http://127.0.0.1:4499/status',
  [int]$TimeoutSec = 10
)
$ErrorActionPreference = 'SilentlyContinue'
try {
  $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
  if(-not $svc){ Write-Host "[watchdog] Service $ServiceName not found"; exit 0 }
  if($svc.Status -ne 'Running'){
    Write-Host "[watchdog] Service not running; starting..."
    Start-Service -Name $ServiceName -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3
  }
  $ok = $false
  try {
    $resp = Invoke-WebRequest -UseBasicParsing -Uri $StatusUrl -TimeoutSec $TimeoutSec
    if($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500){ $ok = $true }
  } catch { $ok = $false }
  if(-not $ok){
    Write-Host "[watchdog] Status URL not responding; restarting service..."
    Restart-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
  } else {
    Write-Host "[watchdog] OK"
  }
} catch {
  Write-Host "[watchdog] error: $_"
}
