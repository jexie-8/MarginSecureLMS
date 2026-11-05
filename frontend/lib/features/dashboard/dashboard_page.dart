import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/app_config.dart';
import '../../core/auth/session_controller.dart';
import '../../core/errors/app_exception.dart';
import '../../core/network/api_client.dart';
import '../../shared/app_widgets.dart';

class DashboardPage extends StatefulWidget {
  const DashboardPage({super.key});
  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardPageState extends State<DashboardPage> {
  late Future<_DashboardData> future;
  @override
  void initState() {
    super.initState();
    future = _load();
  }

  Future<_DashboardData> _load() async {
    final user = context.read<SessionController>().user!;
    final api = context.read<ApiClient>();
    final endpoints = user.isPlatformAdmin || user.isUniversityAdmin
        ? ['users', 'courses', 'enrollments', 'audit-logs']
        : user.isDepartmentAdmin
        ? ['users', 'courses', 'enrollments']
        : user.isInstructor
        ? ['courses', 'assignments', 'submissions']
        : ['courses', 'assignments', 'grades'];
    final data = <String, Map<String, dynamic>>{};
    AppException? firstError;
    await Future.wait(
      endpoints.map((endpoint) async {
        try {
          final response = await api.get(
            '${AppConfig.apiPrefix}/$endpoint',
            query: {'per_page': 12},
          );
          data[endpoint] = Map<String, dynamic>.from(response as Map);
        } on AppException catch (error) {
          firstError ??= error;
          data[endpoint] = const {'data': [], 'meta': {}};
        }
      }),
    );
    if (data.values.every((value) => (value['data'] as List? ?? []).isEmpty) &&
        firstError != null)
      throw firstError!;
    return _DashboardData(data);
  }

  void _retry() => setState(() => future = _load());

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionController>().user!;
    return FutureBuilder<_DashboardData>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done)
          return const PageSkeleton(rows: 7);
        if (snapshot.hasError)
          return SizedBox(
            height: 560,
            child: FeedbackView.error(
              snapshot.error as AppException,
              onRetry: _retry,
            ),
          );
        final data = snapshot.data!;
        if (user.isStudent) return _StudentDashboard(data: data);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            LayoutBuilder(
              builder: (context, constraints) {
                final count = constraints.maxWidth >= 1050
                    ? 4
                    : constraints.maxWidth >= 560
                    ? 2
                    : 1;
                final stats = _stats(user.role, data);
                return GridView.count(
                  crossAxisCount: count,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: count == 1 ? 3.5 : 2.2,
                  children: stats,
                );
              },
            ),
            const SizedBox(height: 18),
            _RecentCard(
              title: user.isAdministrator
                  ? user.isPlatformAdmin || user.isUniversityAdmin
                        ? 'Recent audit activity'
                        : 'Recent enrollments'
                  : user.isInstructor
                  ? 'Recent submissions'
                  : 'Upcoming assignments',
              items: data.items(
                user.isAdministrator
                    ? user.isPlatformAdmin || user.isUniversityAdmin
                          ? 'audit-logs'
                          : 'enrollments'
                    : user.isInstructor
                    ? 'submissions'
                    : 'assignments',
              ),
              onViewAll: () => context.go(
                '/app/${user.isAdministrator
                    ? user.isPlatformAdmin || user.isUniversityAdmin
                          ? 'audit-logs'
                          : 'enrollments'
                    : user.isInstructor
                    ? 'submissions'
                    : 'assignments'}',
              ),
            ),
          ],
        );
      },
    );
  }

  List<Widget> _stats(String role, _DashboardData data) {
    final admin =
        role == 'administrator' ||
        role == 'admin' ||
        role == 'platform_admin' ||
        role == 'university_admin' ||
        role == 'department_admin';
    final instructor =
        role == 'instructor' ||
        role == 'lecturer' ||
        role == 'teaching_assistant';
    if (admin)
      return [
        StatCard(
          label: 'Users',
          value: data.total('users'),
          icon: Icons.people_outline,
          note: 'University accounts',
        ),
        StatCard(
          label: 'Courses',
          value: data.total('courses'),
          icon: Icons.menu_book_outlined,
          note: 'Academic catalog',
        ),
        StatCard(
          label: 'Enrollments',
          value: data.total('enrollments'),
          icon: Icons.how_to_reg_outlined,
          note: 'Student placements',
        ),
        StatCard(
          label: 'Recent events',
          value: data.items('audit-logs').length.toString(),
          icon: Icons.shield_outlined,
          note: 'Latest audit entries',
        ),
      ];
    if (instructor)
      return [
        StatCard(
          label: 'Courses',
          value: data.total('courses'),
          icon: Icons.menu_book_outlined,
          note: 'Assigned courses',
        ),
        StatCard(
          label: 'Assignments',
          value: data.total('assignments'),
          icon: Icons.assignment_outlined,
          note: 'Coursework',
        ),
        StatCard(
          label: 'Submissions',
          value: data.total('submissions'),
          icon: Icons.inventory_2_outlined,
          note: 'Student attempts',
        ),
        StatCard(
          label: 'To review',
          value: data
              .items('submissions')
              .where((item) => item['grade'] == null)
              .length
              .toString(),
          icon: Icons.rate_review_outlined,
          note: 'Visible page',
        ),
      ];
    return [
      StatCard(
        label: 'My courses',
        value: data.total('courses'),
        icon: Icons.menu_book_outlined,
        note: 'Current enrollment',
      ),
      StatCard(
        label: 'Assignments',
        value: data.total('assignments'),
        icon: Icons.assignment_outlined,
        note: 'Published coursework',
      ),
      StatCard(
        label: 'Grades',
        value: data.total('grades'),
        icon: Icons.bar_chart_outlined,
        note: 'Published results',
      ),
      StatCard(
        label: 'Due soon',
        value: data.items('assignments').where(_dueSoon).length.toString(),
        icon: Icons.schedule_outlined,
        note: 'Next seven days',
      ),
    ];
  }

  bool _dueSoon(Map<String, dynamic> item) {
    final due = DateTime.tryParse(item['due_at']?.toString() ?? '')?.toLocal();
    return due != null &&
        due.isAfter(DateTime.now()) &&
        due.isBefore(DateTime.now().add(const Duration(days: 7)));
  }
}

class _StudentDashboard extends StatelessWidget {
  const _StudentDashboard({required this.data});
  final _DashboardData data;

  @override
  Widget build(BuildContext context) {
    final courses = data.items('courses');
    final assignments = data.items('assignments')
      ..removeWhere((item) {
        final due = DateTime.tryParse(
          item['due_at']?.toString() ?? '',
        )?.toLocal();
        return due != null && due.isBefore(DateTime.now());
      })
      ..sort((left, right) {
        final leftDue = DateTime.tryParse(left['due_at']?.toString() ?? '');
        final rightDue = DateTime.tryParse(right['due_at']?.toString() ?? '');
        if (leftDue == null) return 1;
        if (rightDue == null) return -1;
        return leftDue.compareTo(rightDue);
      });

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _SectionTitle(
          title: 'My courses',
          action: 'View all',
          onAction: () => context.go('/app/courses'),
        ),
        const SizedBox(height: 12),
        if (courses.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(28),
              child: Text('You are not enrolled in any courses yet.'),
            ),
          )
        else
          LayoutBuilder(
            builder: (context, constraints) {
              final columns = constraints.maxWidth >= 1150
                  ? 4
                  : constraints.maxWidth >= 760
                  ? 3
                  : constraints.maxWidth >= 520
                  ? 2
                  : 1;
              return GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: courses.length,
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: columns,
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                  childAspectRatio: columns == 1 ? 2.4 : 1.25,
                ),
                itemBuilder: (context, index) => _CourseCard(
                  course: courses[index],
                  onOpen: () =>
                      context.go('/detail/courses/${courses[index]['id']}'),
                ),
              );
            },
          ),
        const SizedBox(height: 26),
        _RecentCard(
          title: 'Upcoming assignments',
          items: assignments,
          onViewAll: () => context.go('/app/assignments'),
        ),
      ],
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.title,
    required this.action,
    required this.onAction,
  });
  final String title, action;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
        ),
      ),
      TextButton(onPressed: onAction, child: Text(action)),
    ],
  );
}

class _CourseCard extends StatelessWidget {
  const _CourseCard({required this.course, required this.onOpen});
  final Map<String, dynamic> course;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) => Card(
    clipBehavior: Clip.antiAlias,
    child: InkWell(
      onTap: onOpen,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.primaryContainer,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(
                    Icons.menu_book_outlined,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
                const Spacer(),
                Text(
                  course['code']?.toString() ?? 'COURSE',
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.secondary,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    letterSpacing: .8,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            Text(
              course['name']?.toString() ?? 'Course',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 7),
            Expanded(
              child: Text(
                course['description']?.toString() ?? 'Course information',
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                  height: 1.45,
                ),
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Text(
                  'Open course',
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.primary,
                    fontWeight: FontWeight.w700,
                    fontSize: 12,
                  ),
                ),
                const SizedBox(width: 4),
                Icon(
                  Icons.arrow_forward,
                  size: 16,
                  color: Theme.of(context).colorScheme.primary,
                ),
              ],
            ),
          ],
        ),
      ),
    ),
  );
}

class _DashboardData {
  const _DashboardData(this.raw);
  final Map<String, Map<String, dynamic>> raw;
  List<Map<String, dynamic>> items(String key) =>
      (raw[key]?['data'] as List? ?? [])
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
  String total(String key) =>
      ((raw[key]?['meta'] as Map?)?['total'] as num?)?.toInt().toString() ??
      items(key).length.toString();
}

class _RecentCard extends StatelessWidget {
  const _RecentCard({
    required this.title,
    required this.items,
    required this.onViewAll,
  });
  final String title;
  final List<Map<String, dynamic>> items;
  final VoidCallback onViewAll;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              TextButton(onPressed: onViewAll, child: const Text('View all')),
            ],
          ),
          const SizedBox(height: 8),
          if (items.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 46),
              child: Center(child: Text('Nothing here yet.')),
            )
          else
            ...items
                .take(6)
                .map(
                  (item) => Container(
                    padding: const EdgeInsets.symmetric(vertical: 13),
                    decoration: BoxDecoration(
                      border: Border(
                        bottom: BorderSide(
                          color: Theme.of(context).dividerColor,
                        ),
                      ),
                    ),
                    child: Row(
                      children: [
                        CircleAvatar(
                          radius: 18,
                          backgroundColor: Theme.of(
                            context,
                          ).colorScheme.primaryContainer,
                          child: Icon(
                            _icon(item),
                            size: 18,
                            color: Theme.of(context).colorScheme.primary,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _title(item),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                _subtitle(item),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: Theme.of(context).textTheme.labelSmall
                                    ?.copyWith(
                                      color: Theme.of(
                                        context,
                                      ).colorScheme.onSurfaceVariant,
                                    ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
        ],
      ),
    ),
  );

  IconData _icon(Map<String, dynamic> item) => item.containsKey('event')
      ? Icons.shield_outlined
      : item.containsKey('attempt_number')
      ? Icons.inventory_2_outlined
      : Icons.assignment_outlined;
  String _title(Map<String, dynamic> item) =>
      item['event']?.toString() ??
      (item['assignment'] is Map
          ? (item['assignment'] as Map)['title']?.toString()
          : null) ??
      item['title']?.toString() ??
      'Activity';
  String _subtitle(Map<String, dynamic> item) {
    final raw = item['created_at'] ?? item['submitted_at'] ?? item['due_at'];
    final date = DateTime.tryParse(raw?.toString() ?? '')?.toLocal();
    return date == null
        ? 'Margin LMS'
        : DateFormat('MMM d, y · h:mm a').format(date);
  }
}
