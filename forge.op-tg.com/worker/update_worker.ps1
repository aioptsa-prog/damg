param(
  [Parameter(Mandatory=$true)][string]$SetupPath,
  [string]$ServiceName = 'LeadsWorker',
  [switch]$Silent,
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$projRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$logDir = Join-Path $projRoot 'storage\logs'
if(!(Test-Path $logDir)){ New-Item -ItemType Directory -Force -Path $logDir | Out-Null }
$logFile = Join-Path $logDir 'update-worker.log'
function Log($msg){ $ts=(Get-Date).ToString('s'); "$ts `t $msg" | Out-File -FilePath $logFile -Append -Encoding utf8 }

function Invoke-Setup {
  param([string]$Path)
  $setupArgs = @()
  if ($Silent) { $setupArgs += @('/VERYSILENT','/NORESTART') }
  if ($DryRun) {
    Log "[DryRun] Would run setup: $Path $($setupArgs -join ' ')"
    return
  }
  Log "Running setup: $Path $($setupArgs -join ' ')"
  $p = Start-Process -FilePath $Path -ArgumentList $setupArgs -PassThru -Verb RunAs
  $p.WaitForExit()
  Log ("Setup exit code: {0}" -f $p.ExitCode)
  if($p.ExitCode -ne 0){ throw "Installer returned exit code $($p.ExitCode)" }
}

Write-Host "Updating using: $SetupPath"
Log "Update start. SetupPath=$SetupPath ServiceName=$ServiceName Silent=$Silent"

try {
  # Stop service if exists
  $svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
  if ($svc) {
    Write-Host "Stopping service $ServiceName ..."
    Log "Stopping service $ServiceName"
    if (-not $DryRun) {
      Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
      Start-Sleep -Seconds 2
    } else {
      Log "[DryRun] Would stop service $ServiceName"
    }
  }
} catch {}

# Compute and log SHA256 of the installer and verify against latest.json when available
try {
  if(Test-Path $SetupPath){
    $hash = (Get-FileHash -Algorithm SHA256 -Path $SetupPath).Hash.ToLower()
    Log ("Installer SHA256: {0}" -f $hash)
    $projRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
    $latestPath = Join-Path $projRoot 'storage\releases\latest.json'
    if(Test-Path $latestPath){
      $latest = Get-Content -LiteralPath $latestPath -Raw | ConvertFrom-Json
      $expected = "$($latest.sha256)".ToLower()
      if($expected){
        if($hash -ne $expected){
          Log ("WARNING: SHA256 mismatch. expected={0} actual={1}" -f $expected, $hash)
        } else {
          Log "SHA256 matches latest.json"
        }
      }
    }
  } else {
    Log "WARNING: SetupPath not found at $SetupPath"
  }
} catch { Log ("Hash verify error: {0}" -f $_) }

Invoke-Setup -Path $SetupPath

try {
  if ($svc) {
    Write-Host "Starting service $ServiceName ..."
    Log "Starting service $ServiceName"
    if (-not $DryRun) {
      Start-Service -Name $ServiceName -ErrorAction SilentlyContinue
    } else {
      Log "[DryRun] Would start service $ServiceName"
    }
  }
} catch {}

Write-Host 'Update flow completed.'
Log 'Update flow completed.'