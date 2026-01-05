param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$DocrootBase,
  [string]$ZipRemotePath,
  [switch]$LeaveMaintenance,
  [switch]$NoTerminal
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$auth = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$targetDir = "$DocrootBase/releases/$ts"

# Ensure target dir exists
$mkdirB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/mkdir')
$mkdirB.Query = 'path=' + [uri]::EscapeDataString($targetDir)
$mk = Invoke-RestMethod -Uri $mkdirB.Uri -Headers $auth -Method Post

# Extract
$extractB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/extract_archive')
$extractB.Query = 'zip_file=' + [uri]::EscapeDataString($ZipRemotePath) + '&dest=' + [uri]::EscapeDataString($targetDir)
$ex = Invoke-RestMethod -Uri $extractB.Uri -Headers $auth -Method Post
if($ex.status -ne 1){ throw "extract_archive failed: $($ex.errors -join '; ')" }

# Maintenance on
$saveB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/save_file')
$saveB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/maintenance.flag") + '&data='
Invoke-RestMethod -Uri $saveB.Uri -Headers $auth -Method Post | Out-Null

if($NoTerminal){
  $src1 = "$DocrootBase/current"
  $dst1 = "$DocrootBase/prev_$ts"
  $src2 = "$DocrootBase/releases/$ts"
  $dst2 = "$DocrootBase/current"
  try {
    $ren1B = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/rename')
    $ren1B.Query = 'source=' + [uri]::EscapeDataString($src1) + '&dest=' + [uri]::EscapeDataString($dst1)
    Invoke-RestMethod -Uri $ren1B.Uri -Headers $auth -Method Post | Out-Null
  } catch {}
  $ren2B = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/rename')
  $ren2B.Query = 'source=' + [uri]::EscapeDataString($src2) + '&dest=' + [uri]::EscapeDataString($dst2)
  Invoke-RestMethod -Uri $ren2B.Uri -Headers $auth -Method Post | Out-Null
} else {
  $swapCmd = ('cd {0}; if [ -d current ]; then mv current prev_{1}; fi; mv releases/{1} current' -f $DocrootBase, $ts)
  $termB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Terminal/execute_command')
  $termB.Query = 'command=' + [uri]::EscapeDataString($swapCmd)
  Invoke-RestMethod -Uri $termB.Uri -Headers $auth -Method Post | Out-Null
}

# Ensure docroot bootstrap .htaccess
$bootstrap = @'
AddDefaultCharset UTF-8
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
# Maintenance mode: serve maintenance.html when maintenance.flag exists
RewriteCond %{REQUEST_URI} !^/maintenance\.html$
RewriteCond %{DOCUMENT_ROOT}/maintenance.flag -f
RewriteRule ^ /current/maintenance.html [L]

# Route everything to /current/ unless already there or file/dir exists at root
RewriteCond %{REQUEST_URI} !^/current/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ current/$1 [L]
</IfModule>
'@
$bootB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/save_file')
$bootB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/.htaccess") + '&data=' + [uri]::EscapeDataString($bootstrap)
Invoke-RestMethod -Uri $bootB.Uri -Headers $auth -Method Post | Out-Null

if(-not $LeaveMaintenance){
  $delB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/delete')
  $delB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/maintenance.flag")
  Invoke-RestMethod -Uri $delB.Uri -Headers $auth -Method Post | Out-Null
}

Write-Host ("EXTRACT_AND_SWAP_OK ts={0}" -f $ts)
