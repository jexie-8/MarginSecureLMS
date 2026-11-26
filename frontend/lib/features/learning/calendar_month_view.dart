import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

/// A month grid for the Calendar tab: days with events are marked, and the
/// events of the selected day are listed under the grid.
class CalendarMonthView extends StatefulWidget {
  const CalendarMonthView({super.key, required this.items, this.today});

  /// Calendar events and assignment deadlines as returned by /calendar.
  final List<Map<String, dynamic>> items;

  /// Overrides "today" (used by tests).
  final DateTime? today;

  @override
  State<CalendarMonthView> createState() => _CalendarMonthViewState();
}

class CalendarEntry {
  const CalendarEntry(
    this.title,
    this.start,
    this.end,
    this.isDeadline,
    this.detail,
  );

  final String title;
  final DateTime start;
  final DateTime? end;
  final bool isDeadline;
  final String detail;
}

DateTime _day(DateTime value) => DateTime(value.year, value.month, value.day);

class _CalendarMonthViewState extends State<CalendarMonthView> {
  late DateTime _month;
  late DateTime _selected;

  DateTime get _today => _day(widget.today ?? DateTime.now());

  @override
  void initState() {
    super.initState();
    _selected = _today;
    _month = DateTime(_today.year, _today.month);
  }

  Map<DateTime, List<CalendarEntry>> get _byDay {
    final map = <DateTime, List<CalendarEntry>>{};
    for (final item in widget.items) {
      final start = DateTime.tryParse(
        item['starts_at']?.toString() ?? '',
      )?.toLocal();
      if (start == null) continue;
      final end = DateTime.tryParse(
        item['ends_at']?.toString() ?? '',
      )?.toLocal();
      final entry = CalendarEntry(
        (item['title'] ?? 'Event').toString(),
        start,
        end,
        item['visibility'] == 'assignment',
        (item['description'] ?? '').toString(),
      );
      map.putIfAbsent(_day(start), () => []).add(entry);
    }
    for (final list in map.values) {
      list.sort((a, b) => a.start.compareTo(b.start));
    }
    return map;
  }

  void _moveMonth(int delta) => setState(() {
    _month = DateTime(_month.year, _month.month + delta);
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final byDay = _byDay;
    final firstOfMonth = DateTime(_month.year, _month.month);
    final daysInMonth = DateTime(_month.year, _month.month + 1, 0).day;
    final leading = firstOfMonth.weekday - 1; // Monday first
    final cellCount = ((leading + daysInMonth + 6) ~/ 7) * 7;
    final selectedEntries = byDay[_selected] ?? const <CalendarEntry>[];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 8, 8, 12),
            child: Column(
              children: [
                Row(
                  children: [
                    IconButton(
                      tooltip: 'Previous month',
                      icon: const Icon(Icons.chevron_left),
                      onPressed: () => _moveMonth(-1),
                    ),
                    Expanded(
                      child: Text(
                        DateFormat('MMMM y').format(_month),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                    TextButton(
                      onPressed: () => setState(() {
                        _selected = _today;
                        _month = DateTime(_today.year, _today.month);
                      }),
                      child: const Text('Today'),
                    ),
                    IconButton(
                      tooltip: 'Next month',
                      icon: const Icon(Icons.chevron_right),
                      onPressed: () => _moveMonth(1),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    for (final name in const [
                      'Mon',
                      'Tue',
                      'Wed',
                      'Thu',
                      'Fri',
                      'Sat',
                      'Sun',
                    ])
                      Expanded(
                        child: Center(
                          child: Text(
                            name,
                            style: Theme.of(context).textTheme.labelSmall
                                ?.copyWith(color: scheme.onSurfaceVariant),
                          ),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final wide = constraints.maxWidth >= 640;
                    return GridView.count(
                      crossAxisCount: 7,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      childAspectRatio: wide ? 1.25 : 0.8,
                      children: [
                        for (var i = 0; i < cellCount; i++)
                          _cell(
                            context,
                            i - leading + 1,
                            daysInMonth,
                            byDay,
                            wide,
                          ),
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(
          DateFormat('EEEE, MMMM d').format(_selected),
          style: Theme.of(
            context,
          ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 8),
        if (selectedEntries.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(20),
              child: Text('Nothing scheduled on this day.'),
            ),
          )
        else
          for (final entry in selectedEntries)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: entry.isDeadline
                      ? scheme.errorContainer
                      : scheme.primaryContainer,
                  child: Icon(
                    entry.isDeadline
                        ? Icons.assignment_late_outlined
                        : Icons.event,
                    color: entry.isDeadline
                        ? scheme.onErrorContainer
                        : scheme.onPrimaryContainer,
                  ),
                ),
                title: Text(
                  entry.title,
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                subtitle: Text(_when(entry)),
              ),
            ),
      ],
    );
  }

  String _when(CalendarEntry entry) {
    final time = DateFormat('h:mm a');
    if (entry.isDeadline) return 'Assignment due ${time.format(entry.start)}';
    final range = entry.end != null && entry.end != entry.start
        ? '${time.format(entry.start)} – ${time.format(entry.end!)}'
        : time.format(entry.start);
    return [range, if (entry.detail.isNotEmpty) entry.detail].join(' · ');
  }

  Widget _cell(
    BuildContext context,
    int dayNumber,
    int daysInMonth,
    Map<DateTime, List<CalendarEntry>> byDay,
    bool wide,
  ) {
    if (dayNumber < 1 || dayNumber > daysInMonth)
      return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    final date = DateTime(_month.year, _month.month, dayNumber);
    final entries = byDay[date] ?? const <CalendarEntry>[];
    final isToday = date == _today;
    final isSelected = date == _selected;

    return Padding(
      padding: const EdgeInsets.all(2),
      child: Material(
        color: isSelected ? scheme.primaryContainer : Colors.transparent,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: () => setState(() => _selected = date),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: isToday
                    ? scheme.primary
                    : scheme.outlineVariant.withValues(alpha: 0.5),
                width: isToday ? 1.6 : 1,
              ),
            ),
            padding: const EdgeInsets.all(4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '$dayNumber',
                  style: TextStyle(
                    fontWeight: isToday ? FontWeight.w800 : FontWeight.w500,
                    color: isToday ? scheme.primary : null,
                  ),
                ),
                const Spacer(),
                if (wide)
                  for (final entry in entries.take(2))
                    Text(
                      entry.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 11,
                        color: entry.isDeadline ? scheme.error : scheme.primary,
                      ),
                    )
                else if (entries.isNotEmpty)
                  Wrap(
                    spacing: 3,
                    runSpacing: 3,
                    children: [
                      for (final entry in entries.take(3))
                        Container(
                          key: const ValueKey('calendar-dot'),
                          width: 7,
                          height: 7,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: entry.isDeadline
                                ? scheme.error
                                : scheme.primary,
                          ),
                        ),
                    ],
                  ),
                if (wide && entries.length > 2)
                  Text(
                    '+${entries.length - 2} more',
                    style: const TextStyle(fontSize: 10),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
