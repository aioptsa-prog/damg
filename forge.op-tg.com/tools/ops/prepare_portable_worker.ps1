<#
Prepares a fully offline Windows Worker bundle locally:
 - Ensures a portable Node runtime under worker\node (downloads if missing)
 - Installs npm dependencies into worker\node_modules (npm ci)
 - Prefetches Playwright Chromium into worker\ms-playwright
 - Copies vendor assets into storage\vendor (node-win64, node_modules, ms-playwright)

Usage (PowerShell, run from repo root):
  powershell -NoProfile -ExecutionPolicy Bypass -File tools\ops\prepare_portable_worker.ps1 -InstallPlaywright

After this, trigger a portable ZIP build by downloading:
  http(s)://<host>/api/download_worker.php?kind=zip

Parameters:
  -NodeVersion        Specific Node version to fetch if worker\node missing (default: auto, fallback 20.18.1)
  -InstallPlaywright  Prefetch Playwright Chromium into worker\ms-playwright
  -Clean              Remove existing worker\node_modules and re-install
  -VendorRoot         Output vendor root (default: storage\vendor)
#>

param(
  [string]$NodeVersion = '',
  [switch]$InstallPlaywright,
  [switch]$Clean,
  [string]$VendorRoot = 'storage\\vendor'
)

$ErrorActionPreference = 'Stop'

function Write-Info($msg){ Write-Host "[INFO] $msg" -ForegroundColor Cyan }
function Write-Warn($msg){ Write-Host "[WARN] $msg" -ForegroundColor Yellow }
function Write-Err($msg){ Write-Host "[ERROR] $msg" -ForegroundColor Red }

Push-Location (Resolve-Path "$PSScriptRoot\\..\\.." )
try {
  $repoRoot = Get-Location
  $workerDir = Join-Path $repoRoot 'worker'
  if (!(Test-Path $workerDir)) { throw "Worker folder not found at $workerDir" }

  Write-Info "Working in $workerDir"
  Push-Location $workerDir

  # 1) Ensure portable Node at worker\node
  $nodeDir = Join-Path $workerDir 'node'
  $nodeExe = Join-Path $nodeDir 'node.exe'
  if (!(Test-Path $nodeExe)) {
    Write-Info 'Portable Node runtime not found. Attempting to download a Windows x64 Node zip...'
    $ver = $NodeVersion
    if (-not $ver) {
      try {
        $nv = (& node -v) 2>$null
        if ($nv) { $ver = ($nv -replace '^v','').Trim() }
      } catch {}
    }
    if (-not $ver -or -not ($ver -match '^[0-9]+\.[0-9]+\.[0-9]+$')) { $ver = '20.18.1' }
    $zipName = "node-v$ver-win-x64.zip"
    $url = "https://nodejs.org/dist/v$ver/$zipName"

    $buildDir = Join-Path $workerDir 'build'
    if (!(Test-Path $buildDir)) { New-Item -ItemType Directory -Force -Path $buildDir | Out-Null }
    $dlPath = Join-Path $buildDir $zipName

    try {
      Write-Info "Downloading Node $ver ... ($url)"
      Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $dlPath -ErrorAction Stop | Out-Null
      if (Test-Path $nodeDir) { Remove-Item $nodeDir -Recurse -Force }
      New-Item -ItemType Directory -Force -Path $nodeDir | Out-Null
      Write-Info 'Extracting Node runtime...'
      Expand-Archive -Path $dlPath -DestinationPath $buildDir -Force
      $unzDir = Join-Path $buildDir "node-v$ver-win-x64"
      if (!(Test-Path $unzDir)) { throw "Unexpected unzip directory not found: $unzDir" }
      Copy-Item -Path (Join-Path $unzDir '*') -Destination $nodeDir -Recurse -Force
      Remove-Item $unzDir -Recurse -Force
      Remove-Item $dlPath -Force
    } catch {
      Write-Warn "Failed to fetch portable Node ($url). You must have system Node installed to proceed. Error: $_"
    }
  } else {
    Write-Info 'Portable Node runtime already present.'
  }

  # Ensure npm from portable Node is on PATH for this session
  $env:PATH = "$nodeDir;$env:PATH"

  # 2) Install node_modules
  if ($Clean -and (Test-Path (Join-Path $workerDir 'node_modules'))) {
    Write-Info 'Cleaning existing node_modules ...'
    Remove-Item -Recurse -Force (Join-Path $workerDir 'node_modules')
  }
  if (Test-Path (Join-Path $workerDir 'package-lock.json')) {
    Write-Info 'Installing npm dependencies via npm ci ...'
    try {
      cmd /c "npm ci" | Out-Host
    } catch {
      Write-Warn "npm ci failed; attempting npm install ... $_"
      try { cmd /c "npm install" | Out-Host } catch { throw $_ }
    }
  } else {
    Write-Info 'package-lock.json not found; running npm install ...'
    cmd /c "npm install" | Out-Host
  }

  # 3) Prefetch Playwright Chromium (optional)
  if ($InstallPlaywright) {
    Write-Info 'Prefetching Playwright Chromium into worker\ms-playwright ...'
    $env:PLAYWRIGHT_BROWSERS_PATH = 'ms-playwright'
    try {
      cmd /c "npx playwright install chromium --with-deps" | Out-Host
    } catch {
      Write-Warn "Playwright prefetch failed; worker may download browsers on first run. $_"
    } finally {
      Remove-Item Env:PLAYWRIGHT_BROWSERS_PATH -ErrorAction SilentlyContinue
    }
  } else {
    Write-Info 'Skipping Playwright prefetch (use -InstallPlaywright to enable).'
  }

  # 4) Copy vendor assets into storage\vendor
  Pop-Location # back to repo root
  $vendorRootAbs = Resolve-Path $VendorRoot -ErrorAction SilentlyContinue
  if (-not $vendorRootAbs) { New-Item -ItemType Directory -Force -Path $VendorRoot | Out-Null; $vendorRootAbs = Resolve-Path $VendorRoot }
  $vendorRootAbs = $vendorRootAbs.Path
  Write-Info "Publishing vendor assets into $vendorRootAbs"

  $destNode = Join-Path $vendorRootAbs 'node-win64'
  $destMods = Join-Path $vendorRootAbs 'node_modules'
  $destPW = Join-Path $vendorRootAbs 'ms-playwright'

  if (Test-Path (Join-Path $workerDir 'node')) {
    if (Test-Path $destNode) { Remove-Item $destNode -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $destNode | Out-Null
    Copy-Item -Path (Join-Path $workerDir 'node\*') -Destination $destNode -Recurse -Force
  } else {
    Write-Warn 'worker\node not found; server will rely on system Node or worker.exe'
  }

  if (Test-Path (Join-Path $workerDir 'node_modules')) {
    if (Test-Path $destMods) { Remove-Item $destMods -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $destMods | Out-Null
    Write-Info 'Copying node_modules (this may take a while)...'
    Copy-Item -Path (Join-Path $workerDir 'node_modules\*') -Destination $destMods -Recurse -Force
  } else {
    Write-Warn 'worker\node_modules not found after install.'
  }

  if (Test-Path (Join-Path $workerDir 'ms-playwright')) {
    if (Test-Path $destPW) { Remove-Item $destPW -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $destPW | Out-Null
    Write-Info 'Copying ms-playwright cache ...'
    Copy-Item -Path (Join-Path $workerDir 'ms-playwright\*') -Destination $destPW -Recurse -Force
  } else {
    Write-Info 'No ms-playwright folder found (Playwright prefetch skipped or failed).'
  }

  $readme = @(
    'This folder contains vendor assets used to build a fully portable worker ZIP on the server.',
    'Copied by tools\\ops\\prepare_portable_worker.ps1',
    'Subfolders:',
    ' - node-win64: Portable Windows Node runtime (node.exe + stdlib)',
    ' - node_modules: npm dependencies for worker\\package.json',
    ' - ms-playwright: Playwright browser cache (optional)'
  )
  $readmePath = Join-Path $vendorRootAbs 'README_VENDOR.txt'
  $readme | Set-Content -Path $readmePath -Encoding UTF8

  Write-Host ''
  Write-Host 'Done. Next steps:' -ForegroundColor Green
  Write-Host '  1) Start the local PHP server (if needed) and request: /api/download_worker.php?kind=zip' -ForegroundColor Green
  Write-Host '  2) Verify the ZIP size (> 30MB) and that OptForgeWorker/node/node.exe exists inside it' -ForegroundColor Green
  Write-Host '  3) Test by extracting and running worker_run.bat on a Windows machine' -ForegroundColor Green

} finally {
  Pop-Location
}
