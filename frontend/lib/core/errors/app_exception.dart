import '../app_strings.dart';

enum AppErrorKind {
  validation,
  unauthenticated,
  forbidden,
  notFound,
  rateLimited,
  csrf,
  timeout,
  offline,
  server,
  unexpected,
}

class AppException implements Exception {
  const AppException(
    this.message, {
    this.kind = AppErrorKind.unexpected,
    this.statusCode,
    this.fieldErrors = const {},
    this.referenceId,
  });

  final String message;
  final AppErrorKind kind;
  final int? statusCode;
  final Map<String, List<String>> fieldErrors;
  final String? referenceId;

  String get friendlyMessage {
    return switch (kind) {
      AppErrorKind.unauthenticated => AppStrings.sessionExpired,
      AppErrorKind.forbidden => AppStrings.forbidden,
      AppErrorKind.notFound => AppStrings.notFound,
      AppErrorKind.timeout || AppErrorKind.offline => AppStrings.offline,
      AppErrorKind.server => AppStrings.maintenance,
      _ => message.isEmpty ? AppStrings.genericError : message,
    };
  }

  @override
  String toString() => friendlyMessage;
}
