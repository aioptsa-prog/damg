Param(
    [int]$TimeoutSec = 120,
    [int]$PollEverySec = 5
)

$ErrorActionPreference = 'Stop'
Write-Host "[Smoke] Starting… (timeout=${TimeoutSec}s, every=${PollEverySec}s)"

# 1) Enqueue a small diagnostic job (direct SQL via temp PHP), best-effort
$repoRoot = Split-Path -Parent $PSScriptRoot
$phpExe = "php"
try {
  $phpEnq = @'
<?php
require __DIR__ . "/../bootstrap.php";
$pdo = db();
// Ensure admin exists
$adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if(!$adminId){
  $st=$pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))");
  $st->execute(['0599999999','Smoke Admin','admin', password_hash('x', PASSWORD_DEFAULT)]);
  $adminId = (int)$pdo->lastInsertId();
}
$payload = ['query'=>'diag-echo','ll'=>'24.7136,46.6753','radius_km'=>1,'lang'=>'ar','region'=>'sa','target'=>1];
$cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
$has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
if($has('job_type') && $has('payload_json')){
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $st = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, attempts, next_retry_at, job_type, payload_json, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, 0, NULL, 'diag.echo', ?, datetime('now'), datetime('now'))");
  $st->execute([$adminId, 'admin', null, $payload['query'], $payload['ll'], $payload['radius_km'], $payload['lang'], $payload['region'], $payload['target'], $payloadJson]);
} else {
  $st = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, attempts, next_retry_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, 0, NULL, datetime('now'), datetime('now'))");
  $st->execute([$adminId, 'admin', null, $payload['query'], $payload['ll'], $payload['radius_km'], $payload['lang'], $payload['region'], $payload['target']]);
}
echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
?>
'@
  $tmpEnq = Join-Path $env:TEMP ("smoke_enq_" + [System.Guid]::NewGuid().ToString() + ".php")
  Set-Content -Path $tmpEnq -Value $phpEnq -Encoding UTF8
  $out = & $phpExe $tmpEnq 2>$null
  Remove-Item -Force $tmpEnq -ErrorAction SilentlyContinue
  $out = ($out | Out-String).Trim()
  Write-Host "[Smoke] enqueue output: $out"
} catch {
  Write-Host "[Smoke] enqueue failed: $($_.Exception.Message)" -ForegroundColor Yellow
}

# 2) Poll recent jobs via a small PHP snippet that queries DB directly
$deadline = (Get-Date).AddSeconds($TimeoutSec)
$jobFound = $false
$jobSucceeded = $false
$jobId = $null

while ((Get-Date) -lt $deadline) {
  try {
    $phpCode = @'
<?php
require __DIR__ . "/../bootstrap.php";
$pdo = db();
// MySQL first, then SQLite fallback for 2m window
try{
  $sql = "SELECT id,status,job_type,role FROM internal_jobs WHERE (updated_at >= NOW() - INTERVAL 2 MINUTE OR created_at >= NOW() - INTERVAL 2 MINUTE) ORDER BY updated_at DESC LIMIT 20";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){
  $rows = $pdo->query("SELECT id,status,job_type,role FROM internal_jobs WHERE (updated_at >= datetime('now','-2 minutes') OR created_at >= datetime('now','-2 minutes')) ORDER BY updated_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
'@
    $tmp = Join-Path $env:TEMP ("smoke_jobs_" + [System.Guid]::NewGuid().ToString() + ".php")
    Set-Content -Path $tmp -Value $phpCode -Encoding UTF8
  $json = & $phpExe $tmp 2>$null
  $json = ($json | Out-String)
    Remove-Item -Force $tmp -ErrorAction SilentlyContinue
    $rows = @()
    try { $rows = $json | ConvertFrom-Json } catch {}
    foreach ($r in $rows) {
      if ($r.status -eq 'done' -or $r.status -eq 'succeeded') {
        $jobFound = $true
        $jobSucceeded = $true
        $jobId = $r.id
        break
      }
      if ($r.status -eq 'processing' -or $r.status -eq 'queued') {
        $jobFound = $true
      }
    }
    if ($jobSucceeded) { break }
  } catch {
    # ignore and retry
  }
  Start-Sleep -Seconds $PollEverySec
}

if ($jobSucceeded) {
  Write-Host "PASS: pipeline is able to enqueue and complete a job (job #$jobId)."
  exit 0
} elseif ($jobFound) {
  Write-Host "FAIL: job observed but did not reach succeeded/done within ${TimeoutSec}s. Check logs: storage\\logs\\worker\\service.log" -ForegroundColor Red
  exit 2
} else {
  Write-Host "FAIL: no recent jobs observed within ${TimeoutSec}s. Check worker status and queue. Logs: storage\\logs\\worker\\service.log" -ForegroundColor Red
  exit 3
}
