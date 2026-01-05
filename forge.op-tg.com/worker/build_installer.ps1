<#
Rewritten: clean build script with code signing hooks and Arabic-enabled installer
#>

param(
  [switch]$Sign,
  [string]$CertThumbprint,
  [string]$PfxPath,
  [SecureString]$PfxPassword,
  [string]$TimestampUrl = 'http://timestamp.digicert.com',
  [ValidateSet('auto','ar','en')][string]$DefaultLanguage = 'auto'
)

$ErrorActionPreference = 'Stop'

Write-Host 'Installing npm dependencies (builder machine only)...'
Push-Location $PSScriptRoot
try { if (Test-Path (Join-Path $PSScriptRoot 'package.json')) { cmd /c "npm i" | Out-Host } else { Write-Host 'Skipping npm install (no package.json in worker folder).' } } catch { Write-Warning 'npm install failed on builder; proceeding (dependencies will be bundled if present).' }

Write-Host 'Pre-fetching Playwright Chromium locally (for offline install)...'
$env:PLAYWRIGHT_BROWSERS_PATH = 'ms-playwright'
try { cmd /c "npx playwright install chromium --with-deps" | Out-Host } catch { Write-Warning 'Playwright prefetch failed; ensure ms-playwright exists or system has internet.' }

Write-Host 'Building optional worker.exe via pkg (launcher.js)...'
try { cmd /c "npx --yes pkg -t node18-win-x64 launcher.js -o worker.exe" | Out-Host } catch { Write-Warning 'pkg build failed; will rely on embedded portable Node.' }

function Invoke-CodeSign {
  param([Parameter(Mandatory=$true)][string]$PathToFile)
  if (-not $Sign) { return }
  $signtool = (Get-Command signtool -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
  if (-not $signtool) { Write-Warning 'Sign requested but signtool not found in PATH. Skipping signing.'; return }
  $sigArgs = @('sign','/fd','SHA256','/tr', $TimestampUrl, '/td','SHA256')
  if ($CertThumbprint) { $sigArgs += @('/sha1', $CertThumbprint) }
  elseif ($PfxPath) {
    $sigArgs += @('/f', $PfxPath)
    if ($PfxPassword) {
      try {
        $pfxPwdPlain = [Runtime.InteropServices.Marshal]::PtrToStringUni([Runtime.InteropServices.Marshal]::SecureStringToBSTR($PfxPassword))
        if ($pfxPwdPlain) { $sigArgs += @('/p', $pfxPwdPlain) }
      } catch {}
    }
  }
  else { $sigArgs += '/a' }
  $sigArgs += @('/d','OptForge Worker','/du','https://nexus.op-tg.com', $PathToFile)
  Write-Host "Signing $PathToFile ..."; & $signtool @sigArgs | Out-Host
}

if (Test-Path (Join-Path $PSScriptRoot 'worker.exe')) { Invoke-CodeSign -PathToFile (Join-Path $PSScriptRoot 'worker.exe') }

$buildDir = Join-Path $PSScriptRoot 'build'
if (!(Test-Path $buildDir)) { New-Item -ItemType Directory -Force -Path $buildDir | Out-Null }
$newIss = Join-Path $buildDir 'worker_installer.iss'

Write-Host 'Ensuring a portable Node runtime is bundled (node\\node.exe)...'
$portableNodeDir = Join-Path $PSScriptRoot 'node'
$portableNodeExe = Join-Path $portableNodeDir 'node.exe'
try {
  if (!(Test-Path $portableNodeExe)) {
    # Try to match the local Node version; fallback to a sensible LTS if unknown
    $nodeVer = ''
    try {
      $nv = (node -v) 2>$null
      if ($nv) { $nodeVer = ($nv -replace '^v','').Trim() }
    } catch {}
    if (-not $nodeVer -or -not ($nodeVer -match '^\d+\.\d+\.\d+$')) { $nodeVer = '20.18.1' }
    $zipName = "node-v$nodeVer-win-x64.zip"
    $nodeZipUrl = "https://nodejs.org/dist/v$nodeVer/$zipName"
    $dlPath = Join-Path $buildDir $zipName
    Write-Host "Downloading portable Node $nodeVer ..."
    try {
      Invoke-WebRequest -UseBasicParsing -Uri $nodeZipUrl -OutFile $dlPath -ErrorAction Stop | Out-Null
      if (Test-Path $portableNodeDir) { Remove-Item $portableNodeDir -Recurse -Force }
      New-Item -ItemType Directory -Path $portableNodeDir | Out-Null
      Write-Host 'Extracting portable Node...'
      Expand-Archive -Path $dlPath -DestinationPath $buildDir -Force
      $unzDir = Join-Path $buildDir ("node-v$nodeVer-win-x64")
      if (Test-Path $unzDir) {
        Copy-Item -Path (Join-Path $unzDir '*') -Destination $portableNodeDir -Recurse -Force
        Remove-Item $unzDir -Recurse -Force
      }
      Remove-Item $dlPath -Force
    } catch {
      Write-Warning "Failed to fetch portable Node runtime ($nodeZipUrl). The portable ZIP will require system Node or worker.exe. Error: $_"
    }
  }
} catch { Write-Warning "Portable Node bundling step failed: $_" }

$AppName = 'OptForge Worker'
$AppVer = '1.5.1'
$DefaultDirName = '{pf64}\\OptForgeWorker'
$SourceDir = $PSScriptRoot

# Prepare SignTool directive if requested
$signToolLine = ''
if ($Sign) {
  if ($CertThumbprint) { $signToolLine = "SignTool=signtool sign /fd SHA256 /tr $TimestampUrl /td SHA256 /sha1 $CertThumbprint $f" }
  elseif ($PfxPath) {
    $pfxPwdStr = ''
    if ($PfxPassword) {
      try {
        $pfxPwdStr = [Runtime.InteropServices.Marshal]::PtrToStringUni([Runtime.InteropServices.Marshal]::SecureStringToBSTR($PfxPassword))
        if ($pfxPwdStr) { $pfxPwdStr = " /p $pfxPwdStr" }
      } catch {}
    }
    $signToolLine = "SignTool=signtool sign /fd SHA256 /tr $TimestampUrl /td SHA256 /f $PfxPath$pfxPwdStr $f"
  }
  else { $signToolLine = "SignTool=signtool sign /fd SHA256 /tr $TimestampUrl /td SHA256 /a $f" }
}

function Get-LanguageDirectives {
  param([string]$Mode)
  switch($Mode){
    'en' { return @('DefaultLanguage=en','ShowLanguageDialog=no','LanguageDetectionMethod=uilanguage','UsePreviousLanguage=yes') }
    'ar' { return @('DefaultLanguage=ar','ShowLanguageDialog=no','LanguageDetectionMethod=uilanguage','UsePreviousLanguage=yes') }
    default { return @('ShowLanguageDialog=auto','LanguageDetectionMethod=uilanguage','UsePreviousLanguage=yes') }
  }
}
$langDirectives = Get-LanguageDirectives -Mode $DefaultLanguage
$langLines = ($langDirectives -join "`n")

$iss = @"
[Setup]
AppName=${AppName}
AppVersion=${AppVer}
DefaultDirName=${DefaultDirName}
DefaultGroupName=OptForge Worker
DisableDirPage=no
PrivilegesRequired=admin
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesInstallIn64BitMode=x64
ArchitecturesAllowed=x64compatible
OutputDir="${buildDir}"
OutputBaseFilename=OptForgeWorker_Setup_v${AppVer}
Compression=lzma
SolidCompression=yes
WizardStyle=modern
SignedUninstaller=yes
${signToolLine}
${langLines}

[Languages]
Name: "en"; MessagesFile: "compiler:Default.isl"
Name: "ar"; MessagesFile: "compiler:Languages\\Arabic.isl"

[Tasks]
Name: "installservice"; Description: "تثبيت كخدمة ويندوز (تشغيل تلقائي عند بدء التشغيل)"; Flags: checkedonce
Name: "userautostart"; Description: "تشغيل تلقائي للمستخدم الحالي (إنشاء اختصار بدء التشغيل)"; Flags: unchecked

[Dirs]
Name: "{app}\\logs"; Flags: uninsalwaysuninstall

[Files]
Source: "${SourceDir}\\index.js"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\launcher.js"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\package.json"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\health_check.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\install_service.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\worker_service.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\worker_run.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\.env.example"; DestDir: "{app}"; Flags: ignoreversion onlyifdoesntexist
Source: "${SourceDir}\\worker.exe"; DestDir: "{app}"; Flags: ignoreversion skipifsourcedoesntexist
Source: "${SourceDir}\\package-lock.json"; DestDir: "{app}"; Flags: ignoreversion
Source: "${SourceDir}\\node_modules\\*"; DestDir: "{app}\\node_modules"; Flags: recursesubdirs createallsubdirs ignoreversion skipifsourcedoesntexist
Source: "${SourceDir}\\ms-playwright\\*"; DestDir: "{app}\\ms-playwright"; Flags: recursesubdirs createallsubdirs ignoreversion skipifsourcedoesntexist
; Bundle a portable Node runtime so the app can run without system Node and without relying on pkg
Source: "${SourceDir}\\node\\*"; DestDir: "{app}\\node"; Flags: recursesubdirs createallsubdirs ignoreversion skipifsourcedoesntexist

[Icons]
Name: "{group}\\OptForge Worker (Console .bat)"; Filename: "{app}\\worker_run.bat"
Name: "{group}\\Install as Service"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \"\"{app}\\install_service.ps1\"\""; WorkingDir: "{app}"
Name: "{commondesktop}\\OptForge Worker"; Filename: "{app}\\worker_run.bat"; Comment: "تشغيل العامل"
Name: "{userstartup}\\OptForge Worker (AutoStart)"; Filename: "{app}\\worker_run.bat"; Tasks: userautostart; Comment: "تشغيل تلقائي للمستخدم الحالي"
Name: "{group}\\View Logs"; Filename: "notepad.exe"; Parameters: "\"\"{app}\\logs\\service-out.log\"\""; WorkingDir: "{app}"

[Run]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \"\"{app}\\install_service.ps1\"\""; Tasks: installservice; Flags: postinstall skipifsilent runminimized
Filename: "{app}\\worker_run.bat"; Flags: postinstall skipifsilent nowait; Tasks: not installservice
Filename: "{cmd}"; Parameters: "/c start http://127.0.0.1:4499/status"; Flags: postinstall skipifsilent nowait

[Code]
var Page: TInputQueryWizardPage;

procedure InitializeWizard;
begin
  Page := CreateInputQueryPage(wpSelectDir, 'Connection Setup (تهيئة الاتصال)', 'Enter server settings (أدخل إعدادات الاتصال بالخادم)', '');
  Page.Add('BASE_URL:', False); // Example: https://nexus.op-tg.com
  Page.Add('INTERNAL_SECRET:', False); // Same secret set in admin settings
  Page.Add('WORKER_ID:', False);
  Page.Add('PULL_INTERVAL_SEC:', False);
  Page.Values[0] := 'https://nexus.op-tg.com';
  Page.Values[2] := 'pc-01';
  Page.Values[3] := '30';
end;

procedure CurStepChanged(CurStep: TSetupStep);
var f: string; S: AnsiString;
begin
  if CurStep = ssInstall then begin
    f := ExpandConstant('{app}\\.env');
    if Page.Values[0] = '' then Page.Values[0] := 'https://nexus.op-tg.com';
    if Page.Values[2] = '' then Page.Values[2] := 'pc-01';
    if Page.Values[3] = '' then Page.Values[3] := '30';
    S := 'BASE_URL=' + Page.Values[0] + #13#10 +
         'INTERNAL_SECRET=' + Page.Values[1] + #13#10 +
         'WORKER_ID=' + Page.Values[2] + #13#10 +
         'PULL_INTERVAL_SEC=' + Page.Values[3] + #13#10 +
      'HEADLESS=true' + #13#10 +
         'MAX_PAGES=3' + #13#10 +
         'DEBUG_SNAPSHOTS=1' + #13#10 +
         'PLAYWRIGHT_BROWSERS_PATH=%CD%\\ms-playwright' + #13#10;
    SaveStringToFile(f, S, False);
  end;
end;
"@

# Write ISS with UTF-8 BOM to fix Arabic rendering
$utf8bom = New-Object System.Text.UTF8Encoding($true)
[System.IO.File]::WriteAllText($newIss, $iss, $utf8bom)

Write-Host 'Compiling Installer via Inno Setup (ISCC) if available...'
# Try to locate Inno Setup 6 (Unicode)
$isscPath = $null
$cands = @(
  'C:\\Program Files\\Inno Setup 6\\ISCC.exe',
  'C:\\Program Files (x86)\\Inno Setup 6\\ISCC.exe'
)
foreach($c in $cands){ if (Test-Path $c) { $isscPath = $c; break } }
if (-not $isscPath) {
  $p = (Get-Command iscc -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
  if ($p) {
    try { $verOut = & $p '/?' 2>&1 | Out-String; if ($verOut -match 'Inno Setup 6') { $isscPath = $p } } catch {}
  }
}

$builtArtifact = $null
try {
  if ($isscPath) {
  & $isscPath $newIss | Out-Host
  $builtArtifact = Join-Path $buildDir "OptForgeWorker_Setup_v${AppVer}.exe"
  } else {
    Write-Warning 'Inno Setup 6 (ISCC.exe) not found. Falling back to building a portable ZIP package.'
  }
} catch {
  Write-Warning "Inno Setup compilation failed: $_. Falling back to portable ZIP."
}

# If no EXE produced, create a portable ZIP containing the worker runtime
if (-not $builtArtifact -or -not (Test-Path $builtArtifact)) {
  $portableName = "OptForgeWorker_Portable_v${AppVer}.zip"
  $portablePath = Join-Path $buildDir $portableName
  if (Test-Path $portablePath) { Remove-Item $portablePath -Force }
  Write-Host "Creating portable package: $portableName"
  $include = @(
    'index.js','launcher.js','package.json','package-lock.json',
    'health_check.ps1','install_service.ps1','worker_service.bat','worker_run.bat','.env.example'
  ) | ForEach-Object { Join-Path $PSScriptRoot $_ }
  $folders = @('node_modules','ms-playwright','node') | ForEach-Object {
    $p = Join-Path $PSScriptRoot $_; if (Test-Path $p) { $p } }
  $tmpStage = Join-Path $buildDir '__portable_stage'
  if (Test-Path $tmpStage) { Remove-Item $tmpStage -Recurse -Force }
  New-Item -ItemType Directory -Path $tmpStage | Out-Null
  foreach($f in $include){ if (Test-Path $f) { Copy-Item $f -Destination $tmpStage -Force } }
  foreach($d in $folders){ if ($d) { Copy-Item $d -Destination (Join-Path $tmpStage (Split-Path -Leaf $d)) -Recurse -Force } }
  # Add a quick README
  @(
    "OptForge Worker - Portable",
    "How to run:",
    "1) Edit .env (BASE_URL, INTERNAL_SECRET, WORKER_ID, PULL_INTERVAL_SEC)",
    "2) Double-click worker_run.bat",
    "3) Optional: run install_service.ps1 as admin to install as a Windows Service"
  ) | Set-Content -Path (Join-Path $tmpStage 'README.txt') -Encoding UTF8
  Compress-Archive -Path (Join-Path $tmpStage '*') -DestinationPath $portablePath -Force
  Remove-Item $tmpStage -Recurse -Force
  $builtArtifact = $portablePath
}

Write-Host "Done. Output in: $buildDir"
Pop-Location

# Publish artifact (EXE or ZIP) to storage/releases and write metadata + latest.json
$releaseDir = Join-Path $PSScriptRoot '..\\storage\\releases'
if (!(Test-Path $releaseDir)) { New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null }
$publicRel = Join-Path $PSScriptRoot '..\\releases'
if (!(Test-Path $publicRel)) { New-Item -ItemType Directory -Force -Path $publicRel | Out-Null }

if (Test-Path $builtArtifact) {
  $leaf = Split-Path -Leaf $builtArtifact
  $releaseFile = Join-Path $releaseDir $leaf
  Copy-Item $builtArtifact $releaseFile -Force
  $publicFile = Join-Path $publicRel $leaf
  Copy-Item $builtArtifact $publicFile -Force
  
  # Code-sign EXE if applicable
  if ($leaf -like '*.exe') { Invoke-CodeSign -PathToFile $releaseFile }

  $sha256 = (Get-FileHash -Path $releaseFile -Algorithm SHA256).Hash.ToLower()
  # Try to extract Publisher from signature (optional, only for EXE)
  $publisher = ''
  if ($leaf -like '*.exe') {
    try {
      $signtool = (Get-Command signtool -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
      if ($signtool) {
        $verify = & $signtool verify /pa /all /v $releaseFile 2>&1
        if ($verify) {
          $m = ($verify | Select-String -Pattern 'Issued to:\s*(.+)$' -AllMatches)
          if ($m -and $m.Matches.Count -gt 0) { $publisher = $m.Matches[0].Groups[1].Value.Trim() }
        }
      }
    } catch {}
  }

  $kind = 'portable'; if ($leaf -like '*.exe') { $kind = 'installer' }
  $meta = [ordered]@{
    name = $leaf
    version = $AppVer
    size = (Get-Item $releaseFile).Length
    mtime = (Get-Item $releaseFile).LastWriteTimeUtc.ToString('o')
    sha256 = $sha256
    timestamp_url = $TimestampUrl
    signed = ([bool]$Sign -and ($leaf -like '*.exe'))
    publisher = $publisher
    kind = $kind
  }
  $metaPath = Join-Path $releaseDir 'installer_meta.json'
  ($meta | ConvertTo-Json -Depth 5) | Set-Content -Path $metaPath -Encoding UTF8
  ($meta | ConvertTo-Json -Depth 5) | Set-Content -Path (Join-Path $publicRel 'installer_meta.json') -Encoding UTF8

  $latest = [ordered]@{
    version = $AppVer
    size = $meta.size
    sha256 = $sha256
    last_modified = $meta.mtime
    url = '/releases/' + $leaf
    channel = 'stable'
    publisher = $publisher
    kind = $meta.kind
  }
  $latestPath = Join-Path $releaseDir 'latest.json'
  ($latest | ConvertTo-Json -Depth 5) | Set-Content -Path $latestPath -Encoding UTF8
  ($latest | ConvertTo-Json -Depth 5) | Set-Content -Path (Join-Path $publicRel 'latest.json') -Encoding UTF8

  # Keep only last 3 artifacts of each kind
  $allExe = @(Get-ChildItem -Path $releaseDir -Filter 'OptForgeWorker_Setup_v*.exe' -File -ErrorAction SilentlyContinue) +
            @(Get-ChildItem -Path $releaseDir -Filter 'OPTNexusWorker_Setup_v*.exe' -File -ErrorAction SilentlyContinue) | Sort-Object LastWriteTime -Descending
  if($allExe.Count -gt 3){ $allExe | Select-Object -Skip 3 | ForEach-Object { Remove-Item $_.FullName -Force } }
  $allZip = @(Get-ChildItem -Path $releaseDir -Filter 'OptForgeWorker_Portable_v*.zip' -File -ErrorAction SilentlyContinue) +
            @(Get-ChildItem -Path $releaseDir -Filter 'OPTNexusWorker_Portable_v*.zip' -File -ErrorAction SilentlyContinue) | Sort-Object LastWriteTime -Descending
  if($allZip.Count -gt 3){ $allZip | Select-Object -Skip 3 | ForEach-Object { Remove-Item $_.FullName -Force } }
}
