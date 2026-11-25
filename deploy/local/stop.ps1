<#
.SYNOPSIS
  Stops Margin LMS and takes it offline. Data is kept in Docker volumes.
  Add -DeleteData to also erase the database and uploaded files.
#>
param([switch]$DeleteData)

Set-Location $PSScriptRoot
if ($DeleteData) {
  docker compose down --volumes
  Remove-Item .seeded -ErrorAction SilentlyContinue
} else {
  docker compose down
}
