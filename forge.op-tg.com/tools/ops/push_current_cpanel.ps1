param(
  [string]$LocalPath = 'D:\LeadsMembershipPRO',
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$DocrootBase,
  [string]$ApiToken,
  [pscredential]$Credential
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 -bor [Net.SecurityProtocolType]::Tls

if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($DocrootBase)){
  throw 'CpanelHost, CpanelUser, and DocrootBase are required.'
}

$authHeader = @{}
if($ApiToken){
  $authHeader['Authorization'] = "cpanel ${CpanelUser}:${ApiToken}"
} elseif($Credential){
  $plain = (New-Object System.Net.NetworkCredential('', $Credential.Password)).Password
  $basic = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$($Credential.UserName):$plain"))
  $authHeader['Authorization'] = "Basic $basic"
} else {
  throw 'ApiToken or Credential required.'
}

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
    $req = [System.Net.HttpWebRequest]::Create((New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/upload_files')).Uri)
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

function Use-RemoteDir([string]$path){
  $path = $path.TrimEnd('/')
  if([string]::IsNullOrWhiteSpace($path)){ return }
  $parts = $path -split '/'
  $acc = ''
  foreach($p in $parts){
    if([string]::IsNullOrWhiteSpace($p)){ continue }
    $acc = $acc + '/' + $p
    $mkdirB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/mkdir')
    $mkdirB.Query = 'path=' + [uri]::EscapeDataString($acc)
    try { Invoke-RestMethod -Uri $mkdirB.Uri -Headers $authHeader -Method Post | Out-Null } catch { }
  }
}

# Build staging tree (__release)
$staging = Join-Path $LocalPath '__release'
if(!(Test-Path $staging)){
  & (Join-Path $LocalPath 'tools\make_release.ps1') | Out-Null
}

# Ensure /current exists and mirror files there
$currentBase = "$DocrootBase/current"
Use-RemoteDir $currentBase
$files = Get-ChildItem -Path $staging -Recurse -File
$count = 0
foreach($f in $files){
  $rel = $f.FullName.Substring($staging.Length) -replace '^[\\/]+',''
  $relDir = [System.IO.Path]::GetDirectoryName($rel)
  $remoteDir = if([string]::IsNullOrEmpty($relDir)){ $currentBase } else { ($currentBase + '/' + ($relDir -replace '\\','/')) }
  Use-RemoteDir $remoteDir
  Invoke-UploadFile -localPath $f.FullName -remoteDir $remoteDir
  $count++
}
Write-Host ("Pushed {0} files to {1}" -f $count, $currentBase) -ForegroundColor Green

# Write a .htaccess that routes to /current
$bootstrap = @"
AddDefaultCharset UTF-8
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} !^/current/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /current/$1 [L]
</IfModule>
"@
$tmpDir = Join-Path ([System.IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
New-Item -ItemType Directory -Force -Path $tmpDir | Out-Null
$tmpHt = Join-Path $tmpDir '.htaccess'
Set-Content -LiteralPath $tmpHt -Value $bootstrap -Encoding ASCII
Invoke-UploadFile -localPath $tmpHt -remoteDir $DocrootBase
Remove-Item -Recurse -Force $tmpDir -ErrorAction SilentlyContinue

Write-Host "Activation via /current is set (.htaccess)." -ForegroundColor Cyan