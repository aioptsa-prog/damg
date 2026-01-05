param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$DocrootBase,
  [string]$BaseUrl = 'https://nexus.op-tg.com',
  [string]$ApiToken,
  [pscredential]$Credential,
  [switch]$LeaveMaintenance,
  [switch]$PlanOnly,
  [switch]$VerifyRollback,
  [string]$Label
)
$ErrorActionPreference = 'Stop'
$tokFile = Join-Path $LocalPath 'tools\secrets\cpanel_token.txt'
if([string]::IsNullOrWhiteSpace($ApiToken) -and (Test-Path $tokFile)){
  try { $ApiToken = (Get-Content -LiteralPath $tokFile -Raw).Trim() } catch {}
}
$validDir = Join-Path $LocalPath 'storage\logs\validation'
New-Item -ItemType Directory -Force -Path $validDir | Out-Null
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = if([string]::IsNullOrWhiteSpace($Label)) { '' } else { "_" + $Label }

Write-Host '--- cPanel deploy (maintenance, Fileman swap) ---' -ForegroundColor Cyan
$deployLog = Join-Path $validDir ("cpanel_live_" + $ts + $suffix + ".txt")

if ([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($DocrootBase)) {
  throw 'CpanelHost, CpanelUser, and DocrootBase are required.'
}

# Build argument set for deploy script
$deployScript = Join-Path $LocalPath 'tools\deploy\deploy_cpanel_uapi.ps1'
if (-not (Test-Path $deployScript)) { throw "Deploy script not found: $deployScript" }
$splat = @{ 
  LocalPath = $LocalPath
  CpanelHost = $CpanelHost
  CpanelUser = $CpanelUser
  DocrootBase = $DocrootBase
  Maintenance = $true
  NoTerminal = $true
  LeaveMaintenance = $LeaveMaintenance.IsPresent
}
if ($ApiToken) { $splat.ApiToken = $ApiToken }
if ($Credential) { $splat.Credential = $Credential }
if ($PlanOnly) { $splat.PlanOnly = $true }

Start-Transcript -Path $deployLog -Force | Out-Null
try {
  & $deployScript @splat
}
finally {
  Stop-Transcript | Out-Null
}
Write-Host ("Saved: {0}" -f $deployLog)

if (-not $PlanOnly -and -not $LeaveMaintenance) {
  # Clear maintenance by rewriting .htaccess to the same release without the maintenance block
  $tsFile = Join-Path $LocalPath 'storage\logs\validation\last_deploy_timestamp.txt'
  if(Test-Path $tsFile){
    $depTs = (Get-Content -LiteralPath $tsFile -Raw).Trim()
    Write-Host ("Deactivating maintenance for release {0}" -f $depTs) -ForegroundColor Yellow
    $actSplat = @{ CpanelHost=$CpanelHost; CpanelUser=$CpanelUser; DocrootBase=$DocrootBase; Timestamp=$depTs; Maintenance=$false }
    if ($ApiToken) { $actSplat.ApiToken = $ApiToken } elseif ($Credential) { $actSplat.Credential = $Credential }
    & "$LocalPath\tools\ops\cpanel_activate_release.ps1" @actSplat
  } else {
    Write-Warning 'last_deploy_timestamp.txt not found; cannot auto-deactivate maintenance.'
  }
  Write-Host '--- HTTP probes (production) ---' -ForegroundColor Cyan
  $probeOut = Join-Path $validDir ("http_probe_prod_" + $ts + $suffix + ".txt")
  & "$LocalPath\tools\http_probe.ps1" -BaseUrl $BaseUrl -OutFile $probeOut
  Write-Host ("Saved: {0}" -f $probeOut)
} else {
  Write-Host 'Skipping probes (PlanOnly or LeaveMaintenance).' -ForegroundColor Yellow
}

Write-Host '--- Bundling evidence ---' -ForegroundColor Cyan
& "$LocalPath\tools\ops\make_evidence_bundle.ps1" -Root $LocalPath
Write-Host 'Production evidence (cPanel) capture complete.' -ForegroundColor Green

if (-not $PlanOnly -and $VerifyRollback) {
  Write-Host '--- Verifying rollback ---' -ForegroundColor Cyan
  $tsFile = Join-Path $LocalPath 'storage\logs\validation\last_deploy_timestamp.txt'
  if(!(Test-Path $tsFile)){ Write-Warning 'last_deploy_timestamp.txt not found; cannot verify rollback.'; return }
  $depTs = (Get-Content -LiteralPath $tsFile -Raw).Trim()
  $rbLog1 = Join-Path $validDir ("cpanel_rollback_live_to_prev_" + $depTs + $suffix + ".txt")
  $rbLog2 = Join-Path $validDir ("cpanel_rollback_restore_" + $depTs + $suffix + ".txt")
  # Roll back to prev_<timestamp>
  $rbSplat = @{ CpanelHost=$CpanelHost; CpanelUser=$CpanelUser; DocrootBase=$DocrootBase; PrevTimestamp=$depTs; NoTerminal=$true }
  if ($ApiToken) { $rbSplat.ApiToken = $ApiToken } elseif ($Credential) { $rbSplat.Credential = $Credential }
  & "$LocalPath\tools\deploy\rollback_cpanel_uapi.ps1" @rbSplat 2>&1 | Set-Content -Path $rbLog1 -Encoding UTF8
  Write-Host ("Saved: {0}" -f $rbLog1)
  # Restore to releases/<timestamp>
  $rbSplat2 = $rbSplat.Clone()
  $rbSplat2.SourcePrefer = 'releases'
  & "$LocalPath\tools\deploy\rollback_cpanel_uapi.ps1" @rbSplat2 2>&1 | Set-Content -Path $rbLog2 -Encoding UTF8
  Write-Host ("Saved: {0}" -f $rbLog2)
  Write-Host 'Rollback verification complete.' -ForegroundColor Green
}
