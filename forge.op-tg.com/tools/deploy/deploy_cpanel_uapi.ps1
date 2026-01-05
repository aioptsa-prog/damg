param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$CpanelHost = 'nexus.op-tg.com',
  [string]$CpanelUser = 'optgccom',
  [pscredential]$Credential,
  [string]$ApiToken,
  [string]$DocrootBase = '/home/optgccom/nexus.op-tg.com',
  [switch]$DryRun,
  [switch]$Maintenance,
  [switch]$NoTerminal,
  [switch]$PlanOnly,
  [switch]$LeaveMaintenance
)
# Fallback deploy using cPanel UAPI: Upload a ZIP and materialize under releases/<timestamp>, then switch traffic via .htaccess.
# Works even when Fileman::rename/extract_archive are unavailable by per-file upload and rewrite-based activation.

$ErrorActionPreference = 'Stop'
[string]$tokFile = Join-Path $LocalPath 'tools\secrets\cpanel_token.txt'
if([string]::IsNullOrWhiteSpace($ApiToken) -and (Test-Path $tokFile)){
  try { $ApiToken = (Get-Content -LiteralPath $tokFile -Raw).Trim() } catch {}
}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 -bor [Net.SecurityProtocolType]::Tls
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$zipPath = Join-Path $LocalPath "storage\releases\site-$timestamp.zip"
try {
  $valDir = Join-Path $LocalPath 'storage\logs\validation'
  if(!(Test-Path $valDir)){ New-Item -ItemType Directory -Force -Path $valDir | Out-Null }
  $tsFile = Join-Path $valDir 'last_deploy_timestamp.txt'
  Set-Content -LiteralPath $tsFile -Value $timestamp -Encoding UTF8
} catch {}
Write-Host ("DEPLOY_TIMESTAMP={0}" -f $timestamp)

if($PlanOnly){
  Write-Host "cPanel UAPI Deploy Plan" -ForegroundColor Cyan
  Write-Host ("- Upload: {0} -> {1}/releases" -f $zipPath, $DocrootBase)
  Write-Host ("- Extract/Materialize: -> {0}/releases/{1}" -f $DocrootBase, $timestamp)
  if($Maintenance){
    Write-Host ("- Maintenance: include maintenance block in .htaccess and ensure {0}/releases/{1}/maintenance.html exists" -f $DocrootBase, $timestamp)
  }
  Write-Host ("- Activate: write .htaccess rewrite to /releases/{0} (no rename required)" -f $timestamp) -ForegroundColor Yellow
  return
}

# 1) Build a zip from make_release.ps1 output or create one ad-hoc
if(!(Test-Path $zipPath)){
  & "$PSScriptRoot\..\make_release.ps1" -OutZip (Split-Path -Leaf $zipPath) | Out-Null
}
$staging = Join-Path $LocalPath '__release'

# 2) Upload via cPanel UAPI (HTTPS). Prefer API Token; fallback to basic auth if needed.
$authHeader = @{}
if($ApiToken){
  $authHeader['Authorization'] = "cpanel ${CpanelUser}:${ApiToken}"
} elseif($Credential){
  $plain = (New-Object System.Net.NetworkCredential('', $Credential.Password)).Password
  $basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$($Credential.UserName):$plain"))
  $authHeader['Authorization'] = "Basic $basic"
}

# Helper to upload a single local file to a remote directory using Fileman/upload_files
function Invoke-UploadFile([string]$localPath,[string]$remoteDir){
  Add-Type -AssemblyName System.Net.Http -ErrorAction SilentlyContinue | Out-Null
  $uplB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/upload_files')
  try {
    $handler = New-Object System.Net.Http.HttpClientHandler
    $client = New-Object System.Net.Http.HttpClient($handler)
    foreach($k in $authHeader.Keys){ $client.DefaultRequestHeaders.Add($k, $authHeader[$k]) }
    $content = New-Object System.Net.Http.MultipartFormDataContent
    $dirPart = New-Object System.Net.Http.StringContent($remoteDir)
    $content.Add($dirPart,'dir')
    $ovPart = New-Object System.Net.Http.StringContent('1')
    $content.Add($ovPart,'overwrite')
    $fs = [System.IO.File]::OpenRead($localPath)
    $fileContent = New-Object System.Net.Http.StreamContent($fs)
    $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/octet-stream')
    $leaf = [System.IO.Path]::GetFileName($localPath)
    $content.Add($fileContent,'file-1',$leaf)
    $resp = $client.PostAsync($uplB.Uri.AbsoluteUri, $content).Result
    $status = [int]$resp.StatusCode
    $body = $resp.Content.ReadAsStringAsync().Result
    $fs.Dispose(); $client.Dispose()
    if([string]::IsNullOrWhiteSpace($body)){
      if($status -eq 200 -or $status -eq 204){ return }
      throw "upload_files empty body (status=$status)"
    }
    try { $json = $body | ConvertFrom-Json } catch { throw "upload_files response not JSON: $body" }
    if($json.status -ne 1){ throw "upload_files failed: $($json.errors -join '; ')" }
  } catch {
    $boundary = "---------------------------$(Get-Random)$(Get-Random)$(Get-Random)"
    $enc = [Text.Encoding]::ASCII
    $nl = "`r`n"
    $leaf = [System.IO.Path]::GetFileName($localPath)
    $head = '--' + $boundary + $nl +
            'Content-Disposition: form-data; name="dir"' + $nl + $nl +
            $remoteDir + $nl +
            '--' + $boundary + $nl +
            'Content-Disposition: form-data; name="overwrite"' + $nl + $nl +
            '1' + $nl +
            '--' + $boundary + $nl +
            'Content-Disposition: form-data; name="file-1"; filename="' + $leaf + '"' + $nl +
            'Content-Type: application/octet-stream' + $nl + $nl
    $tail = $nl + '--' + $boundary + '--' + $nl
    $headBytes = $enc.GetBytes($head)
    $tailBytes = $enc.GetBytes($tail)
    $fileBytes = [System.IO.File]::ReadAllBytes($localPath)
    $ms = New-Object System.IO.MemoryStream
    $ms.Write($headBytes,0,$headBytes.Length)
    $ms.Write($fileBytes,0,$fileBytes.Length)
    $ms.Write($tailBytes,0,$tailBytes.Length)
    $req = [System.Net.HttpWebRequest]::Create($uplB.Uri)
    $req.Method = 'POST'
    foreach($k in $authHeader.Keys){ $req.Headers.Add($k, $authHeader[$k]) }
    $req.ContentType = "multipart/form-data; boundary=$boundary"
    $req.ContentLength = $ms.Length
    $out = $req.GetRequestStream()
    $ms.WriteTo($out)
    $out.Dispose(); $ms.Dispose()
    try { $resp2 = $req.GetResponse() } catch { throw "upload_files failed: $($_.Exception.Message)" }
    $sr = New-Object System.IO.StreamReader($resp2.GetResponseStream())
    $body2 = $sr.ReadToEnd(); $resp2.Close()
    if([string]::IsNullOrWhiteSpace($body2)){ return }
    try { $json2 = $body2 | ConvertFrom-Json } catch { return }
    if($json2.status -ne 1){ throw "upload_files failed: $($json2.errors -join '; ')" }
  }
}
$uploadDir = "$DocrootBase/releases"
$ub = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/upload_files')
$uriUpload = $ub.Uri.AbsoluteUri
if($DryRun){ Write-Host "[DryRun] Would POST $zipPath to $uriUpload"; return }

  # Multipart upload using HttpClient when available; otherwise fallback to HttpWebRequest
  try {
  Add-Type -AssemblyName System.Net.Http -ErrorAction Stop | Out-Null
  $handler = New-Object System.Net.Http.HttpClientHandler
  $client = New-Object System.Net.Http.HttpClient($handler)
  foreach($k in $authHeader.Keys){ $client.DefaultRequestHeaders.Add($k, $authHeader[$k]) }
  $content = New-Object System.Net.Http.MultipartFormDataContent
  # dir field required by upload_files
  $dirPart = New-Object System.Net.Http.StringContent($uploadDir)
  $content.Add($dirPart, 'dir')
  $fileStream = [System.IO.File]::OpenRead($zipPath)
  $fileContent = New-Object System.Net.Http.StreamContent($fileStream)
  $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/zip')
  $fileName = [System.IO.Path]::GetFileName($zipPath)
  $content.Add($fileContent, 'file-1', $fileName)
  $resp = $client.PostAsync($uriUpload, $content).Result
  $body = $resp.Content.ReadAsStringAsync().Result
  Set-Content -LiteralPath (Join-Path $valDir ("cpanel_upload_"+$timestamp+".json")) -Value $body -Encoding UTF8
  if(-not $resp.IsSuccessStatusCode){ throw "Upload failed: $($resp.StatusCode) $($resp.ReasonPhrase) $body" }
  try { $json = $body | ConvertFrom-Json } catch { throw "Upload response not JSON: $body" }
  if($json.status -ne 1){ throw "Upload failed: $($json.errors -join '; ')" }
  $fileStream.Dispose(); $client.Dispose()
  } catch {
  Write-Host "HttpClient unavailable, using Invoke-WebRequest multipart fallback..." -ForegroundColor Yellow
  $form = @{}
  $fileName = [System.IO.Path]::GetFileName($zipPath)
  # Invoke-WebRequest -Form supports file uploads via file paths when prefixed with '@'
  $boundary = "---------------------------$(Get-Random)$(Get-Random)$(Get-Random)"
  $bytes = [System.IO.File]::ReadAllBytes($zipPath)
  $enc = [Text.Encoding]::ASCII
  $nl = "`r`n"
  # dir field part
  $bodyStart = '--' + $boundary + $nl +
               'Content-Disposition: form-data; name="dir"' + $nl + $nl +
               $uploadDir + $nl +
               '--' + $boundary + $nl +
               'Content-Disposition: form-data; name="file-1"; filename="' + $fileName + '"' + $nl +
               'Content-Type: application/zip' + $nl + $nl
  $bodyEnd = "$nl--$boundary--$nl"
  $bodyStartBytes = $enc.GetBytes($bodyStart)
  $bodyEndBytes = $enc.GetBytes($bodyEnd)
  $ms = New-Object System.IO.MemoryStream
  $ms.Write($bodyStartBytes, 0, $bodyStartBytes.Length)
  $ms.Write($bytes, 0, $bytes.Length)
  $ms.Write($bodyEndBytes, 0, $bodyEndBytes.Length)
  $uri = [Uri]$uriUpload
  $req = [System.Net.HttpWebRequest]::Create($uri)
  $req.Method = 'POST'
  foreach($k in $authHeader.Keys){ $req.Headers.Add($k, $authHeader[$k]) }
  $req.ContentType = "multipart/form-data; boundary=$boundary"
  $req.ContentLength = $ms.Length
  $out = $req.GetRequestStream()
  $ms.WriteTo($out)
  $out.Dispose(); $ms.Dispose()
  try { $resp = $req.GetResponse() } catch { throw "Upload failed: $($_.Exception.Message)" }
  $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
  $body = $sr.ReadToEnd(); $resp.Close()
  Set-Content -LiteralPath (Join-Path $valDir ("cpanel_upload_"+$timestamp+".json")) -Value $body -Encoding UTF8
  try { $json = $body | ConvertFrom-Json } catch { throw "Upload response not JSON: $body" }
  if($json.status -ne 1){ throw "Upload failed: $($json.errors -join '; ')" }
}

# 3) Extract archive under releases/<timestamp> and swap
$targetDir = "$DocrootBase/releases/$timestamp"
$zipRemote = "$DocrootBase/releases/$(Split-Path -Leaf $zipPath)"
# Ensure destination directory exists
$mkdirB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/mkdir')
$mkdirB.Query = 'path=' + [uri]::EscapeDataString($targetDir)
$mk = Invoke-RestMethod -Uri $mkdirB.Uri -Headers $authHeader -Method Post
if($mk.status -ne 1 -and -not ($mk.errors -join '; ') -match 'exists'){ Write-Host ("mkdir note: {0}" -f ($mk.errors -join '; ')) -ForegroundColor DarkYellow }
$extractB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/extract_archive')
$extractB.Query = 'zip_file=' + [uri]::EscapeDataString($zipRemote) + '&dest=' + [uri]::EscapeDataString($targetDir)
${extractResp} = $null
$fallbackUpload = $false
try {
  ${extractResp} = Invoke-RestMethod -Uri $extractB.Uri -Headers $authHeader -Method Post
  Set-Content -LiteralPath (Join-Path $valDir ("cpanel_extract_"+$timestamp+".json")) -Value ($extractResp | ConvertTo-Json -Depth 8) -Encoding UTF8
  if($extractResp.status -ne 1){
    Write-Host ("extract_archive failed: {0}" -f ($extractResp.errors -join '; ')) -ForegroundColor DarkYellow
    $fallbackUpload = $true
  }
} catch {
  Write-Host ("extract_archive unavailable, falling back to per-file upload: {0}" -f $_.Exception.Message) -ForegroundColor Yellow
  $fallbackUpload = $true
}

if($fallbackUpload){
  # Try Terminal unzip fallback first
  try {
    $mkterm = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Terminal/execute_command')
    $mkcmd = 'mkdir -p ' + $targetDir
    $mkterm.Query = 'command=' + [uri]::EscapeDataString($mkcmd)
    $mkres = Invoke-RestMethod -Uri $mkterm.Uri -Headers $authHeader -Method Post
  } catch {}
  $termUnzipOk = $false
  try {
    $termB2 = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Terminal/execute_command')
    $zcmd = ('/usr/bin/unzip -o {0} -d {1} || /bin/unzip -o {0} -d {1}' -f ($zipRemote -replace ' ','\ '), ($targetDir -replace ' ','\ '))
    $termB2.Query = 'command=' + [uri]::EscapeDataString($zcmd)
    $tres = Invoke-RestMethod -Uri $termB2.Uri -Headers $authHeader -Method Post
    if($tres.status -eq 1){ $termUnzipOk = $true }
  } catch { Write-Host ("Terminal unzip fallback failed: {0}" -f $_.Exception.Message) -ForegroundColor DarkYellow }
  if($termUnzipOk){ Write-Host 'Terminal unzip succeeded.' -ForegroundColor Green } else { Write-Host 'Terminal unzip not available; using per-file upload.' -ForegroundColor Yellow }
}

if($fallbackUpload -and -not $termUnzipOk){
  Write-Host "Uploading tree (__release) to target via Fileman..." -ForegroundColor Yellow
  $logTree = Join-Path $valDir ("cpanel_upload_tree_"+$timestamp+".log")
  $uploaded = 0; $dirsMade = New-Object System.Collections.Generic.HashSet[string]
  function Ensure-RemoteDir([string]$path){
    $path = $path.TrimEnd('/'); if([string]::IsNullOrWhiteSpace($path)){ return }
    if($dirsMade.Contains($path)){ return }
    $parts = $path -split '/'
    $acc = ''
    foreach($p in $parts){
      if([string]::IsNullOrWhiteSpace($p)){ continue }
      $acc = $acc + '/' + $p
      if($dirsMade.Contains($acc)){ continue }
      $mkdirB2 = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/mkdir')
      $mkdirB2.Query = 'path=' + [uri]::EscapeDataString($acc)
      try { $mk2 = Invoke-RestMethod -Uri $mkdirB2.Uri -Headers $authHeader -Method Post } catch { $mk2 = $null }
      $dirsMade.Add($acc) | Out-Null
    }
  }
  function Upload-OneFile([string]$local,[string]$remoteDir){
    Ensure-RemoteDir $remoteDir
    # Build multipart with dir + file-1
    try {
      Add-Type -AssemblyName System.Net.Http -ErrorAction Stop | Out-Null
      $handler = New-Object System.Net.Http.HttpClientHandler
      $client = New-Object System.Net.Http.HttpClient($handler)
      foreach($k in $authHeader.Keys){ $client.DefaultRequestHeaders.Add($k, $authHeader[$k]) }
  $content = New-Object System.Net.Http.MultipartFormDataContent
      $dirPart = New-Object System.Net.Http.StringContent($remoteDir)
      $content.Add($dirPart,'dir')
  $ovPart = New-Object System.Net.Http.StringContent('1')
  $content.Add($ovPart,'overwrite')
      $fs = [System.IO.File]::OpenRead($local)
      $fileContent = New-Object System.Net.Http.StreamContent($fs)
      $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/octet-stream')
      $leaf = [System.IO.Path]::GetFileName($local)
      $content.Add($fileContent,'file-1',$leaf)
      $resp = $client.PostAsync($ub.Uri.AbsoluteUri, $content).Result
      $status = [int]$resp.StatusCode
      $reason = $resp.ReasonPhrase
      $body = $resp.Content.ReadAsStringAsync().Result
      $fs.Dispose(); $client.Dispose()
      if([string]::IsNullOrWhiteSpace($body)){
        if($status -eq 200 -or $status -eq 204){ return }
        throw "upload_files empty body (status=$status $reason)"
      }
      try { $json = $body | ConvertFrom-Json } catch { throw "upload_files response not JSON (status=$status $reason): $body" }
      if($json.status -ne 1){ throw "upload_files failed: $($json.errors -join '; ')" }
    } catch {
      # Fallback to HttpWebRequest multipart
      $boundary2 = "---------------------------$(Get-Random)$(Get-Random)$(Get-Random)"
      $enc2 = [Text.Encoding]::ASCII
      $nl2 = "`r`n"
      $leaf2 = [System.IO.Path]::GetFileName($local)
      $head = '--' + $boundary2 + $nl2 +
              'Content-Disposition: form-data; name="dir"' + $nl2 + $nl2 +
              $remoteDir + $nl2 +
              '--' + $boundary2 + $nl2 +
              'Content-Disposition: form-data; name="file-1"; filename="' + $leaf2 + '"' + $nl2 +
              'Content-Type: application/octet-stream' + $nl2 + $nl2
      $tail = $nl2 + '--' + $boundary2 + '--' + $nl2
      $headBytes = $enc2.GetBytes($head)
      $tailBytes = $enc2.GetBytes($tail)
      $fileBytes = [System.IO.File]::ReadAllBytes($local)
      $ms2 = New-Object System.IO.MemoryStream
      $ms2.Write($headBytes,0,$headBytes.Length)
      $ms2.Write($fileBytes,0,$fileBytes.Length)
      $ms2.Write($tailBytes,0,$tailBytes.Length)
      $req2 = [System.Net.HttpWebRequest]::Create($ub.Uri)
      $req2.Method = 'POST'
      foreach($k in $authHeader.Keys){ $req2.Headers.Add($k, $authHeader[$k]) }
      $req2.ContentType = "multipart/form-data; boundary=$boundary2"
      $req2.ContentLength = $ms2.Length
      $out2 = $req2.GetRequestStream()
      $ms2.WriteTo($out2)
      $out2.Dispose(); $ms2.Dispose()
      try { $resp2 = $req2.GetResponse() } catch { throw "upload_files failed: $($_.Exception.Message)" }
      $sr2 = New-Object System.IO.StreamReader($resp2.GetResponseStream())
      $body2 = $sr2.ReadToEnd(); $resp2.Close()
      if([string]::IsNullOrWhiteSpace($body2)){ return }
      try { $json2 = $body2 | ConvertFrom-Json } catch { return }
      if($json2.status -ne 1){ throw "upload_files failed: $($json2.errors -join '; ')" }
    }
  }
  $files = Get-ChildItem -Path $staging -Recurse -File
  foreach($f in $files){
    $rel = $f.FullName.Substring($staging.Length)
    $rel = $rel -replace '^[\\/]+',''
    $relDir = [System.IO.Path]::GetDirectoryName($rel)
    $remoteDir = if([string]::IsNullOrEmpty($relDir)){ $targetDir } else { ($targetDir + '/' + ($relDir -replace '\\','/')) }
    Upload-OneFile $f.FullName $remoteDir
    $uploaded++
    if(($uploaded % 50) -eq 0){ Add-Content -LiteralPath $logTree -Value ("uploaded {0} files..." -f $uploaded) -Encoding UTF8 }
  }
  Add-Content -LiteralPath $logTree -Value ("DONE uploaded {0} files" -f $uploaded) -Encoding UTF8
}

if($Maintenance){
  $tmpDir = Join-Path ([System.IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
  New-Item -ItemType Directory -Force -Path $tmpDir | Out-Null
  $tmpMaint = Join-Path $tmpDir 'maintenance.flag'
  try { Set-Content -LiteralPath $tmpMaint -Value '' -Encoding ASCII } catch {}
  Invoke-UploadFile -localPath $tmpMaint -remoteDir $DocrootBase
  Remove-Item -Recurse -Force $tmpDir -ErrorAction SilentlyContinue
  # Also ensure a maintenance.html exists within the active release
  try {
    $maintHtml = @"
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>الصيانة</title>
  <style>
    body{font-family:Tahoma,Arial,sans-serif;background:#0b2239;color:#f1f5f9;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
    .card{background:#122b47;padding:32px 28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35);max-width:520px}
    h1{margin:0 0 10px;font-size:26px}
    p{margin:0;color:#cbd5e1;line-height:1.8}
  </style>
</head>
<body>
  <div class="card">
    <h1>نقوم حالياً بأعمال صيانة</h1>
    <p>نعود إليكم قريباً. شكراً لتفهمكم.</p>
  </div>
</body>
</html>
"@
    $tmpMaintHtml = [System.IO.Path]::GetTempFileName()
    Set-Content -LiteralPath $tmpMaintHtml -Value $maintHtml -Encoding UTF8
    Invoke-UploadFile -localPath $tmpMaintHtml -remoteDir $targetDir
    Remove-Item -Force $tmpMaintHtml -ErrorAction SilentlyContinue
  } catch { Write-Host ("warn: failed to stage maintenance.html -> {0}" -f $_.Exception.Message) -ForegroundColor DarkYellow }
}

# Switch using .htaccess rewrite to the new release (no rename required)
$activeRel = "releases/$timestamp"
$bootstrap = @"
AddDefaultCharset UTF-8
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
# Maintenance mode: serve maintenance.html when enabled
{MAINT_BLOCK}

# Route everything to /$activeRel/ (allow direct access to /releases/* and the active release itself)
RewriteCond %{REQUEST_URI} !^/releases/
RewriteCond %{REQUEST_URI} !^/$activeRel/
RewriteRule ^(.*)$ /$activeRel/$1 [L]
</IfModule>
"@
$maintBlock = if($Maintenance){ "RewriteCond %{REQUEST_URI} !^/maintenance\.html$`nRewriteRule ^ /$activeRel/maintenance.html [L]" } else { '' }
$bootstrap = $bootstrap.Replace('{MAINT_BLOCK}',$maintBlock)
$tmpDir2 = Join-Path ([System.IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
New-Item -ItemType Directory -Force -Path $tmpDir2 | Out-Null
$tmpHt = Join-Path $tmpDir2 '.htaccess'
Set-Content -LiteralPath $tmpHt -Value $bootstrap -Encoding ASCII
Invoke-UploadFile -localPath $tmpHt -remoteDir $DocrootBase
Remove-Item -Recurse -Force $tmpDir2 -ErrorAction SilentlyContinue

# If we wanted to leave maintenance off by ignoring the flag, we already control it via .htaccess content.

Write-Host "cPanel deploy complete to $CpanelHost" -ForegroundColor Green
try { if($tsFile){ Set-Content -LiteralPath $tsFile -Value $timestamp -Encoding UTF8 } } catch {}
