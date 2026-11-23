import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:http/http.dart' as http;

import '../app_config.dart';
import '../app_strings.dart';
import '../errors/app_exception.dart';
import '../platform/platform.dart';
import 'input_cleaner.dart';

class ApiClient {
  ApiClient({http.Client? client}) : _client = client ?? createHttpClient();

  final http.Client _client;
  VoidCallback? onUnauthorized;

  // Mobile only: the bearer token issued at sign-in. The browser app
  // authenticates with its session cookie instead and never has a token.
  String? _token;

  void setToken(String? token) => _token = token;

  /// Headers that identify the signed-in user to the API on the current
  /// platform. Use for any request made outside this client (e.g. images).
  Map<String, String> get authHeaders => {
    if (!kIsWeb && _token != null) 'Authorization': 'Bearer $_token',
  };

  void _addSecurityHeaders(Map<String, String> headers) {
    headers.addAll(authHeaders);
    final xsrf = readCookie('XSRF-TOKEN');
    if (xsrf != null) headers['X-XSRF-TOKEN'] = Uri.decodeComponent(xsrf);
  }

  /// Downloads a protected file and returns its bytes and suggested name.
  Future<({List<int> bytes, String? fileName})> getBytes(String path) async {
    final request = http.Request('GET', AppConfig.uri(path));
    request.headers['Accept'] = '*/*';
    _addSecurityHeaders(request.headers);
    try {
      final streamed = await _client
          .send(request)
          .timeout(AppConfig.requestTimeout);
      final response = await http.Response.fromStream(streamed);
      if (response.statusCode == 401) onUnauthorized?.call();
      if (response.statusCode >= 400) _throwResponse(response);
      final disposition = response.headers['content-disposition'] ?? '';
      final match = RegExp(r'filename="?([^";]+)"?').firstMatch(disposition);
      return (bytes: response.bodyBytes, fileName: match?.group(1));
    } on TimeoutException {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.timeout);
    } on AppException {
      rethrow;
    } catch (_) {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.offline);
    }
  }

  Future<void> prepareCsrf() async {
    // Only the browser app uses cookies, so only it needs a CSRF token.
    if (!kIsWeb) return;
    final response = await _client
        .get(
          AppConfig.uri('/sanctum/csrf-cookie'),
          headers: const {'Accept': 'application/json'},
        )
        .timeout(AppConfig.requestTimeout);
    if (response.statusCode >= 400) _throwResponse(response);
  }

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<dynamic> post(String path, {Map<String, dynamic>? body}) =>
      _send('POST', path, body: body);

  Future<dynamic> put(String path, {Map<String, dynamic>? body}) =>
      _send('PUT', path, body: body);

  Future<dynamic> patch(String path, {Map<String, dynamic>? body}) =>
      _send('PATCH', path, body: body);

  Future<dynamic> delete(String path, {Map<String, dynamic>? body}) =>
      _send('DELETE', path, body: body);

  Future<dynamic> multipart(
    String method,
    String path, {
    Map<String, String> fields = const {},
    List<UploadPart> files = const [],
    bool retriedCsrf = false,
  }) async {
    final request = http.MultipartRequest(method, AppConfig.uri(path));
    request.headers.addAll({
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    });
    _addSecurityHeaders(request.headers);
    request.fields.addAll(cleanFields(fields));
    for (final file in files) {
      request.files.add(
        http.MultipartFile.fromBytes(
          file.field,
          file.bytes,
          filename: file.name,
        ),
      );
    }
    try {
      final streamed = await _client
          .send(request)
          .timeout(AppConfig.requestTimeout);
      final response = await http.Response.fromStream(streamed);
      if (response.statusCode == 419 && !retriedCsrf) {
        await prepareCsrf();
        return multipart(
          method,
          path,
          fields: fields,
          files: files,
          retriedCsrf: true,
        );
      }
      if (response.statusCode == 401) onUnauthorized?.call();
      if (response.statusCode >= 400) _throwResponse(response);
      return response.body.trim().isEmpty ? null : jsonDecode(response.body);
    } on TimeoutException {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.timeout);
    } on AppException {
      rethrow;
    } catch (_) {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.offline);
    }
  }

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    bool retriedCsrf = false,
  }) async {
    final request = http.Request(method, AppConfig.uri(path, query));
    request.headers.addAll({
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    });
    _addSecurityHeaders(request.headers);
    if (body != null) request.body = jsonEncode(cleanOutgoing(body));

    try {
      final streamed = await _client
          .send(request)
          .timeout(AppConfig.requestTimeout);
      final response = await http.Response.fromStream(streamed);
      if (response.statusCode == 419 && !retriedCsrf) {
        await prepareCsrf();
        return _send(method, path, query: query, body: body, retriedCsrf: true);
      }
      if (response.statusCode == 401) onUnauthorized?.call();
      if (response.statusCode >= 400) _throwResponse(response);
      if (response.body.trim().isEmpty) return null;
      return jsonDecode(response.body);
    } on TimeoutException {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.timeout);
    } on AppException {
      rethrow;
    } catch (_) {
      throw const AppException(AppStrings.offline, kind: AppErrorKind.offline);
    }
  }

  Never _throwResponse(http.Response response) {
    Map<String, dynamic> payload = const {};
    try {
      payload = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {}

    final errors = <String, List<String>>{};
    final rawErrors = payload['errors'];
    if (rawErrors is Map) {
      for (final entry in rawErrors.entries) {
        final value = entry.value;
        errors[entry.key.toString()] = value is List
            ? value.map((item) => item.toString()).toList()
            : [value.toString()];
      }
    }
    final fallback = errors.values.expand((items) => items).firstOrNull;
    final message =
        payload['message']?.toString() ?? fallback ?? AppStrings.genericError;
    final kind = switch (response.statusCode) {
      401 => AppErrorKind.unauthenticated,
      403 => AppErrorKind.forbidden,
      404 => AppErrorKind.notFound,
      419 => AppErrorKind.csrf,
      422 => AppErrorKind.validation,
      429 => AppErrorKind.rateLimited,
      >= 500 => AppErrorKind.server,
      _ => AppErrorKind.unexpected,
    };
    throw AppException(
      message,
      kind: kind,
      statusCode: response.statusCode,
      fieldErrors: errors,
      referenceId: response.headers['x-request-id'],
    );
  }

  void close() => _client.close();
}

typedef VoidCallback = void Function();

class UploadPart {
  const UploadPart(this.field, this.name, this.bytes);
  final String field;
  final String name;
  final List<int> bytes;
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
