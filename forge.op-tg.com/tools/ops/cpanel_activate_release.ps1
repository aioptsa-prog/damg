param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$DocrootBase,
  [string]$Timestamp,
  [string]$ApiToken,
  [pscredential]$Credential,
  [switch]$Maintenance
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 -bor [Net.SecurityProtocolType]::Tls

if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($DocrootBase) -or [string]::IsNullOrWhiteSpace($Timestamp)){
  throw 'CpanelHost, CpanelUser, DocrootBase, and Timestamp are required.'
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

$activeRel = "releases/$Timestamp"
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

$tmpDir = Join-Path ([System.IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
New-Item -ItemType Directory -Force -Path $tmpDir | Out-Null
$tmpHt = Join-Path $tmpDir '.htaccess'
Set-Content -LiteralPath $tmpHt -Value $bootstrap -Encoding ASCII
Invoke-UploadFile -localPath $tmpHt -remoteDir $DocrootBase
Remove-Item -Recurse -Force $tmpDir -ErrorAction SilentlyContinue

if($Maintenance){
  # Best-effort ensure maintenance.html exists in the active release
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
    Invoke-UploadFile -localPath $tmpMaintHtml -remoteDir ("$DocrootBase/" + $activeRel)
    Remove-Item -Force $tmpMaintHtml -ErrorAction SilentlyContinue
  } catch {}
}

Write-Host ("Activated release: {0} (maintenance={1})" -f $Timestamp, $Maintenance.IsPresent)