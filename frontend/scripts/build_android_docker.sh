#!/usr/bin/env bash
# Runs inside the build container started by build_android.ps1 -Docker.
# /src is the frontend/ folder from the Windows checkout. Gradle and pub
# downloads live in named Docker volumes, so later builds are faster.
set -euo pipefail
: "${API_ORIGIN:?API_ORIGIN is not set}"

# Build from a copy, so the Windows checkout keeps its own .dart_tool,
# android/local.properties and Gradle state (they hold Windows paths).
rm -rf /work && mkdir -p /work
tar -C /src \
  --exclude=./build --exclude=./.dart_tool --exclude=./.idea \
  --exclude=./android/.gradle --exclude=./android/.kotlin \
  --exclude=./android/app/build --exclude=./android/local.properties \
  -cf - . | tar -C /work -xf -
cd /work
sed -i 's/\r$//' android/gradlew && chmod +x android/gradlew

flutter --version
flutter pub get
flutter build apk --release --obfuscate --split-debug-info=build/debug-info \
  "--dart-define=API_BASE_URL=$API_ORIGIN"

apk=build/app/outputs/flutter-apk/app-release.apk

# ---- Which key signed the APK? ---------------------------------------------
sdk="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
apksigner="$(ls -d "$sdk"/build-tools/*/ | sort -V | tail -1)apksigner"
certs="$("$apksigner" verify --verbose --print-certs "$apk")"
echo "$certs" | grep -E 'Verified using|certificate DN|certificate SHA-256'
apk_sha="$(echo "$certs" | sed -n 's/.*certificate SHA-256 digest: //p' | head -1 | tr -d ':' | tr 'A-F' 'a-f')"

if [ -f android/key.properties ]; then
  prop() { sed -n "s/^$1=//p" android/key.properties | tr -d '\r'; }
  store="android/app/$(prop storeFile)"
  export MARGIN_STOREPASS="$(prop storePassword)"
  key_sha="$(keytool -list -v -keystore "$store" -alias "$(prop keyAlias)" -storepass:env MARGIN_STOREPASS \
    | sed -n 's/.*SHA256: //p' | head -1 | tr -d ': \r' | tr 'A-F' 'a-f')"
  unset MARGIN_STOREPASS
  if [ -n "$key_sha" ] && [ "$apk_sha" = "$key_sha" ]; then
    echo "SIGNING OK: the APK is signed with the release keystore ($store)."
  else
    echo "SIGNING MISMATCH: APK $apk_sha, keystore $key_sha" >&2
    exit 1
  fi
else
  echo "WARNING: android/key.properties not found, so the APK is signed with the debug key." >&2
fi

# ---- Copy the results back to the Windows checkout ------------------------
mkdir -p /src/build/app/outputs/flutter-apk /src/build/debug-info
cp "$apk" "$apk.sha1" /src/build/app/outputs/flutter-apk/ 2>/dev/null || cp "$apk" /src/build/app/outputs/flutter-apk/
cp -r build/debug-info/. /src/build/debug-info/
echo "APK copied to frontend/$apk"
