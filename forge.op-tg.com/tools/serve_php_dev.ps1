param(
  [string]$BindHost = '127.0.0.1',
  [int]$Port = 8091,
  [string]$DocRoot = 'd:\Projects\nexus.op-tg.com',
  [string]$PhpPath = 'php.exe'
)
$ErrorActionPreference='Stop'
$php = (Get-Command $PhpPath -ErrorAction Stop).Path
Start-Process -WindowStyle Hidden -FilePath $php -ArgumentList '-S',"$BindHost`:$Port",'-t',"$DocRoot" | Out-Null
Write-Host "PHP dev server starting on http://${BindHost}:$Port (docroot=$DocRoot)" -ForegroundColor Cyan
