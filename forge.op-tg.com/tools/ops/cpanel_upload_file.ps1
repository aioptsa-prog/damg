param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$LocalFile,
  [string]$RemoteDir
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11 -bor [Net.SecurityProtocolType]::Tls
if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($ApiToken) -or -not (Test-Path $LocalFile) -or [string]::IsNullOrWhiteSpace($RemoteDir)){
  throw 'CpanelHost, CpanelUser, ApiToken, LocalFile (existing), and RemoteDir are required.'
}
$authHeader = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
try {
  Add-Type -AssemblyName System.Net.Http -ErrorAction Stop | Out-Null
  $handler = New-Object System.Net.Http.HttpClientHandler
  $client = New-Object System.Net.Http.HttpClient($handler)
  foreach($k in $authHeader.Keys){ $client.DefaultRequestHeaders.Add($k, $authHeader[$k]) }
  $content = New-Object System.Net.Http.MultipartFormDataContent
  $content.Add((New-Object System.Net.Http.StringContent($RemoteDir)), 'dir')
  $content.Add((New-Object System.Net.Http.StringContent('1')), 'overwrite')
  $fs = [System.IO.File]::OpenRead($LocalFile)
  $fileContent = New-Object System.Net.Http.StreamContent($fs)
  $mime = 'application/octet-stream'
  if($LocalFile -match '\\.zip$'){ $mime = 'application/zip' }
  elseif($LocalFile -match '\\.json$'){ $mime = 'application/json' }
  $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse($mime)
  $leaf = [System.IO.Path]::GetFileName($LocalFile)
  $content.Add($fileContent,'file-1',$leaf)
  $uplB = New-Object System.UriBuilder('https', $CpanelHost, 2083, 'execute/Fileman/upload_files')
  $resp = $client.PostAsync($uplB.Uri.AbsoluteUri, $content).Result
  $status = [int]$resp.StatusCode
  $body = $resp.Content.ReadAsStringAsync().Result
  $fs.Dispose(); $client.Dispose()
  if([string]::IsNullOrWhiteSpace($body)){
    if($status -eq 200 -or $status -eq 204){ Write-Host 'Uploaded (empty response)'; return }
    throw "upload_files empty body (status=$status)"
  }
  try { $json = $body | ConvertFrom-Json } catch { throw "upload_files response not JSON: $body" }
  if($json.status -ne 1){ throw "upload_files failed: $($json.errors -join '; ')" }
  Write-Host 'Upload OK'
} catch {
  Write-Error $_
  exit 1
}