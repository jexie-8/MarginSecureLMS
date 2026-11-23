// Platform-specific code lives behind this single import so the rest of the app
// compiles for both the browser and Android. The browser build uses cookie
// sessions; the mobile build uses bearer tokens.
export 'platform_io.dart' if (dart.library.js_interop) 'platform_web.dart';
