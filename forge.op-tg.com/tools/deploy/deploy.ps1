param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$SshServer = 'nava3.mydnsway.com',
  [int]$Port = 22,
  [string]$User = 'optgccom',
  [pscredential]$Credential,
  [string]$RemoteBase = '/home/optgccom/nexus.op-tg.com',
  [switch]$DryRun,
  [switch]$Maintenance,
  [switch]$Rollback,
  [switch]$FixPerms,
  [string]$OutputScriptPath,
  [switch]$CaptureDirState,
  [string]$PrivateKeyPath,
  [switch]$LeaveMaintenance,
  [string]$WinSCPPath = 'WinSCP.com',
  [string]$HostKey,
  [string]$KeyPassphrase
)
# Requires WinSCP (https://winscp.net/) installed; set $env:PATH to include WinSCP.exe directory.
# Uses SFTP over SSH. If only FTP is available, adjust the session URL accordingly.

$ErrorActionPreference = 'Stop'

# Resolve plain password from PSCredential; if not provided, remain empty
$plainPassword = ''
if ($Credential) {
  $plainPassword = (New-Object System.Net.NetworkCredential('', $Credential.Password)).Password
}
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$releaseDir = "$RemoteBase/releases/$timestamp"
$remoteCurrent = "$RemoteBase/current"
$remotePrev = "$RemoteBase/prev_$timestamp"
$exclude = @(
  '.git/',
  '.vscode/',
  'node_modules/',
  'worker/ms-playwright/',
  'storage/logs/',
  '__release/',
  '*.zip',
  '*.bak',
  '*.tmp'
)

# Compose WinSCP script
$tempScriptPath = [System.IO.Path]::GetTempFileName()
$openParts = @('open')
$openParts += ('sftp://{0}@{1}:{2}/' -f $User, $SshServer, $Port)
if($PrivateKeyPath){
  $openParts += ('-privatekey="{0}"' -f ($PrivateKeyPath -replace '"','\"'))
}
if($KeyPassphrase){
  $openParts += ('-passphrase="{0}"' -f ($KeyPassphrase -replace '"','\\"'))
}
if($plainPassword -and $plainPassword.Trim() -ne ''){
  $openParts += ('-rawsettings')
  $openParts += ('PasswordPlain={0}' -f $plainPassword)
}
if($HostKey){
  $openParts += ('-hostkey="{0}"' -f ($HostKey -replace '"','\\"'))
}
$_openLine = ($openParts -join ' ')
$excludeMask = ($exclude | ForEach-Object { $_ -replace '\\','/' }) -join ";"
$localPath = $LocalPath -replace '\\','/'

$script = @()
$script += "option batch on"
$script += "option confirm off"
$script += $_openLine
$script += ("# maintenance on: {0}" -f $Maintenance)
if($Maintenance){ $script += "call touch $RemoteBase/maintenance.flag" }

if($Rollback){
  if($CaptureDirState){
    $before = ('echo === BEFORE (rollback) ===; ls -la {0}; echo --- releases ---; ls -la {0}/releases; echo --- current ---; ls -la {0}/current || true' -f $RemoteBase)
    $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $before)
  }
  # Rollback: promote latest prev_* back to current, stash current as prev_rollback_<ts>
  $rbCmd = ('set -e; base="{0}"; cur="{0}/current"; prev=$(ls -dt {0}/prev_* 2>/dev/null | head -n1); if [ -z "$prev" ]; then echo no prev found 1>&2; exit 1; fi; tmp="{0}/rollback_tmp_{1}"; mv "$cur" "$tmp"; mv "$prev" "$cur"; mv "$tmp" "{0}/prev_rollback_{1}"; echo rollback ok' -f $RemoteBase, $timestamp)
  $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $rbCmd)
  if($CaptureDirState){
    $after = ('echo === AFTER (rollback) ===; ls -la {0}; echo --- releases ---; ls -la {0}/releases; echo --- current ---; ls -la {0}/current || true' -f $RemoteBase)
    $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $after)
  }
} else {
  if($CaptureDirState){
    $before = ('echo === BEFORE (deploy) ===; ls -la {0}; echo --- releases ---; ls -la {0}/releases; echo --- current ---; ls -la {0}/current || true' -f $RemoteBase)
    $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $before)
  }
  $script += "call mkdir $releaseDir"
  $script += "synchronize remote -delete -criteria=time -filemask=|$excludeMask `"$localPath`" `"$releaseDir`""
  # Near-atomic swap with rollback on failure
  $swapCmd = ('set -e; cur="{0}/current"; prev="{0}/prev_{1}"; new="{2}"; if [ -d "$cur" ]; then mv "$cur" "$prev"; fi; if mv "$new" "$cur"; then echo swap ok; else echo swap failed 1>&2; if [ -d "$prev" ]; then mv "$prev" "$cur"; fi; exit 1; fi' -f $RemoteBase, $timestamp, $releaseDir)
  $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $swapCmd)
  if($FixPerms){ $script += ("call bash -lc 'chmod -R 755 {0} && find {0} -type f -exec chmod 644 {{}} +' || sh -lc 'chmod -R 755 {0} && find {0} -type f -exec chmod 644 {{}} +'" -f $RemoteBase) }
  # Clean older releases (keep last 3)
  $script += "call bash -lc 'cd $RemoteBase/releases && ls -1t | tail -n +4 | xargs -r rm -rf' || sh -lc 'cd $RemoteBase/releases && ls -1t | tail -n +4 | xargs -r rm -rf'"
  if($CaptureDirState){
    $after = ('echo === AFTER (deploy) ===; ls -la {0}; echo --- releases ---; ls -la {0}/releases; echo --- current ---; ls -la {0}/current || true' -f $RemoteBase)
    $script += ("call bash -lc '{0}' || sh -lc '{0}'" -f $after)
  }
}

if($Maintenance -and -not $LeaveMaintenance){ $script += "call rm -f $RemoteBase/maintenance.flag" }
$script += "exit"
$scriptText = ($script -join "`n")
Set-Content -LiteralPath $tempScriptPath -Value $scriptText -Encoding Ascii
if($OutputScriptPath){
  try { Set-Content -LiteralPath $OutputScriptPath -Value $scriptText -Encoding UTF8 } catch { }
}

Write-Host 'WinSCP script:' -ForegroundColor Cyan
# Mask password when echoing the script content
try {
  $escaped = if ($plainPassword) { [Regex]::Escape($plainPassword) } else { '' }
  $masked = if ($plainPassword) { $scriptText -replace $escaped, '***' } else { $scriptText }
  Write-Host $masked
} catch {
  Write-Host $scriptText
}

if($DryRun){ Write-Host "DryRun enabled - not executing."; exit 0 }

# Invoke WinSCP
$winScp = $WinSCPPath
if(-not (Test-Path $winScp)){
  # Try to resolve from PATH or common install locations
  $cmd = $null
  try{ $cmd = Get-Command WinSCP.com -ErrorAction SilentlyContinue }catch{}
  if($cmd){ $winScp = $cmd.Source }
}
if(-not (Test-Path $winScp)){
  $common = @(
    'C:\Program Files\WinSCP\WinSCP.com',
    'C:\Program Files (x86)\WinSCP\WinSCP.com'
  )
  foreach($p in $common){ if(Test-Path $p){ $winScp = $p; break } }
}
if(-not (Test-Path $winScp)){
  throw "WinSCP.com not found. Install WinSCP and ensure WinSCP.com is on PATH, or pass -WinSCPPath 'C:\\Program Files\\WinSCP\\WinSCP.com'"
}
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $winScp
$psi.Arguments = "/ini=nul /script=`"$tempScriptPath`""
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.UseShellExecute = $false
$p = New-Object System.Diagnostics.Process
$p.StartInfo = $psi
$null = $p.Start()
$p.WaitForExit()
Write-Host $p.StandardOutput.ReadToEnd()
if($p.ExitCode -ne 0){ Write-Host $p.StandardError.ReadToEnd(); throw "WinSCP failed with code $($p.ExitCode)" }

Write-Host ("Deploy completed to {0}:{1}" -f $SshServer, $RemoteBase) -ForegroundColor Green

# Cleanup temp file
try { Remove-Item -LiteralPath $tempScriptPath -Force -ErrorAction SilentlyContinue } catch {}
