param(
  [string]$Root = 'D:\LeadsMembershipPRO',
  [string]$ResultsPath = 'D:\LeadsMembershipPRO\storage\data\geo\sa\acceptance_results.json'
)
$ErrorActionPreference = 'Stop'
if(!(Test-Path $ResultsPath)){ throw "Results not found: $ResultsPath" }
$json = Get-Content -LiteralPath $ResultsPath -Raw | ConvertFrom-Json
$summary = "- Geo Acceptance (SA): accuracy={0:P2}; p50={1}ms; p95={2}ms; n={3}" -f ($json.accuracy), ($json.timings.p50_ms), ($json.timings.p95_ms), ($json.n)
$ch = Join-Path $Root 'docs\CHANGELOG.md'
if(!(Test-Path $ch)){ throw "CHANGELOG not found: $ch" }
$content = Get-Content -LiteralPath $ch -Raw
$stamp = (Get-Date).ToString('yyyy-MM-dd')
$insert = "`n$stamp — Acceptance Update`n$summary`n"
Set-Content -LiteralPath $ch -Value ($content + $insert) -Encoding UTF8
Write-Host "CHANGELOG updated with acceptance summary."