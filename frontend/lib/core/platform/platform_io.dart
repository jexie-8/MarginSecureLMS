import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:http/io_client.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Plain HTTP client for the mobile app. Authentication uses a bearer token,
/// so there are no cookies to manage.
http.Client createHttpClient() => IOClient(HttpClient());

/// Cookies are a browser concept; the mobile app has none.
String? readCookie(String name) => null;

/// 10.0.2.2 is how the Android emulator reaches the computer's localhost.
/// Real builds pass --dart-define=API_BASE_URL=https://... instead.
String get defaultApiOrigin =>
    Platform.isAndroid ? 'http://10.0.2.2:8000' : 'http://localhost:8000';

String get deviceName => '${Platform.operatingSystem} app';

void browserDownload(String url) {
  throw UnsupportedError('Mobile downloads go through the API client.');
}

/// Saves downloaded bytes to the app's private temporary folder and opens them
/// with the user's chosen viewer. The file name is reduced to safe characters.
Future<void> saveAndOpenFile(List<int> bytes, String fileName) async {
  final safeName = fileName
      .split(RegExp(r'[\\/]'))
      .last
      .replaceAll(RegExp(r'[^A-Za-z0-9._-]'), '_');
  final directory = await getTemporaryDirectory();
  final file = File(
    '${directory.path}/${safeName.isEmpty ? 'download' : safeName}',
  );
  await file.writeAsBytes(bytes, flush: true);
  await OpenFilex.open(file.path);
}
