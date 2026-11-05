import 'package:flutter/foundation.dart';

import '../app_config.dart';
import '../cache/app_cache.dart';
import '../errors/app_exception.dart';
import '../models/app_user.dart';
import '../network/api_client.dart';

enum SessionState { booting, signedOut, signedIn, startupError }

class SessionController extends ChangeNotifier {
  SessionController(this.api, this.cache) {
    api.onUnauthorized = expireSession;
  }

  final ApiClient api;
  final AppCache cache;
  SessionState state = SessionState.booting;
  AppUser? user;
  AppException? startupError;
  String? notice;

  Future<void> bootstrap() async {
    state = SessionState.booting;
    startupError = null;
    notifyListeners();
    try {
      final json = await api.get('${AppConfig.apiPrefix}/me');
      user = AppUser.fromJson((json as Map<String, dynamic>)['user']);
      state = SessionState.signedIn;
    } on AppException catch (error) {
      if (error.kind == AppErrorKind.unauthenticated) {
        state = SessionState.signedOut;
        notice = null;
      } else {
        startupError = error;
        state = SessionState.startupError;
      }
    }
    notifyListeners();
  }

  Future<bool> login(String email, String password) async {
    await api.prepareCsrf();
    try {
      final json = await api.post(
        '${AppConfig.apiPrefix}/login',
        body: {'email': email.trim(), 'password': password},
      );
      if ((json as Map<String, dynamic>)['mfa_required'] == true) {
        state = SessionState.signedOut;
        notifyListeners();
        return true;
      }
      user = AppUser.fromJson(json['user']);
      state = SessionState.signedIn;
      notice = null;
      notifyListeners();
      return false;
    } on AppException catch (error) {
      if (error.kind == AppErrorKind.unauthenticated) {
        state = SessionState.signedOut;
      }
      rethrow;
    }
  }

  Future<void> verifyMfa(String code) async {
    final json = await api.post(
      '${AppConfig.apiPrefix}/mfa/challenge',
      body: {'code': code.trim()},
    );
    user = AppUser.fromJson((json as Map<String, dynamic>)['user']);
    state = SessionState.signedIn;
    notice = null;
    notifyListeners();
  }

  Future<void> logout() async {
    final oldUser = user;
    try {
      await api.post('${AppConfig.apiPrefix}/logout');
    } catch (_) {
      // Local sign-out must still complete if the server is unreachable.
    }
    if (oldUser != null) await cache.clearUser(oldUser.id);
    user = null;
    state = SessionState.signedOut;
    notice = null;
    notifyListeners();
  }

  void expireSession() {
    if (state == SessionState.signedOut) return;
    final hadAuthenticatedSession =
        state == SessionState.signedIn && user != null;
    final oldUser = user;
    user = null;
    state = SessionState.signedOut;
    notice = hadAuthenticatedSession ? 'session_expired' : null;
    if (oldUser != null) cache.clearUser(oldUser.id);
    notifyListeners();
  }

  void clearNotice() => notice = null;
}
