Param(
  [string]$BaseUrl = "http://127.0.0.1:8080",
  [string]$WorkerId = "dev-local",
  [string]$Secret = "$(Get-Content -Path (Join-Path $PSScriptRoot '..\..\config\.internal_secret') -ErrorAction SilentlyContinue)"
)

function BodySha([string]$raw){
  $bytes = [System.Text.Encoding]::UTF8.GetBytes($raw)
  $sha = [System.Security.Cryptography.SHA256]::Create()
  ($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join ''
}
function HmacSign([string]$method,[string]$path,[string]$sha,[int]$ts,[string]$secret){
  $msg = ($method.ToUpper()+"|"+$path+"|"+$sha+"|"+$ts)
  $hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($secret))
  $hash = $hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($msg))
  ($hash | ForEach-Object { $_.ToString('x2') }) -join ''
}

if([string]::IsNullOrEmpty($Secret)){
  Write-Host "No secret provided; set -Secret or create config/.internal_secret" -ForegroundColor Yellow
  $Secret = "dev-secret"
}

$headers = @{ 'Content-Type'='application/json'; 'X-Worker-Id'=$WorkerId }

# 1) Heartbeat
$ts = [int][double]::Parse((Get-Date -UFormat %s))
$p = '/api/heartbeat.php'
$body = '{"hello":"world"}'
$sha = BodySha $body
$sign = HmacSign 'POST' $p $sha $ts $Secret
$hbHeaders = $headers.Clone(); $hbHeaders['X-Auth-TS'] = "$ts"; $hbHeaders['X-Auth-Sign'] = $sign
Write-Host "Heartbeat..." -ForegroundColor Cyan
try{ $r = Invoke-RestMethod -Method Post -Uri ($BaseUrl+$p) -Headers $hbHeaders -Body $body } catch { Write-Host $_.Exception.Message -ForegroundColor Red; exit 1 }
Write-Host ($r | ConvertTo-Json -Depth 5)

Start-Sleep -Milliseconds 400

# 2) Pull job
$ts = [int][double]::Parse((Get-Date -UFormat %s))
$p = '/api/pull_job.php'
$body = ''
$sha = BodySha $body
$sign = HmacSign 'GET' $p $sha $ts $Secret
$pjHeaders = $headers.Clone(); $pjHeaders['X-Auth-TS'] = "$ts"; $pjHeaders['X-Auth-Sign'] = $sign
Write-Host "Pull job..." -ForegroundColor Cyan
try{ $r = Invoke-RestMethod -Method Get -Uri ($BaseUrl+$p) -Headers $pjHeaders } catch { Write-Host $_.Exception.Message -ForegroundColor Red; exit 1 }
Write-Host ($r | ConvertTo-Json -Depth 5)

# 3) Report results (if job exists)
if($r.job -and $r.job.id){
  $jobId = [int]$r.job.id
  $p = '/api/report_results.php'
  $payload = @{ job_id=$jobId; cursor=1; done=$true; items=@(@{name='Demo Shop'; city='الرياض'; country='SA'; phone='0550000000'}) }
  $body = ($payload | ConvertTo-Json -Compress -Depth 6)
  $ts = [int][double]::Parse((Get-Date -UFormat %s))
  $sha = BodySha $body
  $sign = HmacSign 'POST' $p $sha $ts $Secret
  $rpHeaders = $headers.Clone(); $rpHeaders['X-Auth-TS'] = "$ts"; $rpHeaders['X-Auth-Sign'] = $sign
  Write-Host "Report results..." -ForegroundColor Cyan
  try{ $r2 = Invoke-RestMethod -Method Post -Uri ($BaseUrl+$p) -Headers $rpHeaders -Body $body } catch { Write-Host $_.Exception.Message -ForegroundColor Red; exit 1 }
  Write-Host ($r2 | ConvertTo-Json -Depth 5)

  # Replay once to verify 409 protection
  Write-Host "Replay same report (should be 409)..." -ForegroundColor DarkYellow
  try{ $null = Invoke-WebRequest -Method Post -Uri ($BaseUrl+$p) -Headers $rpHeaders -Body $body -ErrorAction Stop; Write-Host "Unexpected 200" -ForegroundColor Red } catch { Write-Host ("Got expected error: " + $_.Exception.Response.StatusCode.value__) }
}

Write-Host "Done"