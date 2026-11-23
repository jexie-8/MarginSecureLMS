import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:margin_lms_frontend/core/auth/session_controller.dart';
import 'package:margin_lms_frontend/core/auth/token_store.dart';
import 'package:margin_lms_frontend/core/cache/app_cache.dart';
import 'package:margin_lms_frontend/core/network/api_client.dart';
import 'package:shared_preferences/shared_preferences.dart';

const _user = {
  'id': 7,
  'name': 'Sara',
  'email': 'sara@example.test',
  'role': 'student',
  'university_id': 1,
  'department_id': null,
  'avatar_url': null,
  'mfa_enabled': false,
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Future<SessionController> _controller(
  Future<http.Response> Function(http.Request) handler, {
  List<http.Request>? log,
}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  final api = ApiClient(
    client: MockClient((request) async {
      log?.add(request);
      return handler(request);
    }),
  );
  return SessionController(api, AppCache(prefs), tokenStore: TokenStore());
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  test('signing in stores the token and sends it as a bearer token', () async {
    final log = <http.Request>[];
    final session = await _controller((request) async {
      if (request.url.path.endsWith('/mobile/login')) {
        return _json({
          'token': 'abc123',
          'expires_at': DateTime.now()
              .add(const Duration(hours: 8))
              .toIso8601String(),
          'user': _user,
        });
      }
      return _json({'user': _user});
    }, log: log);

    final mfaRequired = await session.login('sara@example.test', 'pw');
    expect(mfaRequired, isFalse);
    expect(session.state, SessionState.signedIn);

    await session.api.get('/api/v1/me');
    expect(log.last.headers['Authorization'], 'Bearer abc123');
    // Mobile never uses cookie/CSRF machinery.
    expect(log.last.headers.containsKey('X-XSRF-TOKEN'), isFalse);
    expect(log.any((r) => r.url.path.contains('csrf-cookie')), isFalse);
    // The sign-in request carries the device name for the server's records.
    expect(jsonDecode(log.first.body)['device_name'], isNotEmpty);
  });

  test('a saved token restores the session on the next start', () async {
    await TokenStore().save(
      'saved',
      DateTime.now().add(const Duration(hours: 1)).toIso8601String(),
    );
    final log = <http.Request>[];
    final session = await _controller(
      (_) async => _json({'user': _user}),
      log: log,
    );

    await session.bootstrap();

    expect(session.state, SessionState.signedIn);
    expect(log.single.headers['Authorization'], 'Bearer saved');
  });

  test('an expired saved token is discarded without calling the API', () async {
    await TokenStore().save(
      'old',
      DateTime.now().subtract(const Duration(minutes: 1)).toIso8601String(),
    );
    final log = <http.Request>[];
    final session = await _controller(
      (_) async => _json({'user': _user}),
      log: log,
    );

    await session.bootstrap();

    expect(session.state, SessionState.signedOut);
    expect(log, isEmpty);
    expect(await TokenStore().read(), isNull);
  });

  test('a rejected token signs the user out and is forgotten', () async {
    await TokenStore().save('revoked', null);
    final session = await _controller(
      (_) async => _json({'message': 'Unauthenticated.'}, 401),
    );

    await session.bootstrap();

    expect(session.state, SessionState.signedOut);
    expect(await TokenStore().read(), isNull);
  });

  test('logging out revokes the token on the server and clears it', () async {
    final log = <http.Request>[];
    final session = await _controller((request) async {
      if (request.url.path.endsWith('/mobile/login')) {
        return _json({'token': 'abc123', 'user': _user});
      }
      return _json({'message': 'Logged out successfully.'});
    }, log: log);
    await session.login('sara@example.test', 'pw');

    await session.logout();

    expect(log.last.url.path, endsWith('/mobile/logout'));
    expect(log.last.headers['Authorization'], 'Bearer abc123');
    expect(session.state, SessionState.signedOut);
    expect(await TokenStore().read(), isNull);
    expect(session.api.authHeaders, isEmpty);
  });

  test('admins complete the MFA challenge before receiving a token', () async {
    final log = <http.Request>[];
    final session = await _controller((request) async {
      if (request.url.path.endsWith('/mobile/login')) {
        return _json({
          'mfa_required': true,
          'challenge_id': 'c' * 64,
        }, 202);
      }
      return _json({'token': 'after-mfa', 'user': _user});
    }, log: log);

    expect(await session.login('admin@example.test', 'pw'), isTrue);
    expect(session.state, SessionState.signedOut);
    expect(await TokenStore().read(), isNull);

    await session.verifyMfa('123456');

    final body = jsonDecode(log.last.body) as Map<String, dynamic>;
    expect(log.last.url.path, endsWith('/mobile/mfa/challenge'));
    expect(body['challenge_id'], 'c' * 64);
    expect(session.state, SessionState.signedIn);
    expect(await TokenStore().read(), 'after-mfa');
  });
}
