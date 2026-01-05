param(
  [string]$ServiceName = 'OptForgeWorker',
  [Parameter(ParameterSetName='Install')][switch]$Install,
  [Parameter(ParameterSetName='Update')][switch]$Update,
  [Parameter(ParameterSetName='Remove')][switch]$Remove,
  [string]$InstallPath = '',
  [string]$NodeExe = ''
)

<#
Idempotent Windows service installer for the worker using NSSM.
- Runs: node worker\index.js (no EXE dependency)
- Reads .env from InstallPath (BASE_URL, INTERNAL_SECRET, WORKER_ID, PULL_INTERVAL_SEC, HEADLESS)
- Creates logs under storage\logs\worker with simple rotation
Usage examples:
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Install
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Update
  powershell -ExecutionPolicy Bypass -File ops\install_worker.ps1 -ServiceName OptForgeWorker -Remove
#>

$ErrorActionPreference = 'Stop'

function Write-Info($msg){ Write-Host $msg -ForegroundColor Cyan }
function Write-Warn($msg){ Write-Warning $msg }
function Write-Err($msg){ Write-Host $msg -ForegroundColor Red }

$repoRoot = Split-Path -Parent $PSScriptRoot
if([string]::IsNullOrWhiteSpace($InstallPath)){ $InstallPath = $repoRoot }
$InstallPath = (Resolve-Path $InstallPath).Path

# Resolve Node
if([string]::IsNullOrWhiteSpace($NodeExe)){
  $cand1 = Join-Path $InstallPath 'worker\node\node.exe'
  $cand2 = (Get-Command node -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
  if(Test-Path $cand1){ $NodeExe = $cand1 }
  elseif($cand2){ $NodeExe = $cand2 }
  else { throw 'Node.js not found. Provide -NodeExe or ensure worker\\node\\node.exe exists.' }
}

# NSSM discovery or bootstrap
function Get-Nssm(){
  $nssm = (Get-Command nssm -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
  if($nssm){ return $nssm }
  # Try local tools cache
  $toolsDir = Join-Path $repoRoot 'tools\nssm'
  $nssm64 = Join-Path $toolsDir 'win64\nssm.exe'
  $nssm32 = Join-Path $toolsDir 'win32\nssm.exe'
  if(Test-Path $nssm64){ return $nssm64 }
  if(Test-Path $nssm32){ return $nssm32 }
  throw 'NSSM not found. Install NSSM and ensure nssm.exe is in PATH, or put it under tools\\nssm\\win64.'
}

$nssm = Get-Nssm

# Paths
$workDir   = Join-Path $InstallPath 'worker'
$entryJs   = Join-Path $workDir 'index.js'
$envFile   = Join-Path $workDir '.env'
$storage   = Join-Path $InstallPath 'storage'
$logDir    = Join-Path $storage 'logs\worker'
$serviceLog = Join-Path $logDir 'service.log'

if(!(Test-Path $entryJs)){ throw "worker entry not found: $entryJs" }

# Ensure storage/logs path
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

# Minimal log rotation: keep last 7 service.log.*
function Rotate-Log($p){
  if(Test-Path $p){
    $ts = Get-Date -Format 'yyyyMMdd_HHmmss'
    $arch = "$p.$ts"
    try { Move-Item -Path $p -Destination $arch -Force } catch {}
    # delete older than 7
    Get-ChildItem -Path "$p.*" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -Skip 7 | Remove-Item -Force -ErrorAction SilentlyContinue
  }
}

# Compose NSSM service configuration (idempotent)
$exe = $NodeExe
$argApp = 'worker\index.js'
$svcDir = $workDir

function Ensure-Service(){
  Write-Info "Configuring service '$ServiceName' via NSSM..."
  # Install if missing
  $exists = (& $nssm status $ServiceName 2>$null) -ne $null
  if(-not $exists){ & $nssm install $ServiceName $exe $argApp }

  # Set core params
  & $nssm set $ServiceName Application $exe
  & $nssm set $ServiceName AppDirectory $svcDir
  & $nssm set $ServiceName AppParameters $argApp
  & $nssm set $ServiceName AppNoConsole 1
  & $nssm set $ServiceName AppStopMethodSkip 0
  & $nssm set $ServiceName AppStdout $serviceLog
  & $nssm set $ServiceName AppStderr $serviceLog
  & $nssm set $ServiceName AppStdoutCreationDisposition 2  # CREATE_ALWAYS
  & $nssm set $ServiceName AppStderrCreationDisposition 2
  & $nssm set $ServiceName AppRotateFiles 1
  & $nssm set $ServiceName AppRotateOnline 1
  & $nssm set $ServiceName AppRotateBytes 10485760  # 10MB
  & $nssm set $ServiceName AppRotateDelay 60
  & $nssm set $ServiceName Start SERVICE_AUTO_START
  & $nssm set $ServiceName ObjectName LocalSystem

  # Environment (.env loader)
  # Inject dotenv defaults via ENV to ensure key params are present; index.js reads .env itself as well
  $dotenv = @()
  if(Test-Path $envFile){
    $dotenv = Get-Content -ErrorAction SilentlyContinue -Path $envFile |
      Where-Object { $_ -and ($_ -match '=') -and (-not ($_.Trim().StartsWith('#'))) } |
      ForEach-Object { $_.Trim() }
  }
  # Additional safety: ensure critical vars are present (empty values allowed, worker validates at runtime)
  $base = $dotenv | Where-Object { $_ -match '^BASE_URL=' }
  $sec  = $dotenv | Where-Object { $_ -match '^INTERNAL_SECRET=' }
  $wid  = $dotenv | Where-Object { $_ -match '^WORKER_ID=' }
  if(-not $base){ $dotenv += 'BASE_URL=' }
  if(-not $sec){ $dotenv += 'INTERNAL_SECRET=' }
  if(-not $wid){ $dotenv += 'WORKER_ID=' }
  $dotenv += "PLAYWRIGHT_BROWSERS_PATH=$($workDir)\ms-playwright"

  # Set environment block (overwrites existing, idempotent)
  & $nssm set $ServiceName AppEnvironmentExtra ($dotenv -join '`r`n')

  # Restart service to apply
  try { & $nssm restart $ServiceName } catch { & $nssm start $ServiceName }
}

try {
  if($Install){
    Rotate-Log -p $serviceLog
    Ensure-Service
    Write-Host "Service '$ServiceName' installed/updated and started." -ForegroundColor Green
  }
  elseif($Update){
    Rotate-Log -p $serviceLog
    Ensure-Service
    Write-Host "Service '$ServiceName' updated and restarted." -ForegroundColor Green
  }
  elseif($Remove){
    Write-Info "Stopping and removing '$ServiceName'..."
    try { & $nssm stop $ServiceName -force | Out-Null } catch {}
    try { & $nssm remove $ServiceName confirm | Out-Null } catch {}
    Write-Host "Service '$ServiceName' removed." -ForegroundColor Green
  }
  else {
    Write-Host 'Specify one of: -Install, -Update, -Remove' -ForegroundColor Yellow
    exit 2
  }
}
catch {
  Write-Err "Failed: $_"
  exit 1
}
