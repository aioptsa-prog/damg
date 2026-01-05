param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$DocrootBase
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$auth = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
$delB = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/delete')
$delB.Query = 'file=' + [uri]::EscapeDataString("$DocrootBase/maintenance.flag")
$resp = Invoke-RestMethod -Uri $delB.Uri -Headers $auth -Method POST
if($resp.status -ne 1){ throw "Failed to delete maintenance.flag: $($resp.errors -join '; ')" }
Write-Host 'maintenance.flag removed'
