<#
.SYNOPSIS
  Builds the Margin LMS Android release APK against the running tunnel address.

.DESCRIPTION
  The free Cloudflare quick tunnel gets a new address every time the tunnel
  container starts, and the mobile app has its API address compiled in. So:
  start the stack with ../deploy/local/start.ps1, build the APK with this
  script, and do not restart the tunnel until the demonstration is over. If the
  tunnel restarts, run this script again.

  Release builds refuse any API address that is not https:// (AppConfig).
  Signing uses android/key.properties when present (see README), otherwise the
  debug key, which is fine for testing but not for distribution.

  -Docker builds inside a Linux container (scripts/android-build.Dockerfile,
  Flutter 3.44.7 with the Android SDK) instead of the local Flutter. Use it
  where Windows blocks Flutter's gen_snapshot.exe (Smart App Control). Gradle
  and pub downloads are kept in the Docker volumes margin-gradle-cache and
  margin-pub-cache. The container also checks that the APK is signed with the
  keystore from android/key.properties.

.EXAMPLE
  ./scripts/build_android.ps1
  ./scripts/build_android.ps1 -Docker
  ./scripts/build_android.ps1 -ApiUrl https://example.trycloudflare.com
#>
param(
  # Defaults to TUNNEL_HOST from deploy/local/.env.
  [string]$ApiUrl = '',
  # Build in a Linux Docker container instead of with the local Flutter.
  [switch]$Docker
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if ([string]::IsNullOrWhiteSpace($ApiUrl)) {
  $envFile = Join-Path $PSScriptRoot '..\..\deploy\local\.env'
  if (-not (Test-Path $envFile)) { throw "No $envFile. Start the stack with deploy/local/start.ps1 first." }
  $line = Get-Content $envFile | Where-Object { $_ -match '^TUNNEL_HOST=' } | Select-Object -Last 1
  $hostName = if ($line) { ($line -split '=', 2)[1].Trim() } else { '' }
  if ($hostName -notmatch '\.trycloudflare\.com$') {
    throw "TUNNEL_HOST is '$hostName', not a tunnel address. Run deploy/local/start.ps1 without -LocalOnly first."
  }
  $ApiUrl = "https://$hostName"
}

$uri = [System.Uri]$ApiUrl
if ($uri.Scheme -ne 'https') { throw "ApiUrl must start with https:// (got '$ApiUrl')." }
$origin = $uri.GetLeftPart([System.UriPartial]::Authority)

if (-not (Test-Path 'android/key.properties')) {
  Write-Host 'android/key.properties not found: the APK will be signed with the debug key.' -ForegroundColor Yellow
}

Write-Host "Building release APK for API $origin ..."
$ErrorActionPreference = 'Continue'
if ($Docker) {
  $image = 'margin-flutter-android:3.44.7'
  docker build -t $image -f scripts/android-build.Dockerfile scripts
  if ($LASTEXITCODE -ne 0) { throw 'Building the Flutter build image failed.' }
  # The .sh file may have Windows line endings after a checkout; strip them.
  docker run --rm `
    -v "${PWD}:/src" `
    -v margin-gradle-cache:/cache/gradle `
    -v margin-pub-cache:/cache/pub `
    -e GRADLE_USER_HOME=/cache/gradle `
    -e PUB_CACHE=/cache/pub `
    -e "API_ORIGIN=$origin" `
    $image bash -c "tr -d '\r' < /src/scripts/build_android_docker.sh | bash"
} else {
  flutter build apk --release --obfuscate --split-debug-info=build/debug-info "--dart-define=API_BASE_URL=$origin"
}
$buildExit = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if ($buildExit -ne 0) { throw 'flutter build apk failed.' }

$apk = 'build/app/outputs/flutter-apk/app-release.apk'
Write-Host ''
Write-Host "APK: frontend/$apk" -ForegroundColor Green
Write-Host "It talks to $origin and stops working when that tunnel address changes."
