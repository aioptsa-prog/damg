param(
  [string]$Releases = 'D:\LeadsMembershipPRO\storage\releases'
)

$ErrorActionPreference = 'Stop'
if(-not (Test-Path -LiteralPath $Releases)){
  Write-Host "Releases folder not found: $Releases" -ForegroundColor Red
  exit 1
}

$keep = @('latest.json','installer_meta.json')
$exeToKeep = $null
$zipToKeep = $null
$latestPath = Join-Path $Releases 'latest.json'
if(Test-Path -LiteralPath $latestPath){
  try {
    $j = Get-Content -Path $latestPath -Raw | ConvertFrom-Json
    if($j.url -and ($j.url -match '\\.zip$')){
      $zipName = [System.IO.Path]::GetFileName([string]$j.url)
      if($zipName){ $zipToKeep = $zipName }
    }
  } catch {}
}

if(-not $zipToKeep){
  # Fallback to the newest Portable zip
  $cand = Get-ChildItem -Path $Releases -Filter '*Portable*.zip' -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
  if($cand){ $zipToKeep = $cand.Name }
}

if($zipToKeep){ $keep += $zipToKeep }

# Also keep the newest installer EXE if present
$candExe = Get-ChildItem -Path $Releases -Filter 'OPTNexusWorker_Setup_v*.exe' -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if($candExe){ $exeToKeep = $candExe.Name }
if($exeToKeep){ $keep += $exeToKeep }

Write-Host 'Keeping:' -ForegroundColor Cyan
foreach($k in $keep){ Write-Host (' - ' + $k) }

$deleteList = Get-ChildItem -Path $Releases -File | Where-Object { $keep -notcontains $_.Name }
if($deleteList.Count -gt 0){
  Write-Host ('Deleting ' + $deleteList.Count + ' files...') -ForegroundColor Yellow
  $deleteList | Remove-Item -Force
} else {
  Write-Host 'Nothing to delete.' -ForegroundColor DarkGray
}

Write-Host 'Remaining files:' -ForegroundColor Green
Get-ChildItem -Path $Releases -File | Select-Object Name,Length,LastWriteTime | Sort-Object Name | Format-Table -AutoSize
