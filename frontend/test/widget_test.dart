import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:margin_lms_frontend/core/theme/app_theme.dart';
import 'package:margin_lms_frontend/shared/app_widgets.dart';
import 'package:margin_lms_frontend/shared/state_pages.dart';

void main() {
  testWidgets('splash presents branded startup state', (tester) async {
    await tester.pumpWidget(
      MaterialApp(theme: AppTheme.light(), home: const SplashPage()),
    );
    expect(find.text('Margin'), findsOneWidget);
    expect(find.text('Preparing your secure workspace…'), findsOneWidget);
  });

  testWidgets('feedback component exposes retry action', (tester) async {
    var retried = false;
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light(),
        home: Scaffold(
          body: FeedbackView(
            icon: Icons.cloud_off,
            title: 'Offline',
            message: 'Reconnect',
            actionLabel: 'Try again',
            onAction: () => retried = true,
          ),
        ),
      ),
    );
    await tester.tap(find.text('Try again'));
    expect(retried, isTrue);
  });
}
