param(
  [string]$BackendBase = 'http://127.0.0.1:8091',
  [string]$WorkerDir = 'D:\LeadsMembershipPRO\worker'
)

$ErrorActionPreference = 'Stop'
Write-Host ("Backend: {0}" -f $BackendBase) -ForegroundColor Cyan
Write-Host ("WorkerDir: {0}" -f $WorkerDir) -ForegroundColor Cyan

if(-not (Test-Path -LiteralPath $WorkerDir)){
  Write-Host "Worker dir not found" -ForegroundColor Red; exit 1
}

# 1) Fetch internal secret from backend (and ensure internal is enabled)
$dev = Invoke-RestMethod -Uri ("{0}/api/dev_enable_internal.php?confirm=1" -f $BackendBase) -UseBasicParsing -TimeoutSec 10
if(-not $dev -or -not $dev.ok){ Write-Host 'Failed to enable internal mode' -ForegroundColor Red; exit 1 }
$secret = [string]$dev.internal_secret
Write-Host ("Secret: {0}" -f $secret)

# 2) Update .env with INTERNAL_SECRET and BASE_URL
$envPath = Join-Path $WorkerDir '.env'
if(-not (Test-Path -LiteralPath $envPath)){ New-Item -ItemType File -Path $envPath -Force | Out-Null }
$lines = Get-Content -Path $envPath -ErrorAction SilentlyContinue
if(-not $lines){ $lines = @() }
$out = @()
$hasSecret = $false; $hasBase = $false
foreach($ln in $lines){
  if($ln -match '^INTERNAL_SECRET='){ $out += ('INTERNAL_SECRET=' + $secret); $hasSecret=$true }
  elseif($ln -match '^BASE_URL='){ $out += ('BASE_URL=' + $BackendBase) ; $hasBase=$true }
  else { $out += $ln }
}
if(-not $hasSecret){ $out += ('INTERNAL_SECRET=' + $secret) }
if(-not $hasBase){ $out += ('BASE_URL=' + $BackendBase) }
$out | Set-Content -Path $envPath -Encoding UTF8
Write-Host 'Updated .env' -ForegroundColor Green

# 3) Stop existing worker processes and free port 4499
try {
  $listeners = Get-NetTCPConnection -State Listen -LocalPort 4499 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
  foreach($pid in $listeners){ try { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue } catch {} }
} catch {}
Get-Process worker -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
# Kill node processes whose CommandLine references the worker dir
try {
  $procs = Get-CimInstance Win32_Process | Where-Object { $_.Name -match 'node\.exe' -and ($_.CommandLine -like "*${WorkerDir.Replace("\\","\\\\")}*") }
  foreach($p in $procs){ try { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue } catch {} }
} catch {}
Start-Sleep -Milliseconds 700

# 4) Start worker
Start-Process -FilePath node -ArgumentList 'launcher.js' -WorkingDirectory $WorkerDir -WindowStyle Hidden | Out-Null
Write-Host 'Worker starting...' -ForegroundColor Green
Start-Sleep -Seconds 3

# 5) Probe worker UI metrics
try{
  $m = Invoke-WebRequest -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5
  Write-Host ('/metrics: ' + $m.Content)
} catch { Write-Host ('/metrics error: ' + $_.Exception.Message) -ForegroundColor Yellow }

# 6) Tail last log lines
try{
  $log = Get-ChildItem -Path (Join-Path $WorkerDir 'logs') -Filter 'worker-*.log' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
  if($log){ Get-Content -Path $log.FullName -Tail 50 }
} catch {}

exit 0
