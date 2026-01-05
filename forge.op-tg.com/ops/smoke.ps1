param(
  [string]$BaseUrl,
  [string]$InternalSecret,
  [string]$WorkerId,
  [int]$TimeoutSec = 120
)
$ErrorActionPreference = 'Stop'
Write-Host "[smoke] Starting end-to-end smoke test..." -ForegroundColor Cyan

# Resolve defaults from worker/.env if not provided
function Read-EnvFile($path){ if(!(Test-Path $path)){ return @{} } $map=@{}; Get-Content -Path $path | ForEach-Object { if($_ -match '^(.*?)=(.*)$'){ $map[$matches[1].Trim()] = $matches[2] } }; return $map }
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$repo = (Resolve-Path (Join-Path $root '..')).Path
$workerEnvPath = Join-Path $repo 'worker/.env'
$envMap = Read-EnvFile $workerEnvPath
if([string]::IsNullOrEmpty($BaseUrl)){ $BaseUrl = $envMap['BASE_URL'] }
if([string]::IsNullOrEmpty($InternalSecret)){ $InternalSecret = $envMap['INTERNAL_SECRET'] }
if([string]::IsNullOrEmpty($WorkerId)){ $WorkerId = $envMap['WORKER_ID'] }
if([string]::IsNullOrEmpty($BaseUrl) -or [string]::IsNullOrEmpty($InternalSecret) -or [string]::IsNullOrEmpty($WorkerId)){
  Write-Host "[smoke] Missing BASE_URL / INTERNAL_SECRET / WORKER_ID. Provide via args or worker/.env" -ForegroundColor Yellow
  exit 2
}

# Check worker service running (best effort)
try {
  $svc = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -like '*OptForgeWorker*' -or $_.DisplayName -like '*OptForgeWorker*' }
  if($null -eq $svc){ Write-Host "[smoke] Worker service not detected. Ensure the worker is running (service or console)." -ForegroundColor Yellow }
  elseif($svc.Status -ne 'Running'){ Write-Host "[smoke] Service present but not running: $($svc.Name) ($($svc.Status))" -ForegroundColor Yellow }
  else { Write-Host "[smoke] Detected service: $($svc.Name) — Running" -ForegroundColor Green }
}catch{}

# Enqueue a sample job (CLI only)
$enqueue = Join-Path $repo 'tools/ops/enqueue_sample.php'
if(!(Test-Path $enqueue)){
  Write-Host "[smoke] enqueue_sample.php missing; creating it is part of this PR. Ensure it exists." -ForegroundColor Red
  exit 3
}

$php = 'php'
$orig = Get-Location
Push-Location $repo
$jobJson = & $php $enqueue --type places_api_search --payload '{"query":"demo","ll":"24.7136,46.6753","radius_km":5,"lang":"ar","region":"sa","target":1}'
if($LASTEXITCODE -ne 0){ Write-Host "[smoke] enqueue failed: $jobJson" -ForegroundColor Red; exit 4 }
try{ $job = $jobJson | ConvertFrom-Json } catch { Write-Host "[smoke] invalid enqueue output: $jobJson" -ForegroundColor Red; exit 5 }
if(-not $job.ok){ Write-Host "[smoke] enqueue not ok: $jobJson" -ForegroundColor Red; exit 6 }
$jobId = [int]$job.job_id
Write-Host "[smoke] Enqueued job_id=$jobId" -ForegroundColor Green

# Poll job status until processing/done or timeout
$deadline = (Get-Date).AddSeconds($TimeoutSec)
$last = ''
while((Get-Date) -lt $deadline){
  $statusObj = & $php -r "require 'bootstrap.php'; $pdo=db(); $id=(int)$argv[1]; $st=$pdo->prepare('SELECT status,lease_expires_at,result_count,attempts,next_retry_at,last_error FROM internal_jobs WHERE id=?'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC); echo json_encode($row);" -- $jobId
  try{ $s = $statusObj | ConvertFrom-Json }catch{ $s = $null }
  if($null -ne $s){
    $line = "status=$($s.status); attempts=$($s.attempts); lease=$($s.lease_expires_at); next_retry=$($s.next_retry_at); result=$($s.result_count); last_error=$($s.last_error)"
    if($line -ne $last){ Write-Host "[smoke] $line" -ForegroundColor Gray; $last = $line }
    if($s.status -eq 'processing' -or $s.status -eq 'done' -or $s.status -eq 'failed'){ break }
  }
  Start-Sleep -Seconds 2
}

# Summarize result
$final = & $php -r "require 'bootstrap.php'; $pdo=db(); $id=(int)$argv[1]; $st=$pdo->prepare('SELECT status,result_count,attempts,last_error FROM internal_jobs WHERE id=?'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC); echo json_encode($row);" -- $jobId | ConvertFrom-Json
if($null -eq $final){ Write-Host "[smoke] Could not load job final state" -ForegroundColor Red; exit 7 }

$logs = @()
$serviceLog = Join-Path $repo 'storage\logs\worker\service.log'
if(Test-Path $serviceLog){ $logs += $serviceLog }
$attemptsCount = & $php -r "require 'bootstrap.php'; $pdo=db(); $id=(int)$argv[1]; echo (int)$pdo->query('SELECT COUNT(*) FROM job_attempts WHERE job_id=' . $id)->fetchColumn();" -- $jobId
$hasAttempts = ($attemptsCount -as [int]) -gt 0

if($final.status -eq 'done' -or ($final.status -eq 'processing' -and ([int]$final.result_count) -ge 0)){
  Write-Host "[smoke] PASS — status=$($final.status) result_count=$($final.result_count) attempts=$($final.attempts)" -ForegroundColor Green
  if($hasAttempts){ Write-Host "[smoke] job_attempts rows: $attemptsCount" -ForegroundColor Green }
  if($logs.Count){ Write-Host "[smoke] Logs:" -ForegroundColor Cyan; $logs | ForEach-Object { Write-Host "  $_" } }
  exit 0
} else {
  Write-Host "[smoke] FAIL — status=$($final.status) attempts=$($final.attempts) last_error=$($final.last_error)" -ForegroundColor Red
  if($hasAttempts){ Write-Host "[smoke] job_attempts rows: $attemptsCount" -ForegroundColor Yellow }
  if($logs.Count){ Write-Host "[smoke] Logs:" -ForegroundColor Cyan; $logs | ForEach-Object { Write-Host "  $_" } }
  exit 8
}
Pop-Location
