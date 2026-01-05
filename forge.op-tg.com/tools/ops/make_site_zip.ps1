param(
  [string]$Root = 'D:\LeadsMembershipPRO',
  [string]$OutDir = 'D:\LeadsMembershipPRO\releases',
  [string]$OutName = 'site-release.zip',
  [switch]$IncludeReleases,
  [switch]$SkipWorkerBuild
)

$ErrorActionPreference = 'Stop'
if(-not (Test-Path -LiteralPath $Root)) { throw "Root not found: $Root" }
if(-not (Test-Path -LiteralPath $OutDir)) { New-Item -ItemType Directory -Force -Path $OutDir | Out-Null }

$zipPath = Join-Path $OutDir $OutName
if(Test-Path -LiteralPath $zipPath){ Remove-Item -LiteralPath $zipPath -Force }

if(-not $SkipWorkerBuild){
  Write-Host '[1/4] Building worker artifacts...' -ForegroundColor Cyan
  try {
    $builder = Join-Path $Root 'worker\build_installer.ps1'
    if(Test-Path -LiteralPath $builder){
  $psArgs = @('-NoProfile','-ExecutionPolicy','Bypass','-File', $builder)
  $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList $psArgs -Wait -PassThru -WindowStyle Hidden
      if($proc.ExitCode -ne 0){ Write-Warning ("worker build exited with code {0}, continuing" -f $proc.ExitCode) }
    } else {
      Write-Warning "worker build script not found at $builder"
    }
  } catch { Write-Warning "worker build failed: $_" }
} else {
  Write-Host '[1/4] Skipping worker build as requested.' -ForegroundColor DarkYellow
}

# Prepare a temp staging folder to assemble a clean site copy
$stage = Join-Path $OutDir '__site_stage'
if(Test-Path $stage){ Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Path $stage | Out-Null
Write-Host '[2/4] Staging site files (robocopy)...' -ForegroundColor Cyan

# Build robocopy exclude lists
# Exclude volatile/large caches and build artifacts to keep the package lean and avoid long-path issues
$xd = @(
  '.git',
  '.vscode',
  '.venv',
  '__release',
  'node_modules',
  'worker\ms-playwright',
  'worker\profile-data',
  'worker\\build',
  'worker\\node',
  'worker\downloads',
  'storage\logs',
  'storage\\releases',
  '__site_stage'
)
if($OutDir.StartsWith($Root, [System.StringComparison]::OrdinalIgnoreCase)){
  $xd += ($OutDir.Substring($Root.Length).TrimStart(("\\/".ToCharArray())))
}
if(-not $IncludeReleases){ $xd += 'releases' }
$xf = @('*.zip','*.bak','*.tmp')

$xdArgs = @();
foreach($d in $xd){
  if($d){
    # Exclude by absolute path (when possible)
    $xdArgs += @('/XD', (Join-Path $Root $d))
    # Also exclude by bare name to catch nested copies (e.g., any __site_stage or releases anywhere)
    $baseName = Split-Path -Leaf $d
    if($baseName){ $xdArgs += @('/XD', $baseName) }
  }
}
$xfArgs = @(); foreach($f in $xf){ $xfArgs += @('/XF', $f) }

$roboArgs = @($Root, $stage, '/E') + $xdArgs + $xfArgs
Write-Host ('robocopy ' + (($roboArgs | ForEach-Object { if($_ -match '\\s') { '"{0}"' -f $_ } else { $_ } }) -join ' ')) -ForegroundColor DarkGray
& robocopy @roboArgs | Out-Host
# Robocopy sets $LASTEXITCODE; values < 8 are success
$rc = $LASTEXITCODE
if($rc -ge 8){ throw ("robocopy failed with code {0}" -f $rc) }

# Optionally include releases folder (latest.json/meta + latest artifacts)
if($IncludeReleases){
  $srcRel = Join-Path $Root 'releases'
  if(Test-Path $srcRel){
    $dstRel = Join-Path $stage 'releases'
    if(-not (Test-Path $dstRel)){ New-Item -ItemType Directory -Path $dstRel | Out-Null }
    Get-ChildItem -LiteralPath $srcRel -Force | Where-Object { $_.Name -ne '__site_stage' -and $_.Name -notlike 'site-*.zip' } |
      Copy-Item -Destination $dstRel -Recurse -Force
  }
}

# Create the zip
Write-Host '[3/4] Creating ZIP archive...' -ForegroundColor Cyan
try {
  Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zipPath -Force -CompressionLevel Optimal
}
finally {
  Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
}
if(Test-Path -LiteralPath $zipPath){
  Write-Host ("[4/4] Site ZIP created: {0}" -f $zipPath) -ForegroundColor Green
} else {
  throw "Failed to create site ZIP at $zipPath"
}

# Normalize exit code to success only if we reached here
exit 0
