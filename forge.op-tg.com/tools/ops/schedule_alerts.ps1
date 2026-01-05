param(
  [string]$PhpPath = 'php.exe',
  [string]$ScriptPath = 'd:\\Projects\\nexus.op-tg.com\\tools\\ops\\alerts_tick.php',
  [int]$EveryMinutes = 5,
  [ValidateSet('Install','Remove')][string]$Action = 'Install',
  [string]$TaskName = 'OptForge-AlertsTick'
)

$phpCmd = (Get-Command $PhpPath -ErrorAction SilentlyContinue)
if ($phpCmd -eq $null) { Write-Error "PHP not found: $PhpPath"; exit 1 }
$fullPhp = $phpCmd.Path
if (-not (Test-Path $ScriptPath)) { Write-Error "Script not found: $ScriptPath"; exit 1 }

if ($Action -eq 'Remove') {
  schtasks /Delete /TN $TaskName /F | Out-Null
  Write-Host "Removed task $TaskName"
  exit 0
}

if ($EveryMinutes -lt 1) { $EveryMinutes = 5 }

# Use schtasks for compatibility with Windows PowerShell 5.1
# Create or update the task to run every N minutes under the current user context
try {
  # Delete existing task if present
  schtasks /Query /TN $TaskName > $null 2>&1
  if ($LASTEXITCODE -eq 0) {
    schtasks /Delete /TN $TaskName /F | Out-Null
  }

  $tr = '"' + $fullPhp + '" ' + '"' + $ScriptPath + '"'
  schtasks /Create /TN $TaskName /TR $tr /SC MINUTE /MO $EveryMinutes /F | Out-Null
  Write-Host "Installed task $TaskName to run every $EveryMinutes minute(s)"
} catch {
  Write-Error $_.Exception.Message
  exit 1
}
