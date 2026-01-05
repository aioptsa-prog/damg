param(
  [string]$OutZip = 'nexus.op-tg.com-release.zip'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$proj = Resolve-Path (Join-Path $root '..')
$releaseDir = Join-Path $proj 'storage\releases'
if(!(Test-Path $releaseDir)){ New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null }
$staging = Join-Path $proj '__release'
if(Test-Path $staging){ Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Force -Path $staging | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'

function Copy-Tree($src,$dst){ robocopy $src $dst /MIR /NFL /NDL /NJH /NJS | Out-Null }

# Copy core files
Copy-Item -Path (Join-Path $proj 'index.php') -Destination $staging
Copy-Item -Path (Join-Path $proj 'bootstrap.php') -Destination $staging
Copy-Item -Path (Join-Path $proj 'layout_header.php') -Destination $staging
Copy-Item -Path (Join-Path $proj 'layout_footer.php') -Destination $staging
if(Test-Path (Join-Path $proj '.htaccess')){ Copy-Item -Path (Join-Path $proj '.htaccess') -Destination $staging }
if(Test-Path (Join-Path $proj 'maintenance.html')){ Copy-Item -Path (Join-Path $proj 'maintenance.html') -Destination $staging }

# Copy app directories (exclude project-level 'releases' to avoid bundling massive archives)
foreach($d in @('admin','agent','api','assets','auth','config','lib')){
  Copy-Tree (Join-Path $proj $d) (Join-Path $staging $d)
}

# Storage: include releases and empty logs
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'storage') | Out-Null
Copy-Tree (Join-Path $proj 'storage\releases') (Join-Path $staging 'storage\releases')
New-Item -ItemType Directory -Force -Path (Join-Path $staging 'storage\logs') | Out-Null

# Remove only site-*.zip from staging (نُبقي ملفات العامل OPTNexusWorker_*.zip)
$stagingReleases = Join-Path $staging 'storage\releases'
if(Test-Path $stagingReleases){ Get-ChildItem -Path $stagingReleases -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'site-*.zip' } | Remove-Item -Force -ErrorAction SilentlyContinue }

# Remove dev/test/worker content not needed on server
foreach($d in @('tests','worker','.git','.vscode','node_modules')){
  $p = Join-Path $staging $d
  if(Test-Path $p){ Remove-Item -Recurse -Force $p }
}

# Create zip with robust fallback (Compress-Archive -> ZipFile if needed)
$zipPath = Join-Path $releaseDir $OutZip
if(Test-Path $zipPath){ Remove-Item -Force $zipPath }
try {
  Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $zipPath -Force
  Write-Host "Release zip created: $zipPath"
} catch {
  Write-Host "Compress-Archive failed ($($_.Exception.Message)). Falling back to .NET ZipFile..." -ForegroundColor Yellow
  try {
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
    if(Test-Path $zipPath){ Remove-Item -Force $zipPath }
    [System.IO.Compression.ZipFile]::CreateFromDirectory($staging, $zipPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)
    Write-Host "ZipFile created: $zipPath" -ForegroundColor Green
  } catch {
    throw "Failed to create release zip via both Compress-Archive and ZipFile: $($_.Exception.Message)"
  }
}

# Also create a timestamped copy: site-<timestamp>.zip
$tsZip = Join-Path $releaseDir ("site-$timestamp.zip")
try {
  Copy-Item -Force $zipPath $tsZip
  Write-Host "Timestamped zip: $tsZip"
} catch {}

# Mirror artifacts to public releases/ folder for web serving on shared hosting and ensure staged copy
$publicRel = Join-Path $proj 'releases'
if(!(Test-Path $publicRel)){ New-Item -ItemType Directory -Force -Path $publicRel | Out-Null }
try { Copy-Item -Force $zipPath (Join-Path $publicRel (Split-Path -Leaf $zipPath)) } catch {}
try { if(Test-Path $tsZip){ Copy-Item -Force $tsZip (Join-Path $publicRel (Split-Path -Leaf $tsZip)) } } catch {}
# Ensure the staging includes the latest public releases directory (latest.json + artifacts) without re-zipping release zips
try {
  $dstRel = Join-Path $staging 'releases'
  if(Test-Path $dstRel){ Remove-Item -Recurse -Force $dstRel }
  New-Item -ItemType Directory -Force -Path $dstRel | Out-Null
  # Copy only latest.json and installer/portable artifacts; skip any nested release zips generated for site deploy
  $toCopy = Get-ChildItem -Path $publicRel -File | Where-Object { $_.Name -match '^(latest\.json|installer_meta\.json|OPTNexusWorker_.*\.(exe|zip))$' }
  foreach($f in $toCopy){ Copy-Item -Force $f.FullName (Join-Path $dstRel $f.Name) }
} catch {}
