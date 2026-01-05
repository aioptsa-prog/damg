param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$SshServer,
  [int]$Port = 22,
  [string]$User,
  [pscredential]$Credential,
  [string]$RemoteBase,
  [switch]$Maintenance,
  [switch]$Rollback,
  [switch]$FixPerms,
  [switch]$DryRun,
  [string]$Label
)
$ErrorActionPreference = 'Stop'
$eviDir = Join-Path $LocalPath 'storage\logs\validation'
New-Item -ItemType Directory -Force -Path $eviDir | Out-Null
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = if([string]::IsNullOrWhiteSpace($Label)) { '' } else { "_${Label}" }
$scriptOut = Join-Path $eviDir ("winscp_${ts}${suffix}.winscp")
$stdout = Join-Path $eviDir ("winscp_${ts}${suffix}.stdout.txt")
$stderr = Join-Path $eviDir ("winscp_${ts}${suffix}.stderr.txt")

$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = 'powershell'
$args = @(
  '-NoProfile','-ExecutionPolicy','Bypass','-Command',
  "& '" + (Join-Path $PSScriptRoot '..\deploy\deploy.ps1') + "' -LocalPath '" + $LocalPath + "' -SshServer '" + $SshServer + "' -Port " + $Port + " -User '" + $User + "' -Credential ([pscredential]::Empty) -RemoteBase '" + $RemoteBase + "' -Maintenance:$" + $Maintenance + " -Rollback:$" + $Rollback + " -FixPerms:$" + $FixPerms + " -DryRun:$" + $DryRun + " -OutputScriptPath '" + $scriptOut + "'"
)
$psi.Arguments = ($args -join ' ')
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.UseShellExecute = $false
$p = New-Object System.Diagnostics.Process
$p.StartInfo = $psi
$p.Start() | Out-Null
$p.WaitForExit()
$p.StandardOutput.ReadToEnd() | Set-Content -Path $stdout -Encoding UTF8
$p.StandardError.ReadToEnd()  | Set-Content -Path $stderr -Encoding UTF8
Write-Host "Saved: $scriptOut, $stdout, $stderr"
