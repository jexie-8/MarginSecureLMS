import 'package:flutter/foundation.dart';

import '../app_config.dart';
import '../cache/app_cache.dart';
import '../errors/app_exception.dart';
import '../models/app_user.dart';
import '../network/api_client.dart';
import '../platform/platform.dart';
import 'token_store.dart';

enum SessionState { booting, signedOut, signedIn, startupError }

class SessionController extends ChangeNotifier {
  SessionController(this.api, this.cache, {TokenStore? tokenStore})
    : _tokens = tokenStore ?? (kIsWeb ? null : TokenStore()) {
    api.onUnauthorized = expireSession;
  }

  final ApiClient api;
  final AppCache cache;
  // Mobile only. The browser app keeps its session in an HTTP-only cookie.
  final TokenStore? _tokens;
  SessionState state = SessionState.booting;
  AppUser? user;
  AppException? startupError;
  String? notice;
  String? _mfaChallengeId;

  // The mobile app uses dedicated token endpoints; the browser uses cookies.
  String get _auth => kIsWeb ? AppConfig.apiPrefix : '${AppConfig.apiPrefix}/mobile';

  Future<void> bootstrap() async {
    state = SessionState.booting;
    startupError = null;
    notifyListeners();
    try {
      if (_tokens != null) {
        final token = await _tokens.read();
        if (token == null) {
          state = SessionState.signedOut;
          notice = null;
          notifyListeners();
          return;
        }
        api.setToken(token);
      }
      final json = await api.get('${AppConfig.apiPrefix}/me');
      user = AppUser.fromJson((json as Map<String, dynamic>)['user']);
      state = SessionState.signedIn;
    } on AppException catch (error) {
      if (error.kind == AppErrorKind.unauthenticated) {
        await _forgetToken();
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
      final json =
          await api.post(
                '$_auth/login',
                body: {
                  'email': email.trim(),
                  'password': password,
                  if (!kIsWeb) 'device_name': deviceName,
                },
              )
              as Map<String, dynamic>;
      if (json['mfa_required'] == true) {
        _mfaChallengeId = json['challenge_id']?.toString();
        state = SessionState.signedOut;
        notifyListeners();
        return true;
      }
      await _acceptSignIn(json);
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
    final json =
        await api.post(
              '$_auth/mfa/challenge',
              body: {
                'code': code.trim(),
                if (!kIsWeb) 'challenge_id': _mfaChallengeId,
                if (!kIsWeb) 'device_name': deviceName,
              },
            )
            as Map<String, dynamic>;
    _mfaChallengeId = null;
    await _acceptSignIn(json);
    notice = null;
    notifyListeners();
  }

  Future<void> _acceptSignIn(Map<String, dynamic> json) async {
    if (_tokens != null) {
      final token = json['token']?.toString();
      if (token == null) {
        throw const AppException('Sign-in failed. Please try again.');
      }
      await _tokens.save(token, json['expires_at']?.toString());
      api.setToken(token);
    }
    user = AppUser.fromJson(json['user']);
    state = SessionState.signedIn;
  }

  Future<void> _forgetToken() async {
    api.setToken(null);
    await _tokens?.clear();
  }

  Future<void> logout() async {
    final oldUser = user;
    try {
      await api.post('$_auth/logout');
    } catch (_) {
      // Local sign-out must still complete if the server is unreachable.
    }
    await _forgetToken();
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
    _forgetToken();
    if (oldUser != null) cache.clearUser(oldUser.id);
    notifyListeners();
  }

  void clearNotice() => notice = null;
}
