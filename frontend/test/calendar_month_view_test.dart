import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:margin_lms_frontend/features/learning/calendar_month_view.dart';

Widget _app(List<Map<String, dynamic>> items, {double width = 400}) =>
    MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: SizedBox(
            width: width,
            child: CalendarMonthView(
              items: items,
              today: DateTime(2026, 11, 26),
            ),
          ),
        ),
      ),
    );

final _items = <Map<String, dynamic>>[
  {
    'title': 'Midterm review session',
    'description': 'Web Application Security',
    'starts_at': DateTime(2026, 11, 26, 12).toIso8601String(),
    'ends_at': DateTime(2026, 11, 26, 14).toIso8601String(),
    'visibility': 'course',
  },
  {
    'title': 'Security Risk Assessment',
    'starts_at': DateTime(2026, 11, 29, 23, 59).toIso8601String(),
    'ends_at': DateTime(2026, 11, 29, 23, 59).toIso8601String(),
    'visibility': 'assignment',
  },
  {
    'title': 'Next month event',
    'starts_at': DateTime(2026, 12, 3, 10).toIso8601String(),
    'visibility': 'course',
  },
];

void main() {
  testWidgets('shows a month grid with today\'s events listed', (tester) async {
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_app(_items));
    expect(find.text('November 2026'), findsOneWidget);
    for (final day in ['Mon', 'Sun']) {
      expect(find.text(day), findsOneWidget);
    }
    expect(find.text('30'), findsOneWidget);
    // Two November days have events, shown as dots on a narrow screen.
    expect(find.byKey(const ValueKey('calendar-dot')), findsNWidgets(2));
    expect(find.text('Thursday, November 26'), findsOneWidget);
    expect(find.text('Midterm review session'), findsOneWidget);
    expect(find.textContaining('Web Application Security'), findsOneWidget);
  });

  testWidgets('tapping a day and changing month updates the list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_app(_items));
    await tester.tap(find.text('29'));
    await tester.pump();
    expect(find.text('Security Risk Assessment'), findsOneWidget);
    expect(find.textContaining('Assignment due'), findsOneWidget);

    await tester.tap(find.byTooltip('Next month'));
    await tester.pump();
    expect(find.text('December 2026'), findsOneWidget);
    await tester.tap(find.text('3'));
    await tester.pump();
    expect(find.text('Next month event'), findsOneWidget);

    await tester.tap(find.text('Today'));
    await tester.pump();
    expect(find.text('November 2026'), findsOneWidget);
  });

  testWidgets('an empty calendar still shows the grid', (tester) async {
    await tester.pumpWidget(_app(const []));
    expect(find.text('November 2026'), findsOneWidget);
    expect(find.text('Nothing scheduled on this day.'), findsOneWidget);
  });
}
