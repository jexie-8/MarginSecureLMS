import 'package:flutter/foundation.dart';
import 'package:http/browser_client.dart';
import 'package:http/http.dart' as http;
import 'package:web/web.dart' as web;

/// Browser client that sends the session cookie with every API request.
http.Client createHttpClient() => BrowserClient()..withCredentials = true;

/// Reads a cookie the browser exposes to scripts (used for the CSRF token).
String? readCookie(String name) {
  for (final item in web.document.cookie.split(';')) {
    final parts = item.trim().split('=');
    if (parts.first == name && parts.length > 1) {
      return parts.sublist(1).join('=');
    }
  }
  return null;
}

/// A release build is served by the same host as the API (the web server
/// forwards /api to the backend), so it talks to its own origin. Cookies then
/// stay first-party, and nothing needs rebuilding when the host changes.
String get defaultApiOrigin =>
    kReleaseMode ? Uri.base.origin : 'http://localhost:8000';

String get deviceName => 'web';

/// Lets the browser download a file itself, using its session cookie.
void browserDownload(String url) => web.window.open(url, '_blank');

Future<void> saveAndOpenFile(List<int> bytes, String fileName) async {
  throw UnsupportedError('The browser downloads files directly.');
}
