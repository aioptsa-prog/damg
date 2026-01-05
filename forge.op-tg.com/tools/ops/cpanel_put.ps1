param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$Dir,
  [string]$FileName = 'ping.txt',
  [string]$Content = 'ping'
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($ApiToken) -or [string]::IsNullOrWhiteSpace($Dir)){
  throw 'CpanelHost, CpanelUser, ApiToken, and Dir are required.'
}
$auth = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
$tmp = [System.IO.Path]::Combine([System.IO.Path]::GetTempPath(), [System.IO.Path]::GetRandomFileName())
Set-Content -LiteralPath $tmp -Value $Content -Encoding ASCII
try {
  Add-Type -AssemblyName System.Net.Http -ErrorAction SilentlyContinue | Out-Null
  $uplB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/upload_files')
  $handler = [System.Net.Http.HttpClientHandler]::new()
  $client = [System.Net.Http.HttpClient]::new($handler)
  foreach($k in $auth.Keys){ $client.DefaultRequestHeaders.Add($k, $auth[$k]) }
  $mp = [System.Net.Http.MultipartFormDataContent]::new()
  $mp.Add([System.Net.Http.StringContent]::new($Dir), 'dir')
  $mp.Add([System.Net.Http.StringContent]::new('1'), 'overwrite')
  $fs = [System.IO.File]::OpenRead($tmp)
  $sc = [System.Net.Http.StreamContent]::new($fs)
  $sc.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('text/plain')
  $mp.Add($sc, 'file-1', $FileName)
  $resp = $client.PostAsync($uplB.Uri.AbsoluteUri, $mp).Result
  $body = $resp.Content.ReadAsStringAsync().Result
  $fs.Dispose(); $client.Dispose()
  if([string]::IsNullOrWhiteSpace($body)){ Write-Host 'Uploaded (empty response)'; return }
  $json = $body | ConvertFrom-Json
  $json | ConvertTo-Json -Depth 6
} finally { Remove-Item -Force $tmp -ErrorAction SilentlyContinue }