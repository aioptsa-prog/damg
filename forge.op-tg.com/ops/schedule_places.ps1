param(
  [ValidateSet('Install','Update','Remove')][string]$Action = 'Install',
  [int]$EveryMinutes = 15,
  [string]$PhpPath = 'php.exe',
  [string]$Args = 'tools/ops/run_places_queue.php --max 5'
)
$ErrorActionPreference = 'Stop'

function Log($msg){
  $root = Split-Path -Parent $PSCommandPath
  $repo = (Resolve-Path "$root\..").Path
  $logDir = Join-Path $repo 'storage\logs\ops'
  if(!(Test-Path $logDir)){ New-Item -ItemType Directory -Force -Path $logDir | Out-Null }
  $log = Join-Path $logDir 'places_task.log'
  $ts = (Get-Date).ToString('s')
  "$ts $msg" | Out-File -FilePath $log -Append -Encoding UTF8
}

$repoRoot = (Resolve-Path "$PSScriptRoot\..").Path
$taskName = 'OptForge-PlacesQueue'
$startIn = $repoRoot
$arguments = "$Args"
$program = $PhpPath

switch ($Action) {
  'Install' {
    Log "Installing scheduled task $taskName to run every $EveryMinutes minutes"
    $trig = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes) -RepetitionDuration ([TimeSpan]::MaxValue)
    $act = New-ScheduledTaskAction -Execute $program -Argument $arguments -WorkingDirectory $startIn
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false }
    Register-ScheduledTask -TaskName $taskName -Action $act -Trigger $trig -Description 'Run Places queue runner periodically' | Out-Null
    Log "Installed."
  }
  'Update' {
    Log "Updating scheduled task $taskName (interval=$EveryMinutes, args=$arguments)"
    if (!(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue)) { throw "Task $taskName not found. Use -Action Install first." }
    $trig = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes) -RepetitionDuration ([TimeSpan]::MaxValue)
    $act = New-ScheduledTaskAction -Execute $program -Argument $arguments -WorkingDirectory $startIn
    Set-ScheduledTask -TaskName $taskName -Action $act -Trigger $trig | Out-Null
    Log "Updated."
  }
  'Remove' {
    Log "Removing scheduled task $taskName"
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false | Out-Null }
    Log "Removed."
  }
}
