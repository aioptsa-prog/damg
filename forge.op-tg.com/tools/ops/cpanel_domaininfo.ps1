param(
  [string]$CpanelHost,
  [string]$CpanelUser,
  [string]$ApiToken,
  [string]$Domain
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if(!$CpanelHost -or !$CpanelUser -or !$ApiToken -or !$Domain){ throw 'CpanelHost, CpanelUser, ApiToken, and Domain are required.' }
$auth = @{ 'Authorization' = "cpanel ${CpanelUser}:$ApiToken" }
$ub = [System.UriBuilder]::new('https', $CpanelHost, 2083, 'execute/DomainInfo/domains_data')
$ub.Query = 'domain=' + [uri]::EscapeDataString($Domain)
$resp = Invoke-RestMethod -Uri $ub.Uri -Headers $auth -Method Get
$resp | ConvertTo-Json -Depth 6
