import 'package:flutter/foundation.dart' show kIsWeb;

import '../app_config.dart';
import '../platform/platform.dart';
import 'api_client.dart';

/// Downloads a protected file from the API. The browser opens it itself using
/// its session cookie; the mobile app fetches it with its bearer token and
/// opens it in the user's chosen viewer.
Future<void> downloadFromApi(
  ApiClient api,
  String path, {
  String fallbackName = 'download',
}) async {
  if (kIsWeb) {
    browserDownload(AppConfig.uri(path).toString());
    return;
  }
  final file = await api.getBytes(path);
  await saveAndOpenFile(file.bytes, file.fileName ?? fallbackName);
}
