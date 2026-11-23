<#
.SYNOPSIS
  Builds the Margin LMS web app for release and checks the output for leaks.

.DESCRIPTION
  - Requires an HTTPS API address (credentials must never travel in clear text).
  - Builds a release bundle: minified, no source maps, comments removed, and
    every file (including the CanvasKit renderer) served from the site itself
    so the Content-Security-Policy can stay strict.
  - Points the page's Content-Security-Policy at the real API address.
  - Scans the finished bundle for source maps, development addresses and
    anything that looks like a secret, and fails if it finds one.

.EXAMPLE
  ./scripts/build_web.ps1 -ApiUrl https://api.lms.example.edu
#>
param(
  [Parameter(Mandatory = $true)]
  [string]$ApiUrl
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$uri = [System.Uri]$ApiUrl
if ($uri.Scheme -ne 'https') {
  throw "ApiUrl must start with https:// (got '$ApiUrl')."
}
$origin = $uri.GetLeftPart([System.UriPartial]::Authority)

Write-Host "Building release bundle for API $origin ..."
# Flutter prints progress notes on stderr; do not treat those as failures.
$ErrorActionPreference = 'Continue'
flutter build web --release --no-web-resources-cdn --no-wasm-dry-run "--dart-define=API_BASE_URL=$origin"
$buildExit = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if ($buildExit -ne 0) { throw 'flutter build failed.' }

# Point the Content-Security-Policy at the real API instead of the dev default.
$index = 'build/web/index.html'
$html = Get-Content $index -Raw
if ($html -notmatch 'http://localhost:8000') {
  throw 'index.html does not contain the development API address to replace.'
}
$html = $html.Replace('http://localhost:8000', $origin)
Set-Content -Path $index -Value $html -NoNewline

# ---- Scan the output ------------------------------------------------------
$problems = @()
$files = Get-ChildItem build/web -Recurse -File

$maps = $files | Where-Object { $_.Extension -eq '.map' }
if ($maps) { $problems += "Source maps found: $($maps.Name -join ', ')" }

$textFiles = $files | Where-Object { $_.Extension -in '.js', '.html', '.json', '.css', '.txt' }
$patterns = [ordered]@{
  'development address'      = 'localhost|127\.0\.0\.1|10\.0\.2\.2'
  'private key'              = '-----BEGIN [A-Z ]*PRIVATE KEY-----'
  'AWS access key'           = 'AKIA[0-9A-Z]{16}'
  'live payment/API key'     = '(sk|pk)_live_[0-9A-Za-z]{10,}'
  'hard-coded secret'        = '(?i)(api[_-]?key|secret|passwd)["'' ]*[:=]["'' ]*[A-Za-z0-9/+_\-]{20,}'
  'Bearer token'             = 'Bearer [A-Za-z0-9\-_\.]{30,}'
}
foreach ($file in $textFiles) {
  $content = Get-Content $file.FullName -Raw
  foreach ($name in $patterns.Keys) {
    if ($content -cmatch $patterns[$name]) {
      # The CSP in index.html legitimately names the API; development addresses are not allowed anywhere.
      $problems += "$name in $($file.Name)"
    }
  }
}

if ($problems.Count -gt 0) {
  Write-Host 'Release check FAILED:' -ForegroundColor Red
  $problems | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
  exit 1
}

Write-Host "Release check passed: no source maps, development addresses or secrets in build/web." -ForegroundColor Green
Write-Host "Upload the contents of frontend/build/web to the static site."
