param(
  [string]$Root = 'D:\LeadsMembershipPRO',
  [string]$BindHost = '127.0.0.1',
  [int]$Port = 8091
)
$ErrorActionPreference = 'Stop'
$baseUrl = ("http://{0}:{1}" -f $BindHost, $Port)
$logsDir = Join-Path $Root 'storage\logs\validation'
New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
$logFile = Join-Path $logsDir ("http_probe_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.txt')

Write-Host "Starting local PHP server at $baseUrl ..."
$proc = Start-Process -FilePath 'php' -ArgumentList '-S',("{0}:{1}" -f $BindHost,$Port),'-t',"$Root" -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 2

try {
  Write-Host 'Running HTTP probe...'
  $probe = & "$Root\tools\http_probe.ps1" -BaseUrl $baseUrl 2>&1 | Out-String
  $probe | Set-Content -Path $logFile -Encoding UTF8
  Write-Host "Probe output saved to: $logFile"
} finally {
  if($proc -and !$proc.HasExited){
    Write-Host 'Stopping local PHP server...'
    Stop-Process -Id $proc.Id -Force
  }
}
