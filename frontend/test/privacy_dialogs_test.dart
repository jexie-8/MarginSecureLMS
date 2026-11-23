import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:margin_lms_frontend/core/network/api_client.dart';
import 'package:margin_lms_frontend/features/settings/privacy_pages.dart';
import 'package:provider/provider.dart';

Widget _host(ApiClient api, Widget dialog) => Provider<ApiClient>.value(
  value: api,
  child: MaterialApp(home: Scaffold(body: dialog)),
);

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

void main() {
  testWidgets('privacy notice lists its sections and the contact address', (
    tester,
  ) async {
    final api = ApiClient(
      client: MockClient(
        (_) async => _json({
          'controller': 'Test University',
          'contact_email': 'privacy@test.example',
          'updated_at': '2026-11-23',
          'sections': [
            {'title': 'What we collect', 'body': 'Only what is needed.'},
          ],
        }),
      ),
    );

    await tester.pumpWidget(_host(api, const PrivacyNoticeDialog()));
    await tester.pumpAndSettle();

    expect(find.text('What we collect'), findsOneWidget);
    expect(find.text('Only what is needed.'), findsOneWidget);
    expect(find.textContaining('privacy@test.example'), findsOneWidget);
  });

  testWidgets('data export asks for the password and sends it', (tester) async {
    http.Request? sent;
    final api = ApiClient(
      client: MockClient((request) async {
        sent = request;
        return _json({
          'profile': {'email': 'sara@example.test'},
        });
      }),
    );

    await tester.pumpWidget(_host(api, const ExportDataDialog()));

    // Nothing is sent until a password is entered.
    await tester.tap(find.text('Download'));
    await tester.pump();
    expect(sent, isNull);
    expect(find.text('Enter your password to continue.'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'secret-password');
    await tester.tap(find.text('Download'));
    await tester.pumpAndSettle();

    expect(sent!.url.path, endsWith('/profile/export'));
    expect(jsonDecode(sent!.body)['password'], 'secret-password');
    expect(find.textContaining('sara@example.test'), findsOneWidget);
  });
}
