# Linux build environment for the Android release APK (build_android.ps1 -Docker).
# On the development PC, Windows Smart App Control blocks Flutter's
# gen_snapshot.exe, so release builds run in this container instead.
#
# cirruslabs publishes no 3.44.7 image, so this starts from 3.44.0 and moves its
# Flutter SDK (a git checkout) to the exact tag used on the development PC.
FROM ghcr.io/cirruslabs/flutter:3.44.0

ARG FLUTTER_VERSION=3.44.7

RUN set -eux; \
    root="$(dirname "$(dirname "$(readlink -f "$(command -v flutter)")")")"; \
    git config --global --add safe.directory "$root"; \
    git -C "$root" fetch --depth 1 origin "refs/tags/$FLUTTER_VERSION:refs/tags/$FLUTTER_VERSION"; \
    git -C "$root" -c advice.detachedHead=false checkout "$FLUTTER_VERSION"; \
    flutter --version; \
    flutter precache --android; \
    flutter --version | grep -q "Flutter $FLUTTER_VERSION "
