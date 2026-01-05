param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$Dir
)
$ErrorActionPreference = 'Stop'
if([string]::IsNullOrWhiteSpace($CpanelHost) -or [string]::IsNullOrWhiteSpace($CpanelUser) -or [string]::IsNullOrWhiteSpace($ApiToken) -or [string]::IsNullOrWhiteSpace($Dir)){
  throw 'CpanelHost, CpanelUser, ApiToken, and Dir are required.'
}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$auth = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
$ub = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/Fileman/list_files')
$ub.Query = 'dir=' + [uri]::EscapeDataString($Dir)
$resp = Invoke-RestMethod -Uri $ub.Uri -Headers $auth -Method GET
$resp | ConvertTo-Json -Depth 6
