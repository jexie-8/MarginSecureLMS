<#
.SYNOPSIS
  Starts Margin LMS in Docker and publishes it over a free Cloudflare quick
  tunnel (HTTPS, no domain, no cost).

.DESCRIPTION
  First run: creates .env with random secrets, builds the web app, creates the
  database, runs migrations, seeds demonstration accounts and starts the tunnel.
  Later runs: reuse everything and just bring the stack back up.

  The tunnel address changes every time the tunnel container is recreated. The
  backend must know the public host name (session cookies and CSRF depend on
  it), so this script reads the address from the tunnel and passes it to the
  app. Run it again after stopping and starting the tunnel.

.EXAMPLE
  ./start.ps1
  ./start.ps1 -RebuildWeb     # after changing the Flutter code
#>
param(
  [switch]$RebuildWeb,
  [switch]$Seed,
  # Test on this PC only (http://localhost:8088). No tunnel, nothing public.
  [switch]$LocalOnly
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function New-Secret([int]$Bytes = 24) {
  $buffer = New-Object byte[] $Bytes
  $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
  $rng.GetBytes($buffer)
  $rng.Dispose()
  return (($buffer | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-Compose {
  # Compose writes progress to stderr; only a non-zero exit code is a failure.
  $previous = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  & docker compose @args
  $code = $LASTEXITCODE
  $ErrorActionPreference = $previous
  if ($code -ne 0) { throw "docker compose $($args -join ' ') failed (exit $code)." }
}

# ---- Docker available? -------------------------------------------------------
$previous = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
docker info *> $null
$dockerOk = ($LASTEXITCODE -eq 0)
$ErrorActionPreference = $previous
if (-not $dockerOk) { throw 'Docker is not running. Start Docker Desktop and try again.' }

# ---- Secrets (first run only) ---------------------------------------------------
function Read-EnvFile {
  $values = @{}
  if (Test-Path .env) {
    Get-Content .env | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object {
      $name, $value = $_ -split '=', 2
      $values[$name] = $value.Trim()
    }
  }
  return $values
}

# Add whatever is missing and keep anything already set (for example a Gmail
# app password typed in by hand). Existing values are never overwritten.
$envValues = Read-EnvFile
# Google shows app passwords in groups of four with spaces; the spaces are not part of it.
if ($envValues['MAIL_PASSWORD'] -match '[\s"'']') {
  $clean = ($envValues['MAIL_PASSWORD'] -replace '[\s"'']', '')
  (Get-Content .env) | ForEach-Object { if ($_ -match '^MAIL_PASSWORD=') { "MAIL_PASSWORD=$clean" } else { $_ } } | Set-Content .env -Encoding ascii
  $envValues = Read-EnvFile
}
$keyBytes = New-Object byte[] 32
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($keyBytes)
$rng.Dispose()
$defaults = [ordered]@{
  APP_KEY                    = 'base64:' + [Convert]::ToBase64String($keyBytes)
  POSTGRES_PASSWORD          = New-Secret
  DB_OWNER_PASSWORD          = New-Secret
  DB_RUNTIME_PASSWORD        = New-Secret
  MARGIN_SEED_ADMIN_PASSWORD = "$(New-Secret 12)-Aa1!"
  # All outgoing email (password resets, announcements) is delivered here.
  MAIL_ALWAYS_TO             = 'jexie.marano@gmail.com'
  MAIL_USERNAME              = 'jexie.marano@gmail.com'
  # Without a domain, Resend only accepts this sender address.
  MAIL_FROM_ADDRESS          = 'onboarding@resend.dev'
}
$missing = @()
foreach ($name in $defaults.Keys) {
  if (-not $envValues.ContainsKey($name) -or [string]::IsNullOrWhiteSpace($envValues[$name])) {
    $missing += "$name=$($defaults[$name])"
  }
}
if ($missing.Count -gt 0) {
  Write-Host 'Adding missing secrets to .env ...'
  if (Test-Path .env) {
    $existing = Get-Content .env -Raw
    if ($existing -and -not $existing.EndsWith("`n")) { Add-Content -Path .env -Value '' -Encoding ascii }
  }
  Add-Content -Path .env -Value $missing -Encoding ascii
}
# Typed in by hand: a Resend API key (mail over HTTPS, the default) or a Gmail
# app password (only if MAIL_MAILER=smtp and SMTP is not blocked).
foreach ($name in 'RESEND_API_KEY', 'MAIL_PASSWORD') {
  if (-not (Select-String -Path .env -Pattern "^$name=" -Quiet)) {
    Add-Content -Path .env -Value "$name=" -Encoding ascii
  }
}
$envValues = Read-EnvFile

# ---- Web app ----------------------------------------------------------------------
$webIndex = Join-Path $PSScriptRoot '..\..\frontend\build\web\index.html'
if ($RebuildWeb -or -not (Test-Path $webIndex)) {
  Write-Host 'Building the web app ...'
  & (Join-Path $PSScriptRoot '..\..\frontend\scripts\build_web.ps1')
  if ($LASTEXITCODE -ne 0) { throw 'Web build failed.' }
}

# ---- Database and migrations -----------------------------------------------------
Write-Host 'Starting the database ...'
Invoke-Compose up -d --build db
Write-Host 'Building the application image ...'
Invoke-Compose build app
Write-Host 'Running migrations ...'
Invoke-Compose run --rm migrate

if ($Seed -or -not (Test-Path .seeded)) {
  Write-Host 'Creating demonstration accounts ...'
  Invoke-Compose run --rm seed
  Set-Content -Path .seeded -Value (Get-Date -Format o)
}

# ---- Tunnel address ---------------------------------------------------------------
$hostName = $null
if ($LocalOnly) {
  $hostName = 'localhost:8088'
  $env:TUNNEL_SCHEME = 'http'
} else {
  Write-Host 'Starting the tunnel ...'
  Invoke-Compose up -d tunnel
}
for ($i = 0; $i -lt 40 -and -not $hostName; $i++) {
  Start-Sleep -Seconds 2
  $previous = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  $log = (& docker compose logs tunnel --no-color 2>&1) -join "`n"
  $ErrorActionPreference = $previous
  $found = [regex]::Matches($log, 'https://([a-z0-9-]+\.trycloudflare\.com)')
  if ($found.Count -gt 0) { $hostName = $found[$found.Count - 1].Groups[1].Value }
}
if (-not $hostName) { throw 'Could not read the tunnel address. Check: docker compose logs tunnel' }
$env:TUNNEL_HOST = $hostName
$scheme = if ($LocalOnly) { 'http' } else { 'https' }

# Remember the address in .env so a plain `docker compose up` later uses the same one.
function Set-EnvValue([string]$Name, [string]$Value) {
  $lines = @()
  if (Test-Path .env) { $lines = @(Get-Content .env) }
  $found = $false
  $lines = $lines | ForEach-Object {
    if ($_ -match "^$Name=") { $found = $true; "$Name=$Value" } else { $_ }
  }
  if (-not $found) { $lines += "$Name=$Value" }
  Set-Content -Path .env -Value $lines -Encoding ascii
}
Set-EnvValue 'TUNNEL_HOST' $hostName
Set-EnvValue 'TUNNEL_SCHEME' $scheme
$env:TUNNEL_SCHEME = $scheme

# ---- Application -------------------------------------------------------------------
Write-Host "Starting the application for $hostName ..."
Invoke-Compose up -d --build --force-recreate app worker scheduler waf
if ($LocalOnly) { Invoke-Compose stop tunnel }

Write-Host ''
if ($LocalOnly) {
  Write-Host "Margin LMS (local test only) is at:  http://$hostName" -ForegroundColor Green
} else {
  Write-Host "Margin LMS is online at:  https://$hostName" -ForegroundColor Green
  Write-Host 'It stays online while this PC, Docker and the tunnel container are running.'
}
Write-Host ''
Write-Host 'Demonstration accounts (password is MARGIN_SEED_ADMIN_PASSWORD in deploy/local/.env):'
Write-Host '  student@margin.test   lecturer@margin.test   university-admin@margin.test   admin@margin.test'
$mailer = if ($envValues['MAIL_MAILER']) { $envValues['MAIL_MAILER'] } else { 'resend' }
$mailKey = if ($mailer -eq 'smtp') { 'MAIL_PASSWORD' } else { 'RESEND_API_KEY' }
if ([string]::IsNullOrWhiteSpace($envValues[$mailKey])) {
  Write-Host ''
  Write-Host "Email is not configured: set $mailKey in deploy/local/.env, then run ./start.ps1 again." -ForegroundColor Yellow
}
