import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:margin_lms_frontend/core/models/app_user.dart';
import 'package:margin_lms_frontend/core/theme/app_theme.dart';
import 'package:margin_lms_frontend/shared/app_widgets.dart';

void main() {
  test('admin profile parses MFA and avatar capabilities', () {
    final user = AppUser.fromJson({
      'id': 7,
      'name': 'Admin User',
      'email': 'admin@margin.test',
      'role': 'university_admin',
      'avatar_url': 'http://localhost/avatar/7',
      'mfa_enabled': true,
    });
    expect(user.isAdministrator, isTrue);
    expect(user.mfaEnabled, isTrue);
    expect(user.avatarUrl, isNotNull);
    expect(user.roleLabel, 'University Administrator');
  });

  test('student cannot be mistaken for an administrator', () {
    final user = AppUser.fromJson({
      'id': 8,
      'name': 'Student User',
      'email': 'student@margin.test',
      'role': 'student',
    });
    expect(user.isStudent, isTrue);
    expect(user.isAdministrator, isFalse);
    expect(user.mfaEnabled, isFalse);
  });

  testWidgets('logo remains visible at compact mobile width', (tester) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: const Scaffold(body: MarginLogo(light: true)),
      ),
    );
    expect(find.byType(Image), findsOneWidget);
    expect(find.bySemanticsLabel('Margin LMS logo'), findsOneWidget);
    expect(find.text('Margin'), findsOneWidget);
  });

  testWidgets('page header stacks its action without mobile overflow', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light(),
        home: const Scaffold(
          body: PageHeader(
            title: 'Course materials',
            description: 'Private files shared with your courses.',
            action: ElevatedButton(onPressed: null, child: Text('Upload')),
          ),
        ),
      ),
    );
    expect(find.text('Course materials'), findsOneWidget);
    expect(find.text('Upload'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('dark theme keeps actionable controls discoverable', (
    tester,
  ) async {
    var pressed = false;
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark(),
        home: Scaffold(
          body: Center(
            child: ElevatedButton(
              onPressed: () => pressed = true,
              child: const Text('Continue'),
            ),
          ),
        ),
      ),
    );
    expect(find.text('Continue'), findsOneWidget);
    await tester.tap(find.text('Continue'));
    expect(pressed, isTrue);
  });

  testWidgets('desktop navigation rail stays laid out on a short viewport', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 420);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Row(
            children: [
              ScrollableNavigationRail(
                contentHeight: 900,
                rail: NavigationRail(
                  selectedIndex: 0,
                  destinations: const [
                    NavigationRailDestination(
                      icon: Icon(Icons.home_outlined),
                      label: Text('Home'),
                    ),
                    NavigationRailDestination(
                      icon: Icon(Icons.settings_outlined),
                      label: Text('Settings'),
                    ),
                  ],
                ),
              ),
              const Expanded(child: SizedBox()),
            ],
          ),
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.byType(NavigationRail), findsOneWidget);
    expect(find.byType(SingleChildScrollView), findsOneWidget);
  });
}
