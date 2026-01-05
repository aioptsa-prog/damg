param([string]$PhpPath='php.exe')
$ErrorActionPreference='Stop'
$php=(Get-Command $PhpPath -ErrorAction Stop).Path
$files=Get-ChildItem -Recurse -Filter *.php | Select-Object -ExpandProperty FullName
$failed=$false
foreach($f in $files){
  & $php -l "$f" | Out-Null
  if($LASTEXITCODE -ne 0){ Write-Host "Lint failed: $f" -ForegroundColor Red; $failed=$true; break }
}
if($failed){ exit 1 } else { Write-Host "PHP Lint: OK ($($files.Count) files)" -ForegroundColor Green }
