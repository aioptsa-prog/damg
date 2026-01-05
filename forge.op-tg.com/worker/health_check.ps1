param(
  [string]$BaseDir = (Get-Location).Path
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $BaseDir
Write-Host '== OPT Nexus Worker - Health Check ==' -ForegroundColor Cyan
Write-Host ("BaseDir: {0}" -f (Get-Location).Path)

function Pass($m){ Write-Host ("PASS  - {0}" -f $m) -ForegroundColor Green }
function Warn($m){ Write-Host ("WARN  - {0}" -f $m) -ForegroundColor Yellow }
function Fail($m){ Write-Host ("FAIL  - {0}" -f $m) -ForegroundColor Red }

function Test-Node() {
  try {
    $v = (& node -v) 2>$null
    if (-not $v) { Fail 'Node.js not found in PATH'; return $false }
    $ver = $v.Trim('v') -as [version]
  if ($ver.Major -lt 18) { Warn "Node.js $v detected - recommend v18+" } else { Pass "Node.js $v" }
    return $true
  } catch { Fail 'Node.js not available'; return $false }
}

function Test-Npm(){ try { $nv = (& npm -v) 2>$null; if($nv){ Pass "npm $nv"; return $true } else { Warn 'npm not found' ; return $false } } catch { Warn 'npm not available'; return $false } }

function Read-DotEnv($path){
  $map = @{}
  if(Test-Path $path){ Get-Content $path | ForEach-Object {
    if($_ -match '^\s*$' -or $_ -match '^\s*#'){ return }
    $i = $_.IndexOf('='); if($i -gt 0){ $k=$_.Substring(0,$i).Trim(); $v=$_.Substring($i+1); $map[$k]=$v }
  } }
  return $map
}

function Test-Env(){
  $envFile = Join-Path $BaseDir '.env'
  if(!(Test-Path $envFile)){ Warn '.env not found - open http://127.0.0.1:4499/setup to configure first run'; return $false }
  $vars = Read-DotEnv $envFile
  $ok = $true
  foreach($k in @('INTERNAL_SECRET','WORKER_ID')){ if(-not $vars[$k]){ Fail "Missing $k in .env"; $ok=$false } }
  if(-not ($vars['BASE_URL']) -and -not ($vars['WORKER_CONF_URL'])){ Fail 'Missing BASE_URL or WORKER_CONF_URL'; $ok=$false } else { Pass "BASE set via BASE_URL/WORKER_CONF_URL" }
  return $ok
}

function Test-Connectivity($base){
  try {
    $url = "$base/api/heartbeat.php"
    $res = Invoke-WebRequest -UseBasicParsing -Uri $url -Headers @{ 'X-Internal-Secret' = 'placeholder' } -Method GET -TimeoutSec 10
    if($res.StatusCode -ge 200 -and $res.StatusCode -lt 500){ Pass "Backend reachable: $base (HTTP $($res.StatusCode))" } else { Warn "Backend response: HTTP $($res.StatusCode)" }
  } catch { $msg = $_.Exception.Message; Warn ("Backend not reachable at {0}: {1}" -f $base, $msg) }
}

function Test-Playwright(){
  try {
    $out = node -e "console.log(require('playwright/package.json').version)" 2>$null
    if($LASTEXITCODE -eq 0 -and $out){ Pass "Playwright installed v$out" } else { Warn 'Playwright not resolvable from node_modules' }
  } catch { Warn 'Playwright check failed' }
}

function Test-Chromium(){
  try {
    $hasChrome = Test-Path 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    if($hasChrome){ Pass 'Google Chrome detected' }
  else { Write-Host 'Chrome not found - will rely on Playwright Chromium.' -ForegroundColor Yellow }
  } catch { }
}

# Run checks
$okNode = Test-Node
$okNpm = Test-Npm
$okEnv = Test-Env
Test-Playwright
Test-Chromium

# Suggest commands
Write-Host
Write-Host 'Suggested next steps:' -ForegroundColor Cyan
if(-not $okEnv){ Write-Host ' - Open http://127.0.0.1:4499/setup to complete .env' }
if(-not $okNode){ Write-Host ' - Install Node.js v18+ (LTS) and re-run' }
if($okNpm){ Write-Host ' - Ensure dependencies: npm i' }
Write-Host ' - Ensure Chromium: npx playwright install chromium' 
Write-Host ' - Start worker: node index.js'

Write-Host
Pass 'Health check finished.'
