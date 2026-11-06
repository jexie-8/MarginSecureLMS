import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/auth/session_controller.dart';
import '../../shared/app_widgets.dart';
import '../dashboard/dashboard_page.dart';
import '../resources/resource_page.dart';
import '../settings/settings_pages.dart';
import '../learning/learning_pages.dart';

class _Destination {
  const _Destination(this.section, this.label, this.icon);
  final String section, label;
  final IconData icon;
}

class AppShell extends StatefulWidget {
  const AppShell({super.key, required this.section});
  final String section;

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  bool _collapsed = false;

  List<_Destination> _destinations(String role) {
    if (role == 'platform_admin' ||
        role == 'university_admin' ||
        role == 'administrator' ||
        role == 'admin') {
      return const [
        _Destination('dashboard', 'Dashboard', Icons.dashboard_outlined),
        _Destination('users', 'Users', Icons.people_outline),
        _Destination(
          'academic-structure',
          'Academic structure',
          Icons.account_balance_outlined,
        ),
        _Destination('enrollments', 'Enrollments', Icons.how_to_reg_outlined),
        _Destination('course-staff', 'Course staff', Icons.group_work_outlined),
        _Destination('audit-logs', 'Audit logs', Icons.shield_outlined),
        _Destination('announcements', 'Announcements', Icons.campaign_outlined),
        _Destination('calendar', 'Calendar', Icons.calendar_month_outlined),
        _Destination(
          'notifications',
          'Notifications',
          Icons.notifications_outlined,
        ),
        _Destination('search', 'Search', Icons.search),
        _Destination(
          'data-tools',
          'Import / export',
          Icons.swap_vert_circle_outlined,
        ),
        _Destination('settings', 'Settings', Icons.settings_outlined),
      ];
    }
    if (role == 'department_admin') {
      return const [
        _Destination('dashboard', 'Dashboard', Icons.dashboard_outlined),
        _Destination('users', 'Users', Icons.people_outline),
        _Destination('enrollments', 'Enrollments', Icons.how_to_reg_outlined),
        _Destination('announcements', 'Announcements', Icons.campaign_outlined),
        _Destination('calendar', 'Calendar', Icons.calendar_month_outlined),
        _Destination(
          'notifications',
          'Notifications',
          Icons.notifications_outlined,
        ),
        _Destination('search', 'Search', Icons.search),
        _Destination(
          'data-tools',
          'Import / export',
          Icons.swap_vert_circle_outlined,
        ),
        _Destination('settings', 'Settings', Icons.settings_outlined),
      ];
    }
    if (role == 'instructor' ||
        role == 'lecturer' ||
        role == 'teaching_assistant') {
      return const [
        _Destination('dashboard', 'Dashboard', Icons.dashboard_outlined),
        _Destination('courses', 'Courses', Icons.menu_book_outlined),
        _Destination('assignments', 'Assignments', Icons.assignment_outlined),
        _Destination('submissions', 'Submissions', Icons.inventory_2_outlined),
        _Destination('grading', 'Grading', Icons.rate_review_outlined),
        _Destination('materials', 'Materials', Icons.folder_outlined),
        _Destination('announcements', 'Announcements', Icons.campaign_outlined),
        _Destination('attendance', 'Attendance', Icons.fact_check_outlined),
        _Destination('calendar', 'Calendar', Icons.calendar_month_outlined),
        _Destination(
          'notifications',
          'Notifications',
          Icons.notifications_outlined,
        ),
        _Destination('search', 'Search', Icons.search),
        _Destination('settings', 'Settings', Icons.settings_outlined),
      ];
    }
    return const [
      _Destination('dashboard', 'Dashboard', Icons.dashboard_outlined),
      _Destination('courses', 'My courses', Icons.menu_book_outlined),
      _Destination('assignments', 'Assignments', Icons.assignment_outlined),
      _Destination('grades', 'Grades', Icons.bar_chart_outlined),
      _Destination('materials', 'Materials', Icons.folder_outlined),
      _Destination('announcements', 'Announcements', Icons.campaign_outlined),
      _Destination('attendance', 'Attendance', Icons.fact_check_outlined),
      _Destination('calendar', 'Calendar', Icons.calendar_month_outlined),
      _Destination(
        'notifications',
        'Notifications',
        Icons.notifications_outlined,
      ),
      _Destination('search', 'Search', Icons.search),
      _Destination('settings', 'Settings', Icons.settings_outlined),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionController>().user!;
    final destinations = _destinations(user.role);
    final requestedSection = widget.section == 'profile'
        ? 'settings'
        : widget.section;
    final allowed = destinations.any(
      (item) => item.section == requestedSection,
    );
    final active = destinations.indexWhere(
      (item) => item.section == requestedSection,
    );
    final page = !allowed
        ? const Center(
            child: Text('You do not have permission to view this page.'),
          )
        : requestedSection == 'dashboard'
        ? const DashboardPage()
        : requestedSection == 'academic-structure'
        ? const AcademicStructurePage()
        : requestedSection == 'settings'
        ? const ProfilePage()
        : const [
            'materials',
            'announcements',
            'attendance',
            'calendar',
            'notifications',
            'search',
            'data-tools',
          ].contains(requestedSection)
        ? LearningToolPage(section: requestedSection)
        : ResourcePage(section: requestedSection);
    final width = MediaQuery.sizeOf(context).width;
    if (width < 760) {
      return _MobileShell(
        destinations: destinations,
        active: active,
        page: page,
      );
    }

    final canExpand = width >= 1000;
    final extended = canExpand && !_collapsed;
    return Scaffold(
      body: Row(
        children: [
          ScrollableNavigationRail(
            contentHeight: 118 + (destinations.length * 56),
            rail: NavigationRail(
              extended: extended,
              minWidth: 100,
              minExtendedWidth: 248,
              selectedIndex: active < 0 ? 0 : active,
              onDestinationSelected: (index) =>
                  context.go('/app/${destinations[index].section}'),
              leading: Padding(
                padding: const EdgeInsets.fromLTRB(10, 18, 10, 22),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    MarginLogo(compact: !extended, light: true),
                    if (canExpand) ...[
                      const SizedBox(width: 4),
                      IconButton(
                        tooltip: extended
                            ? 'Collapse sidebar'
                            : 'Expand sidebar',
                        onPressed: () =>
                            setState(() => _collapsed = !_collapsed),
                        color: const Color(0xFFAEBECD),
                        iconSize: 19,
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints.tightFor(
                          width: 32,
                          height: 32,
                        ),
                        icon: Icon(
                          extended
                              ? Icons.keyboard_double_arrow_left
                              : Icons.keyboard_double_arrow_right,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              destinations: destinations
                  .map(
                    (item) => NavigationRailDestination(
                      icon: Icon(item.icon),
                      selectedIcon: Icon(item.icon),
                      label: Text(item.label),
                    ),
                  )
                  .toList(),
            ),
          ),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 1450),
                  child: page,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _MobileShell extends StatelessWidget {
  const _MobileShell({
    required this.destinations,
    required this.active,
    required this.page,
  });
  final List<_Destination> destinations;
  final int active;
  final Widget page;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const MarginLogo()),
    drawer: NavigationDrawer(
      selectedIndex: active < 0 ? null : active,
      onDestinationSelected: (index) {
        Navigator.pop(context);
        context.go('/app/${destinations[index].section}');
      },
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(20, 26, 20, 12),
          child: Text(
            'NAVIGATION',
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.3,
            ),
          ),
        ),
        ...destinations.map(
          (item) => NavigationDrawerDestination(
            icon: Icon(item.icon),
            label: Text(item.label),
          ),
        ),
      ],
    ),
    body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: page),
  );
}
