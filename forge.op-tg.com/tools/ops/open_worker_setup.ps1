param(
  [int]$Port = 4499,
  [string]$WorkerDir = 'D:\LeadsMembershipPRO\worker',
  [switch]$StartWorker,
  [int]$TimeoutSec = 30
)

$ErrorActionPreference = 'Stop'
$url = "http://127.0.0.1:$Port/setup"

function Wait-HttpReady([string]$u, [int]$timeoutSec){
  $deadline = (Get-Date).AddSeconds([Math]::Max(1,$timeoutSec))
  while((Get-Date) -lt $deadline){
    try { $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 2; if($r.StatusCode -ge 200){ return $true } } catch {}
    Start-Sleep -Milliseconds 300
  }
  return $false
}

if($StartWorker){
  if(-not (Test-Path $WorkerDir)){
    Write-Host "Worker directory not found: $WorkerDir" -ForegroundColor Red
    exit 1
  }
  $bat = Join-Path $WorkerDir 'worker_run.bat'
  if(-not (Test-Path $bat)){
    Write-Host "worker_run.bat not found in $WorkerDir" -ForegroundColor Red
    exit 1
  }
  Write-Host "Starting worker in background from $WorkerDir ..." -ForegroundColor Cyan
  Start-Process -FilePath $bat -WorkingDirectory $WorkerDir -WindowStyle Minimized | Out-Null
}

Write-Host ("Waiting for setup page: {0}" -f $url) -ForegroundColor Yellow
$ready = Wait-HttpReady -u $url -timeoutSec $TimeoutSec
if(-not $ready){ Write-Host "Setup page not ready within timeout. You can still try to open it." -ForegroundColor DarkYellow }

Write-Host "Opening browser ..." -ForegroundColor Green
Start-Process $url | Out-Null

Write-Host "Done." -ForegroundColor Green
