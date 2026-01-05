param(
  [string]$Root = 'D:\LeadsMembershipPRO'
)
$ErrorActionPreference = 'Stop'
$evi = Join-Path $Root 'storage\logs\validation'
$bundle = Join-Path $Root ('storage\releases\evidence_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.zip')
if(!(Test-Path $evi)){ throw "Evidence directory not found: $evi" }
# Stage helpful docs and logs for bundling
try {
  $uw = Join-Path $Root 'storage\logs\update-worker.log'
  if(Test-Path $uw){ Copy-Item -LiteralPath $uw -Destination (Join-Path $evi 'update-worker.log') -Force }
  foreach($doc in @('docs\EVIDENCE_CHECKLIST.md','docs\EVIDENCE_NOTES.md','docs\RUNBOOK.md','docs\CHANGELOG.md')){
    $src = Join-Path $Root $doc
    if(Test-Path $src){ Copy-Item -LiteralPath $src -Destination (Join-Path $evi ([IO.Path]::GetFileName($src))) -Force }
  }
} catch {}
Compress-Archive -Path (Join-Path $evi '*') -DestinationPath $bundle -Force
Write-Host "Evidence bundle created: $bundle"