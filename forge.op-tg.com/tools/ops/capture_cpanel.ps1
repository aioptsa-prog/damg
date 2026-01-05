param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$DocrootBase,
  [switch]$NoTerminal,
  [string]$Label
)
$ErrorActionPreference = 'Stop'
$eviDir = Join-Path $LocalPath 'storage\logs\validation'
New-Item -ItemType Directory -Force -Path $eviDir | Out-Null
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = if([string]::IsNullOrWhiteSpace($Label)) { '' } else { "_${Label}" }
$out = Join-Path $eviDir ("cpanel_${ts}${suffix}.txt")

$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = 'powershell'
$procArgs = @(
  '-NoProfile','-ExecutionPolicy','Bypass','-Command',
  "& '" + (Join-Path $PSScriptRoot '..\deploy\deploy_cpanel_uapi.ps1') + "' -LocalPath '" + $LocalPath + "' -CpanelHost '" + $CpanelHost + "' -CpanelUser '" + $CpanelUser + "' -ApiToken '" + $ApiToken + "' -DocrootBase '" + $DocrootBase + "' -PlanOnly -NoTerminal:$" + $NoTerminal
)
$psi.Arguments = ($procArgs -join ' ')
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.UseShellExecute = $false
$p = New-Object System.Diagnostics.Process
$p.StartInfo = $psi
$p.Start() | Out-Null
$p.WaitForExit()
@(
  $p.StandardOutput.ReadToEnd(),
  "--- STDERR ---`n" + $p.StandardError.ReadToEnd()
) -join "`n" | Set-Content -Path $out -Encoding UTF8
Write-Host "Saved: $out"
