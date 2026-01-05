param(
  [int]$Port = 8091,
  [string]$Root = 'D:\LeadsMembershipPRO',
  [string]$PhpExe,
  [switch]$KeepOpen,
  [switch]$EnableInternal
)

$ErrorActionPreference = 'Stop'

function Resolve-PhpExe([string]$hint){
  if($hint -and (Test-Path $hint)){ return (Resolve-Path $hint).Path }
  $cmd = Get-Command php -ErrorAction SilentlyContinue
  if($cmd){ return $cmd.Source }
  $cands = @(
    "$env:ProgramFiles\PHP\*\php.exe",
    'C:\php\php.exe',
    'C:\xampp\php\php.exe',
    "$env:ChocolateyInstall\bin\php.exe",
    "$env:USERPROFILE\scoop\shims\php.exe"
  )
  foreach($p in $cands){ $found = Get-Item -LiteralPath $p -ErrorAction SilentlyContinue; if($found){ return $found.FullName } }
  return $null
}

$phpPath = Resolve-PhpExe -hint $PhpExe
if(-not $phpPath){
  Write-Host 'PHP executable not found. Please install PHP or provide -PhpExe path.' -ForegroundColor Red
  Write-Host 'Examples:'
  Write-Host '  -PhpExe "C:\\php\\php.exe"'
  Write-Host '  -PhpExe "C:\\xampp\\php\\php.exe"'
  exit 1
}

if(-not (Test-Path $Root)){
  Write-Host "Root not found: $Root" -ForegroundColor Red
  exit 1
}

Write-Host ("Starting PHP built-in server on http://127.0.0.1:{0} serving {1}" -f $Port, $Root) -ForegroundColor Cyan
$phpArgs = @('-S',"127.0.0.1:$Port",'-t',$Root)
$proc = Start-Process -FilePath $phpPath -ArgumentList $phpArgs -PassThru -WorkingDirectory $Root

# Wait for server to bind and respond
$base = "http://127.0.0.1:$Port"
$deadline = (Get-Date).AddSeconds(10)
$ready = $false
while((Get-Date) -lt $deadline){
  try {
    $r = Invoke-WebRequest -Uri "$base/api/latest.php" -UseBasicParsing -TimeoutSec 2
    if($r.StatusCode -ge 200){ $ready = $true; break }
  } catch {}
  Start-Sleep -Milliseconds 250
}

if(-not $ready){
  Write-Host 'Server did not respond in time. Stopping...' -ForegroundColor Yellow
  try { Stop-Process -Id $proc.Id -Force } catch {}
  exit 1
}

Write-Host 'Server is ready.' -ForegroundColor Green
Write-Host ("Try: {0}" -f "$base/api/latest.php")
Write-Host ("Or:  {0}" -f "$base/api/download_worker.php")

if($KeepOpen){
  if($EnableInternal){
    try {
      $dev = Invoke-RestMethod -Uri "$base/api/dev_enable_internal.php?confirm=1" -UseBasicParsing -TimeoutSec 5
      $sec = $dev.internal_secret
      Write-Host ("Enabled internal mode. Secret: {0}" -f $sec) -ForegroundColor Yellow
      try {
        $hb = Invoke-RestMethod -Uri "$base/api/heartbeat.php" -Headers @{ 'X-Internal-Secret'=$sec; 'X-Worker-Id'='dev-win' } -UseBasicParsing -TimeoutSec 5
        Write-Host ("Heartbeat ok={0}, stopped={1}" -f ($hb.ok), ($hb.stopped)) -ForegroundColor Green
      } catch { Write-Host ("Heartbeat failed: {0}" -f $_.Exception.Message) -ForegroundColor DarkYellow }
    } catch { Write-Host ("dev_enable_internal failed: {0}" -f $_.Exception.Message) -ForegroundColor DarkYellow }
  }
  Write-Host 'Press Ctrl+C to stop.' -ForegroundColor DarkGray
  try { Wait-Process -Id $proc.Id } finally { try { Stop-Process -Id $proc.Id -Force } catch {} }
} else {
  # Quick probe then stop
  try {
    $h = Invoke-WebRequest -Uri "$base/api/download_worker.php" -Method Head -UseBasicParsing -TimeoutSec 5
    Write-Host ("X-Worker-Installer-Filename: {0}" -f ($h.Headers['X-Worker-Installer-Filename']))
    $ver = $h.Headers['X-Worker-Installer-Version']
    $kind = $h.Headers['X-Worker-Installer-Kind']
    if($ver){ Write-Host ("X-Worker-Installer-Version: {0}" -f $ver) }
    if($kind){ Write-Host ("X-Worker-Installer-Kind: {0}" -f $kind) }
  } catch {
    Write-Host ("Probe failed: {0}" -f $_.Exception.Message) -ForegroundColor Yellow
  }
  try { Stop-Process -Id $proc.Id -Force } catch {}
}
