import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:margin_lms_frontend/core/auth/session_controller.dart';
import 'package:margin_lms_frontend/core/auth/token_store.dart';
import 'package:margin_lms_frontend/core/cache/app_cache.dart';
import 'package:margin_lms_frontend/core/models/app_user.dart';
import 'package:margin_lms_frontend/core/network/api_client.dart';
import 'package:margin_lms_frontend/features/learning/learning_pages.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _course = {
  'id': 4,
  'university_id': 1,
  'name': 'Network Defense',
  'code': 'SEC260',
  'description': 'Network monitoring and incident response.',
  'program_ids': [1],
};

const _assignment = {
  'id': 8,
  'course_id': 4,
  'created_by': 4,
  'title': 'Incident Triage Worksheet',
  'description': 'Document an incident-triage sequence.',
  'max_score': '70.00',
  'due_at': '2099-12-11T23:59:00.000000Z',
  'published_at': '2026-11-23T23:06:52.000000Z',
  'rubric': null,
  'course': _course,
};

Future<http.Response> _api(http.Request request) async {
  final path = request.url.path;
  if (path.endsWith('/courses/4')) return _json({'data': _course});
  if (path.endsWith('/courses/4/grade-summary')) {
    return _json({
      'data': [
        {
          'student_id': 6,
          'earned': '56.00',
          'possible': '70.00',
          'percentage': 80,
        },
      ],
    });
  }
  if (path.endsWith('/assignments/8')) return _json({'data': _assignment});
  if (path.endsWith('/assignments')) {
    return _json({
      'data': [_assignment],
    });
  }
  if (path.endsWith('/submissions')) {
    return _json({
      'data': [
        {
          'id': 20,
          'assignment_id': 8,
          'student_id': 6,
          'attempt_number': 2,
          'content': 'My answer',
          'submitted_at': '2026-11-25T17:02:55.000000Z',
          'files': [
            {'id': 3, 'name': 'triage.pdf', 'size': 2048},
          ],
        },
      ],
    });
  }
  return http.Response('{}', 404);
}

Future<Widget> _app() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  final api = ApiClient(client: MockClient(_api));
  final session =
      SessionController(api, AppCache(prefs), tokenStore: TokenStore())
        ..user = const AppUser(
          id: 6,
          name: 'Sara',
          email: 'sara@example.test',
          role: 'student',
        );
  final router = GoRouter(
    initialLocation: '/app/courses',
    routes: [
      GoRoute(
        path: '/app/:section',
        builder: (context, _) => Scaffold(
          body: Center(
            child: TextButton(
              onPressed: () => context.push('/detail/courses/4'),
              child: const Text('Open course'),
            ),
          ),
        ),
      ),
      GoRoute(
        path: '/detail/:type/:id',
        builder: (_, state) => EntityDetailPage(
          type: state.pathParameters['type']!,
          id: int.parse(state.pathParameters['id']!),
        ),
      ),
    ],
  );
  return MultiProvider(
    providers: [
      Provider<ApiClient>.value(value: api),
      ChangeNotifierProvider<SessionController>.value(value: session),
    ],
    child: MaterialApp.router(routerConfig: router),
  );
}

void main() {
  testWidgets('course page reads like a page, not raw data, and can go back', (
    tester,
  ) async {
    await tester.pumpWidget(await _app());
    await tester.tap(find.text('Open course'));
    await tester.pumpAndSettle();

    expect(find.text('SEC260'), findsOneWidget);
    expect(
      find.text('Network monitoring and incident response.'),
      findsOneWidget,
    );
    expect(find.text('Your grade'), findsOneWidget);
    expect(find.text('80%'), findsOneWidget);
    expect(find.text('Assignments (1)'), findsOneWidget);
    expect(find.text('Incident Triage Worksheet'), findsOneWidget);
    expect(find.textContaining('· 70 points'), findsOneWidget);
    expect(find.text('56 of 70 points in published grades'), findsOneWidget);

    // No JSON, ids or field names leak onto the page.
    expect(find.textContaining('{'), findsNothing);
    expect(find.textContaining('university_id'), findsNothing);
    expect(find.textContaining('University Id'), findsNothing);

    // Back returns to where the course was opened.
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    expect(find.text('Open course'), findsOneWidget);
  });

  testWidgets('an assignment opens from the course with a submit button', (
    tester,
  ) async {
    await tester.pumpWidget(await _app());
    await tester.tap(find.text('Open course'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Incident Triage Worksheet'));
    await tester.pumpAndSettle();

    expect(find.text('SEC260 · Network Defense'), findsOneWidget);
    expect(find.text('Submit work'), findsOneWidget);
    expect(find.text('Your submissions (1)'), findsOneWidget);
    expect(find.text('Attempt 2'), findsOneWidget);
    expect(find.textContaining('1 file'), findsOneWidget);
    expect(find.textContaining('{'), findsNothing);

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    expect(find.text('Assignments (1)'), findsOneWidget);
  });
}
