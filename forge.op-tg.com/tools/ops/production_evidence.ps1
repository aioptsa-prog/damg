param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$SshServer = 'nava3.mydnsway.com',
  [int]$Port = 22,
  [string]$User = 'optgccom',
  [string]$RemoteBase = '/home/optgccom/nexus.op-tg.com',
  [string]$BaseUrl = 'https://nexus.op-tg.com',
  [switch]$FixPerms,
  [string]$PrivateKeyPath,
  [pscredential]$Credential,
  [switch]$LeaveMaintenance,
  [string]$Label,
  [string]$WinSCPPath,
  [string]$HostKey,
  [string]$KeyPassphrase
)
$ErrorActionPreference = 'Stop'
$validDir = Join-Path $LocalPath 'storage\logs\validation'
New-Item -ItemType Directory -Force -Path $validDir | Out-Null
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = if([string]::IsNullOrWhiteSpace($Label)) { '' } else { "_" + $Label }

# 1) Maintenance deploy with directory state capture
Write-Host '--- SFTP deploy (maintenance, capture dir state) ---' -ForegroundColor Cyan
& "$LocalPath\tools\deploy\deploy.ps1" -LocalPath $LocalPath -SshServer $SshServer -Port $Port -User $User -Credential $Credential -RemoteBase $RemoteBase -Maintenance -CaptureDirState -FixPerms:$FixPerms -PrivateKeyPath $PrivateKeyPath -LeaveMaintenance:$LeaveMaintenance -WinSCPPath $WinSCPPath -HostKey $HostKey -KeyPassphrase $KeyPassphrase

# 2) Production probes
Write-Host '--- HTTP probes (production) ---' -ForegroundColor Cyan
$probeOut = Join-Path $validDir ("http_probe_prod_" + $ts + $suffix + ".txt")
& "$LocalPath\tools\http_probe.ps1" -BaseUrl $BaseUrl -OutFile $probeOut

# 3) Bundle evidence
Write-Host '--- Bundling evidence ---' -ForegroundColor Cyan
& "$LocalPath\tools\ops\make_evidence_bundle.ps1" -Root $LocalPath
Write-Host 'Production evidence capture complete.' -ForegroundColor Green
