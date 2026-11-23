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

String get defaultApiOrigin => 'http://localhost:8000';

String get deviceName => 'web';

/// Lets the browser download a file itself, using its session cookie.
void browserDownload(String url) => web.window.open(url, '_blank');

Future<void> saveAndOpenFile(List<int> bytes, String fileName) async {
  throw UnsupportedError('The browser downloads files directly.');
}
