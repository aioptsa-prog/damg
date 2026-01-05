param(
  [string]$BaseUrl = 'http://127.0.0.1:8091',
  [string]$OutFile
)
$sb = New-Object System.Text.StringBuilder
function OutLine($msg){ Write-Host $msg; $null = $sb.AppendLine($msg) }
OutLine "Probing $BaseUrl"
$latest = "$BaseUrl/api/latest.php"
$download = "$BaseUrl/api/download_worker.php"

OutLine "HEAD latest.php"
try { $h = Invoke-WebRequest -Uri $latest -Method Head -Headers @{ 'Cache-Control'='no-cache' }; (($h.Headers.GetEnumerator() | Format-Table -AutoSize | Out-String).Trim()) | ForEach-Object { OutLine $_ } } catch { OutLine ("$_.Exception") }

OutLine "GET latest.php (ETag)"
$r = Invoke-WebRequest -Uri $latest -Headers @{ 'Cache-Control'='no-cache' }
$etag = $r.Headers['ETag']
OutLine "ETag: $etag"

OutLine "GET latest.php with If-None-Match"
try{ $r2 = Invoke-WebRequest -Uri $latest -Headers @{ 'If-None-Match'=$etag }; OutLine ("Status: $($r2.StatusCode)") }catch{ OutLine ("Status: $($_.Exception.Response.StatusCode.value__)") }

OutLine "HEAD download_worker.php"
try { $dhead = Invoke-WebRequest -Uri $download -Method Head; OutLine "$($dhead.StatusCode)"; (($dhead.Headers.GetEnumerator() | Format-Table -AutoSize | Out-String).Trim()) | ForEach-Object { OutLine $_ } } catch { OutLine ("$_.Exception") }

OutLine "Range 0-499 download_worker.php"
try{
  Add-Type -AssemblyName System.Net.Http | Out-Null
  $client = [System.Net.Http.HttpClient]::new()
  $req = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Get, $download)
  $req.Headers.Range = New-Object System.Net.Http.Headers.RangeHeaderValue(0,499)
  $resp = $client.SendAsync($req).Result
  $len = if($resp.Content.Headers.ContentLength){ $resp.Content.Headers.ContentLength } else { '' }
  $cr = if($resp.Content.Headers.ContentRange){ $resp.Content.Headers.ContentRange.ToString() } else { '' }
  OutLine ("Status: {0} Length={1} Content-Range={2}" -f [int]$resp.StatusCode, $len, $cr)
} catch { OutLine ("$_.Exception") }

if($OutFile){
  $dir = Split-Path -Parent $OutFile
  if($dir -and !(Test-Path $dir)){ New-Item -ItemType Directory -Path $dir -Force | Out-Null }
  [IO.File]::WriteAllText($OutFile, $sb.ToString(), [Text.Encoding]::UTF8)
  Write-Host "Saved probe transcript to $OutFile"
}