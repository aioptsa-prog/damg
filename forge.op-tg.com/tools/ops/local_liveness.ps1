param(
  [int]$Port = 8091,
  [string]$Root = 'D:\LeadsMembershipPRO'
)

$ErrorActionPreference = 'Stop'

function Resolve-PhpExe(){
  $cmd = Get-Command php -ErrorAction SilentlyContinue
  if($cmd){ return $cmd.Source }
  throw 'php.exe not found in PATH'
}

if(-not (Test-Path -LiteralPath $Root)){
  Write-Host "Root not found: $Root" -ForegroundColor Red
  exit 1
}

$php = Resolve-PhpExe
Write-Host ("Starting PHP server http://127.0.0.1:{0} -> {1}" -f $Port,$Root) -ForegroundColor Cyan
$phpArgs = @('-S',"127.0.0.1:$Port",'-t',$Root)
$proc = Start-Process -FilePath $php -ArgumentList $phpArgs -PassThru -WorkingDirectory $Root

$base = "http://127.0.0.1:$Port"
# Wait until server responds
$deadline = (Get-Date).AddSeconds(10)
$ready = $false
while((Get-Date) -lt $deadline){
  try {
    $r = Invoke-WebRequest -Uri "$base/api/latest.php" -UseBasicParsing -TimeoutSec 2
    if($r.StatusCode -ge 200){ $ready = $true; break }
  } catch {}
  Start-Sleep -Milliseconds 200
}

if(-not $ready){
  Write-Host 'Server did not become ready in time' -ForegroundColor Yellow
  try { Stop-Process -Id $proc.Id -Force } catch {}
  exit 1
}

Write-Host 'Server is ready. Probing endpoints...' -ForegroundColor Green

try {
  # Ensure internal mode is enabled and secret is known in DB
  $resetOut = & php "$Root\tests\reset.php"
  Write-Host ($resetOut.Trim())
} catch {
  Write-Host ('reset.php failed: ' + $_.Exception.Message) -ForegroundColor Yellow
}

try {
  $h = Invoke-WebRequest -Uri "$base/api/heartbeat.php" -UseBasicParsing -Headers @{ 'X-Internal-Secret'='testsecret'; 'X-Worker-Id'='local-check' } -TimeoutSec 5
  Write-Host ("heartbeat: {0}" -f $h.StatusCode)
  Write-Host ($h.Content)
} catch {
  Write-Host ('heartbeat failed: ' + $_.Exception.Message) -ForegroundColor Red
}

try {
  $l = Invoke-WebRequest -Uri "$base/api/latest.php" -UseBasicParsing -TimeoutSec 5
  Write-Host ("latest.php: {0}" -f $l.StatusCode)
  Write-Host ($l.Content)
} catch {
  Write-Host ('latest.php failed: ' + $_.Exception.Message) -ForegroundColor Red
}

try {
  $d = Invoke-WebRequest -Uri "$base/api/download_worker.php?kind=zip" -UseBasicParsing -Method Head -TimeoutSec 10
  Write-Host ("download_worker (zip) HEAD: {0}" -f $d.StatusCode)
  Write-Host ("  X-Worker-Installer-Filename: " + ($d.Headers['X-Worker-Installer-Filename']))
  Write-Host ("  X-Worker-Installer-Kind: " + ($d.Headers['X-Worker-Installer-Kind']))
  Write-Host ("  Content-Type: " + ($d.Headers['Content-Type']))
  Write-Host ("  Content-Length: " + ($d.Headers['Content-Length']))
} catch {
  Write-Host ('download_worker HEAD failed: ' + $_.Exception.Message) -ForegroundColor Red
}

try { Stop-Process -Id $proc.Id -Force } catch {}
Write-Host 'Done.' -ForegroundColor DarkGray
