param(
  [ValidateSet('Install','Update','Remove')]
  [string]$Action = 'Install',
  [int]$EveryDays = 1,
  [string]$PhpPath = 'php.exe',
  [string]$PhpArgs = 'tools/rotate_logs.php --max-size=20 --max-days=14',
  [string]$TaskName = 'OptForge-RotateLogs'
)

$ErrorActionPreference = 'Stop'
$taskExists = $false
try { $null = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop; $taskExists = $true } catch { $taskExists = $false }

if ($Action -eq 'Remove') {
  if ($taskExists) { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false }
  Write-Host "Removed task $TaskName (if existed)"
  exit 0
}

# Build the full command
$workdir = (Get-Location).Path
$cmd = "`"$PhpPath`" $PhpArgs"
$trigger = New-ScheduledTaskTrigger -Daily -DaysInterval $EveryDays -At 03:15
$taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -Command cd `"$workdir`"; $cmd"
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -StartWhenAvailable -MultipleInstances Ignore
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
$task = New-ScheduledTask -Action $taskAction -Trigger $trigger -Settings $settings -Principal $principal

try {
  if ($taskExists) {
    Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
    Write-Host "Updated task $TaskName to run every $EveryDays day(s)."
  } else {
    Register-ScheduledTask -TaskName $TaskName -InputObject $task | Out-Null
    Write-Host "Installed task $TaskName to run every $EveryDays day(s)."
  }
} catch {
  # Fallback to schtasks for PS 5.1 compatibility
  try {
    if ($taskExists) { schtasks /Delete /TN $TaskName /F | Out-Null }
    $tr = 'powershell.exe -NoProfile -WindowStyle Hidden -Command cd ' + '"' + $workdir + '"' + '; ' + $cmd
    schtasks /Create /TN $TaskName /TR $tr /SC DAILY /ST 03:15 /F | Out-Null
    Write-Host "Installed task via schtasks: $TaskName"
  } catch {
    Write-Error $_.Exception.Message; exit 1
  }
}
