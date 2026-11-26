import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:margin_lms_frontend/core/auth/session_controller.dart';
import 'package:margin_lms_frontend/core/auth/token_store.dart';
import 'package:margin_lms_frontend/core/cache/app_cache.dart';
import 'package:margin_lms_frontend/core/models/app_user.dart';
import 'package:margin_lms_frontend/core/network/api_client.dart';
import 'package:margin_lms_frontend/core/theme/theme_controller.dart';
import 'package:margin_lms_frontend/features/settings/settings_pages.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

Future<Widget> _settings(String role, {bool mfaEnabled = false}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/mfa/setup')) {
        return _json({
          'secret': 'JBSWY3DPEHPK3PXP',
          'otpauth_url':
              'otpauth://totp/Margin%20LMS:x?secret=JBSWY3DPEHPK3PXP',
        });
      }
      if (request.url.path.endsWith('/sessions')) return _json({'data': []});
      return _json({});
    }),
  );
  final session =
      SessionController(api, AppCache(prefs), tokenStore: TokenStore())
        ..user = AppUser(
          id: 6,
          name: 'Demo User',
          email: 'demo@example.test',
          role: role,
          mfaEnabled: mfaEnabled,
        );
  return MultiProvider(
    providers: [
      Provider<ApiClient>.value(value: api),
      ChangeNotifierProvider<SessionController>.value(value: session),
      ChangeNotifierProvider<ThemeController>(
        create: (_) => ThemeController(prefs),
      ),
    ],
    child: const MaterialApp(
      home: Scaffold(body: SingleChildScrollView(child: ProfilePage())),
    ),
  );
}

void main() {
  for (final role in ['student', 'lecturer', 'teaching_assistant']) {
    testWidgets('$role can open MFA setup from settings', (tester) async {
      tester.view.physicalSize = const Size(1400, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(await _settings(role));
      await tester.pumpAndSettle();

      final button = find.text('Enable MFA');
      expect(button, findsOneWidget);
      await tester.tap(button);
      await tester.pumpAndSettle();

      expect(find.text('Multi-factor authentication'), findsOneWidget);
      await tester.enterText(find.byType(TextField).first, 'Password!123');
      await tester.tap(find.text('Start setup'));
      await tester.pumpAndSettle();
      expect(find.textContaining('JBSWY3DPEHPK3PXP'), findsWidgets);
    });
  }

  testWidgets('an enrolled student sees Manage MFA', (tester) async {
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(await _settings('student', mfaEnabled: true));
    await tester.pumpAndSettle();
    expect(find.text('Manage MFA'), findsOneWidget);
    expect(find.text('Enable MFA'), findsNothing);
  });
}
