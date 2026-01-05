param(
  [string]$OutZip = 'nexus.op-tg.com-release.zip',
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$SshServer = 'nava3.mydnsway.com',
  [int]$Port = 22,
  [string]$User = 'optgccom',
  [pscredential]$Credential,
  [string]$RemoteBase = '/home/optgccom/nexus.op-tg.com',
  [string]$LatestUrl,
  [switch]$Maintenance,
  [string]$HostKey,
  [string]$PrivateKeyPath,
  [string]$KeyPassphrase
)
$ErrorActionPreference = 'Stop'
# 0) Build the Windows worker installer first so it’s baked into storage/releases
& "$LocalPath\worker\build_installer.ps1"

# 1) Generate latest.json for the installer so it’s included in staging
if($LatestUrl){ $env:LATEST_URL = $LatestUrl }
php "$PSScriptRoot\..\gen_latest.php"
if($LatestUrl){ Remove-Item Env:\LATEST_URL }

# 2) Build clean zip and staging (__release) which will now include the installer and latest.json
& "$PSScriptRoot\..\make_release.ps1" -OutZip $OutZip

# 3) Deploy via WinSCP
& "$PSScriptRoot\deploy.ps1" -LocalPath $LocalPath -SshServer $SshServer -Port $Port -User $User -Credential $Credential -RemoteBase $RemoteBase -Maintenance:$Maintenance -HostKey $HostKey -PrivateKeyPath $PrivateKeyPath -KeyPassphrase $KeyPassphrase
Write-Host 'Release + Deploy complete.' -ForegroundColor Green
