param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [pscredential]$Credential,
  [string]$ApiToken,
  [string]$DocrootBase,
  [string]$PrevTimestamp,
  [ValidateSet('prev','releases')][string]$SourcePrefer = 'prev',
  [switch]$PlanOnly,
  [switch]$NoTerminal
)
$ErrorActionPreference = 'Stop'
if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($DocrootBase)){
  throw 'CpanelHost, CpanelUser, and DocrootBase are required.'
}
if([string]::IsNullOrWhiteSpace($PrevTimestamp)){
  throw 'PrevTimestamp is required (e.g., 20250930_153000).'
}

$src = if($SourcePrefer -eq 'prev') { "$DocrootBase/prev_$PrevTimestamp" } else { "$DocrootBase/releases/$PrevTimestamp" }
$dst = "$DocrootBase/current"
$trash = "$DocrootBase/rollback_prev_" + (Get-Date -Format 'yyyyMMdd_HHmmss')

if($PlanOnly){
  Write-Host 'cPanel UAPI Rollback Plan' -ForegroundColor Cyan
  Write-Host ("- Touch maintenance: {0}/maintenance.flag" -f $DocrootBase)
  Write-Host ("- Rename current -> {0}" -f $trash)
  Write-Host ("- Rename {0} -> {1}" -f $src, $dst)
  Write-Host ("- Delete maintenance.flag")
  return
}

# Auth header (required for live run)
$authHeader = @{}
if($ApiToken){
  $authHeader['Authorization'] = "cpanel ${CpanelUser}:$ApiToken"
} elseif($Credential){
  $plain = (New-Object System.Net.NetworkCredential('', $Credential.Password)).Password
  $basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$($Credential.UserName):$plain"))
  $authHeader['Authorization'] = "Basic $basic"
} else {
  throw 'Provide ApiToken or Credential.'
}

# Maintenance on
$saveB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/save_file')
$saveB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/maintenance.flag") + '&data='
Invoke-WebRequest -Uri $saveB.Uri -Headers $authHeader -Method Post | Out-Null

if($NoTerminal){
  # Rename current to trash
  try {
    $ren1B = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/rename')
    $ren1B.Query = 'source=' + [uri]::EscapeDataString($dst) + '&dest=' + [uri]::EscapeDataString($trash)
    Invoke-WebRequest -Uri $ren1B.Uri -Headers $authHeader -Method Post | Out-Null
  } catch { Write-Host "Note: current rename may have failed (non-existent?): $_" -ForegroundColor DarkYellow }
  # Rename source to current
  $ren2B = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/rename')
  $ren2B.Query = 'source=' + [uri]::EscapeDataString($src) + '&dest=' + [uri]::EscapeDataString($dst)
  Invoke-WebRequest -Uri $ren2B.Uri -Headers $authHeader -Method Post | Out-Null
} else {
  $swapCmd = @(
    "set -e",
    "cd $DocrootBase",
    "if [ -d current ]; then mv current '$trash'; fi",
    "mv '$src' current"
  ) -join '; '
  $terminalUri = "https://$CpanelHost:2083/execute/Terminal/execute_command?command=$([uri]::EscapeDataString($swapCmd))"
  Invoke-WebRequest -Uri $terminalUri -Headers $authHeader -Method Post | Out-Null
}

# Maintenance off
$delB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/delete')
$delB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/maintenance.flag")
Invoke-WebRequest -Uri $delB.Uri -Headers $authHeader -Method Post | Out-Null

Write-Host "Rollback complete: $src -> $dst" -ForegroundColor Green
