abstract final class AppConfig {
  static const appName = 'Margin LMS';
  static const apiOrigin = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8000',
  );
  static const apiPrefix = '/api/v1';
  static const requestTimeout = Duration(seconds: 25);
  static const cacheTtl = Duration(minutes: 5);

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
