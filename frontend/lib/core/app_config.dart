import 'package:flutter/foundation.dart';

import 'platform/platform.dart';

abstract final class AppConfig {
  static const appName = 'Margin LMS';
  static const _configuredOrigin = String.fromEnvironment('API_BASE_URL');

  /// The API address. Pass --dart-define=API_BASE_URL=https://api.example.edu
  /// for real builds; development falls back to the local server.
  static String get apiOrigin =>
      _configuredOrigin.isNotEmpty ? _configuredOrigin : defaultApiOrigin;
  static const apiPrefix = '/api/v1';
  static const requestTimeout = Duration(seconds: 25);
  static const cacheTtl = Duration(minutes: 5);

  /// Release builds must reach the API over HTTPS so credentials and tokens
  /// are never sent in clear text.
  static void assertSecureTransport() {
    if (kReleaseMode && !apiOrigin.startsWith('https://')) {
      throw StateError(
        'Release builds require an HTTPS API address. '
        'Build with --dart-define=API_BASE_URL=https://your-api-host',
      );
    }
  }

  static Uri uri(String path, [Map<String, dynamic>? query]) {
    final normalized = path.startsWith('/') ? path : '/$path';
    final base = Uri.parse(apiOrigin);
    final parameters = query?.map(
      (key, value) => MapEntry(key, value?.toString()),
    );
    parameters?.removeWhere((_, value) => value == null || value.isEmpty);
    return base.replace(path: normalized, queryParameters: parameters);
  }
}
