param(
  [int]$BackendPort = 8091,
  [string]$Root = 'D:\LeadsMembershipPRO',
  [string]$WorkerDir = 'D:\LeadsMembershipPRO\worker',
  [string]$Query = 'cafe',
  [string]$LL = '24.7136,46.6753',
  [int]$Target = 2,
  [switch]$HeadlessOff,
  [switch]$OpenMini
)

$ErrorActionPreference = 'Stop'
function Wait-HttpOk([string]$url,[int]$timeoutSec=15){
  $dl = (Get-Date).AddSeconds($timeoutSec)
  while((Get-Date) -lt $dl){ try { $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3; if($r.StatusCode -ge 200){ return $true } } catch {} Start-Sleep -Milliseconds 300 }
  return $false
}

Write-Host "=== LIVE DEMO: backend + worker + mini + command + job ===" -ForegroundColor Cyan
$base = "http://127.0.0.1:$BackendPort"

# 1) Start backend in a separate PowerShell (KeepOpen)
Write-Host "[1/9] Starting backend on $base" -ForegroundColor Yellow
$backendProc = Start-Process -FilePath powershell -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File', (Join-Path $Root 'tools\ops\run_dev_server.ps1'), '-Port', $BackendPort, '-Root', $Root, '-EnableInternal', '-KeepOpen') -PassThru -WindowStyle Hidden
if(-not (Wait-HttpOk -url "$base/api/latest.php" -timeoutSec 20)) { Write-Host 'Backend did not come up in time.' -ForegroundColor Red; exit 1 }
Write-Host "Backend is up." -ForegroundColor Green

# 2) Enable internal and get secret
Write-Host "[2/9] Enabling internal mode and fetching secret" -ForegroundColor Yellow
$dev = Invoke-RestMethod -Uri ("{0}/api/dev_enable_internal.php?confirm=1" -f $base) -UseBasicParsing -TimeoutSec 8
$secret = [string]$dev.internal_secret
if(-not $secret){ Write-Host 'Failed to get internal secret' -ForegroundColor Red; exit 1 }
Write-Host ("Secret: {0}" -f $secret)

# 3) Ensure worker .env is configured (BASE_URL, INTERNAL_SECRET, HEADLESS)
Write-Host "[3/9] Updating worker .env" -ForegroundColor Yellow
$envPath = Join-Path $WorkerDir '.env'
if(-not (Test-Path -LiteralPath $envPath)){ New-Item -ItemType File -Path $envPath -Force | Out-Null }
$lines = Get-Content -LiteralPath $envPath -ErrorAction SilentlyContinue
if(-not $lines){ $lines = @() }
$out = @()
$keys = @{ 'INTERNAL_SECRET'=$secret; 'BASE_URL'=$base }
if($HeadlessOff){ $keys['HEADLESS'] = 'false' }
foreach($ln in $lines){ $w=$false; foreach($k in $keys.Keys){ if($ln -match ("^"+$k+"=")){ $out += ($k+'='+$keys[$k]); $w=$true; break } } if(-not $w){ $out += $ln } }
foreach($k in $keys.Keys){ if(-not ($out -match ("^"+$k+"="))){ $out += ($k+'='+$keys[$k]) } }
$out | Set-Content -Path $envPath -Encoding UTF8

# 4) Start (or restart) worker
Write-Host "[4/9] Starting worker" -ForegroundColor Yellow
& (Join-Path $Root 'tools\ops\restart_worker.ps1') -BackendBase $base -WorkerDir $WorkerDir | Write-Host

# 5) Wait for /metrics and open mini if requested
Write-Host "[5/9] Waiting for worker /metrics" -ForegroundColor Yellow
if(-not (Wait-HttpOk -url 'http://127.0.0.1:4499/metrics' -timeoutSec 20)){ Write-Host 'Worker UI did not come up.' -ForegroundColor Red; exit 1 }
$m = Invoke-RestMethod -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5
Write-Host (ConvertTo-Json $m -Depth 4)
if($OpenMini){ Start-Process -FilePath powershell -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File', (Join-Path $Root 'tools\ops\mini_widget.ps1')) | Out-Null }

# 6) Central command round-trip test: pause then resume
Write-Host "[6/9] Central command test (pause -> sync -> resume -> sync)" -ForegroundColor Yellow
Invoke-WebRequest -Uri ("{0}/api/dev_worker_command.php?cmd=pause&rev=1" -f $base) -UseBasicParsing | Out-Null
Invoke-RestMethod -Uri 'http://127.0.0.1:4499/control' -Method Post -Headers @{ 'Content-Type'='application/json' } -Body '{"action":"sync-config"}' | Out-Null
$m1 = Invoke-RestMethod -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5
Write-Host ("Paused now: {0}" -f [bool]$m1.paused)
Invoke-WebRequest -Uri ("{0}/api/dev_worker_command.php?cmd=resume&rev=2" -f $base) -UseBasicParsing | Out-Null
Invoke-RestMethod -Uri 'http://127.0.0.1:4499/control' -Method Post -Headers @{ 'Content-Type'='application/json' } -Body '{"action":"sync-config"}' | Out-Null
$m2 = Invoke-RestMethod -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5
Write-Host ("Paused now: {0}" -f [bool]$m2.paused)

# 7) Enqueue a demo job
Write-Host "[7/9] Enqueue test job: q=$Query, ll=$LL, target=$Target" -ForegroundColor Yellow
$job = Invoke-RestMethod -Uri ("{0}/tools/ops/add_test_job.php?q={1}&ll={2}&target={3}" -f $base, $Query, $LL, $Target) -UseBasicParsing -TimeoutSec 8
Write-Host ("Job: {0}" -f (ConvertTo-Json $job))

# 8) Nudge worker and wait for progress
Write-Host "[8/9] Reconnect worker and wait for progress" -ForegroundColor Yellow
Invoke-RestMethod -Uri 'http://127.0.0.1:4499/control' -Method Post -Headers @{ 'Content-Type'='application/json' } -Body '{"action":"reconnect"}' | Out-Null
$deadline = (Get-Date).AddSeconds(90)
$progress = $false
while((Get-Date) -lt $deadline){
  try { $mm = Invoke-RestMethod -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5 } catch { Start-Sleep -Seconds 2; continue }
  if($mm.lastReport -and $mm.lastReport.added -ge 0){ $progress = $true; break }
  Start-Sleep -Seconds 2
}
Write-Host ("Progress seen: {0}" -f $progress)

# 9) Print final snapshot and done
Write-Host "[9/9] Final metrics snapshot" -ForegroundColor Yellow
$final = Invoke-RestMethod -Uri 'http://127.0.0.1:4499/metrics' -UseBasicParsing -TimeoutSec 5
Write-Host (ConvertTo-Json $final -Depth 5)
Write-Host "Demo complete. Open http://127.0.0.1:4499/status for full view and watch the Playwright browser." -ForegroundColor Green
