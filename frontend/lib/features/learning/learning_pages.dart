import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/app_config.dart';
import '../../core/auth/session_controller.dart';
import '../../core/errors/app_exception.dart';
import '../../core/models/app_user.dart';
import '../../core/network/api_client.dart';
import '../../core/network/download.dart';
import '../../shared/app_widgets.dart';
import '../resources/submit_work.dart';

/// Downloads a protected file (browser: opens it; mobile: fetches and opens it).
Future<void> _download(BuildContext context, String path) async {
  final api = context.read<ApiClient>();
  final messenger = ScaffoldMessenger.of(context);
  try {
    await downloadFromApi(api, path);
  } on AppException catch (error) {
    messenger.showSnackBar(SnackBar(content: Text(error.message)));
  } catch (_) {
    messenger.showSnackBar(
      const SnackBar(content: Text('The file could not be opened.')),
    );
  }
}

class LearningToolPage extends StatefulWidget {
  const LearningToolPage({super.key, required this.section});
  final String section;
  @override
  State<LearningToolPage> createState() => _LearningToolPageState();
}

class _LearningToolPageState extends State<LearningToolPage> {
  bool loading = true;
  AppException? error;
  List<Map<String, dynamic>> items = [];
  final search = TextEditingController();

  String get endpoint => switch (widget.section) {
    'materials' => 'materials',
    'announcements' => 'announcements',
    'notifications' => 'notifications',
    'attendance' => 'attendance',
    'calendar' => 'calendar',
    _ => widget.section,
  };
  String get title => switch (widget.section) {
    'materials' => 'Course materials',
    'announcements' => 'Announcements',
    'notifications' => 'Notifications',
    'attendance' => 'Attendance',
    'calendar' => 'Calendar',
    'search' => 'Search Margin LMS',
    'data-tools' => 'Import and export',
    _ => 'Learning tools',
  };

  @override
  void initState() {
    super.initState();
    if (widget.section != 'search' && widget.section != 'data-tools')
      unawaited(load());
    else
      loading = false;
  }

  @override
  void didUpdateWidget(covariant LearningToolPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.section != widget.section) {
      items = [];
      loading = widget.section != 'search' && widget.section != 'data-tools';
      if (loading)
        unawaited(load());
      else
        setState(() {});
    }
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final json = await context.read<ApiClient>().get(
        '${AppConfig.apiPrefix}/$endpoint',
        query: {'per_page': 100},
      );
      final raw = (json as Map)['data'];
      final list = raw is Map ? raw['data'] : raw;
      items = (list as List? ?? [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
    } on AppException catch (e) {
      error = e;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> doSearch() async {
    if (search.text.trim().length < 2) return;
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final json = await context.read<ApiClient>().get(
        '${AppConfig.apiPrefix}/search',
        query: {'q': search.text.trim()},
      );
      final groups = Map<String, dynamic>.from((json as Map)['data'] as Map);
      items = [
        for (final group in groups.entries)
          for (final row in (group.value as List? ?? []).whereType<Map>())
            {...Map<String, dynamic>.from(row), '_type': group.key},
      ];
    } on AppException catch (e) {
      error = e;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> pickAndUpload(String kind) async {
    final selected = await FilePicker.platform.pickFiles(
      withData: true,
      type: kind == 'avatar' ? FileType.image : FileType.custom,
      allowedExtensions: kind == 'csv'
          ? ['csv']
          : kind == 'material'
          ? ['pdf', 'doc', 'docx', 'txt', 'csv', 'xlsx', 'zip', 'png', 'jpg']
          : null,
    );
    if (selected == null || selected.files.single.bytes == null || !mounted)
      return;
    final file = selected.files.single;
    if (kind == 'material') {
      final values = await showDialog<Map<String, String>>(
        context: context,
        builder: (_) => const _MaterialDialog(),
      );
      if (values == null || !mounted) return;
      await context.read<ApiClient>().multipart(
        'POST',
        '${AppConfig.apiPrefix}/materials',
        fields: values,
        files: [UploadPart('file', file.name, file.bytes!)],
      );
      if (mounted) {
        showSuccess(context, 'Course material uploaded.');
        await load();
      }
    } else {
      final endpoint = kind == 'users'
          ? 'imports/users'
          : 'imports/enrollments';
      final json = await context.read<ApiClient>().multipart(
        'POST',
        '${AppConfig.apiPrefix}/$endpoint',
        files: [UploadPart('file', file.name, file.bytes!)],
      );
      if (mounted)
        showSuccess(
          context,
          (json as Map)['message']?.toString() ?? 'Import complete.',
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final admin = context.watch<SessionController>().user!.isAdministrator;
    if (widget.section == 'data-tools')
      return _DataTools(onImport: pickAndUpload);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PageHeader(title: title, description: _description()),
        if (widget.section == 'search')
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: search,
                  onSubmitted: (_) => doSearch(),
                  decoration: const InputDecoration(
                    labelText:
                        'Search courses, assignments, announcements, and materials',
                    prefixIcon: Icon(Icons.search),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              ElevatedButton(onPressed: doSearch, child: const Text('Search')),
            ],
          ),
        if (widget.section == 'materials' &&
            !context.read<SessionController>().user!.isStudent)
          Align(
            alignment: Alignment.centerRight,
            child: ElevatedButton.icon(
              onPressed: () => pickAndUpload('material'),
              icon: const Icon(Icons.upload_file),
              label: const Text('Upload material'),
            ),
          ),
        if (widget.section == 'announcements' &&
            !context.read<SessionController>().user!.isStudent)
          Align(
            alignment: Alignment.centerRight,
            child: ElevatedButton.icon(
              onPressed: _newAnnouncement,
              icon: const Icon(Icons.add),
              label: const Text('New announcement'),
            ),
          ),
        if (widget.section == 'calendar' &&
            !context.read<SessionController>().user!.isStudent)
          Align(
            alignment: Alignment.centerRight,
            child: ElevatedButton.icon(
              onPressed: _newCalendarEvent,
              icon: const Icon(Icons.event_available),
              label: const Text('Add event'),
            ),
          ),
        if (widget.section == 'attendance' &&
            context.read<SessionController>().user!.isInstructor)
          Align(
            alignment: Alignment.centerRight,
            child: ElevatedButton.icon(
              onPressed: _newAttendanceSession,
              icon: const Icon(Icons.add_task),
              label: const Text('New attendance session'),
            ),
          ),
        if (widget.section == 'notifications')
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () async {
                await context.read<ApiClient>().post(
                  '${AppConfig.apiPrefix}/notifications/read-all',
                );
                await load();
              },
              icon: const Icon(Icons.done_all),
              label: const Text('Mark all read'),
            ),
          ),
        const SizedBox(height: 16),
        if (loading)
          const PageSkeleton(rows: 6)
        else if (error != null)
          SizedBox(
            height: 520,
            child: FeedbackView.error(
              error!,
              onRetry: widget.section == 'search' ? doSearch : load,
            ),
          )
        else if (items.isEmpty)
          const Card(
            child: Padding(
              padding: EdgeInsets.all(38),
              child: Center(child: Text('Nothing to show yet.')),
            ),
          )
        else
          ...items.map(_itemCard),
        if (admin && widget.section == 'calendar')
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'University administrators can add calendar events through the API; assignment deadlines are included automatically.',
            ),
          ),
      ],
    );
  }

  String _description() => switch (widget.section) {
    'materials' => 'Private files shared with your courses.',
    'announcements' => 'Published course and university updates.',
    'notifications' => 'Your unread and recent activity.',
    'attendance' => 'Attendance history for your courses.',
    'calendar' => 'Course events and assignment deadlines in one timeline.',
    'search' => 'Find learning content across your permitted courses.',
    _ => '',
  };

  Widget _itemCard(Map<String, dynamic> item) {
    final heading =
        item['title'] ??
        item['course_name'] ??
        item['name'] ??
        item['_type'] ??
        'Item';
    final subtitle =
        item['body'] ??
        item['description'] ??
        item['message'] ??
        item['course_code'] ??
        '';
    final rawDate =
        item['starts_at'] ??
        item['held_at'] ??
        item['published_at'] ??
        item['created_at'];
    final date = DateTime.tryParse(rawDate?.toString() ?? '')?.toLocal();
    return Card(
      child: ListTile(
        onTap:
            widget.section == 'attendance' &&
                context.read<SessionController>().user!.isInstructor
            ? () => _recordAttendance(item)
            : null,
        leading: CircleAvatar(child: Icon(_icon())),
        title: Text(
          heading.toString(),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Text(
          [
            if (subtitle.toString().isNotEmpty) subtitle,
            if (date != null) DateFormat('MMM d, y · h:mm a').format(date),
          ].join('\n'),
          maxLines: 4,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: widget.section == 'materials'
            ? IconButton(
                tooltip: 'Download',
                icon: const Icon(Icons.download_outlined),
                onPressed: () => _download(
                  context,
                  '${AppConfig.apiPrefix}/materials/${item['id']}/download',
                ),
              )
            : null,
      ),
    );
  }

  IconData _icon() => switch (widget.section) {
    'materials' => Icons.folder_outlined,
    'announcements' => Icons.campaign_outlined,
    'notifications' => Icons.notifications_outlined,
    'attendance' => Icons.fact_check_outlined,
    'calendar' => Icons.calendar_month_outlined,
    _ => Icons.search,
  };

  Future<void> _newAnnouncement() async {
    final values = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => const _AnnouncementDialog(),
    );
    if (values == null || !mounted) return;
    try {
      await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/announcements',
        body: values,
      );
      if (mounted) {
        showSuccess(context, 'Announcement published.');
        await load();
      }
    } on AppException catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _newCalendarEvent() async {
    final values = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => const _CalendarDialog(),
    );
    if (values == null || !mounted) return;
    try {
      await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/calendar',
        body: values,
      );
      if (mounted) {
        showSuccess(context, 'Calendar event created.');
        await load();
      }
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _newAttendanceSession() async {
    final values = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => const _AttendanceSessionDialog(),
    );
    if (values == null || !mounted) return;
    try {
      await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/attendance/sessions',
        body: values,
      );
      if (mounted) {
        showSuccess(context, 'Attendance session created.');
        await load();
      }
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _recordAttendance(Map<String, dynamic> session) async {
    final values = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _AttendanceRecordDialog(
        courseId: (session['course_id'] as num).toInt(),
      ),
    );
    if (values == null || !mounted) return;
    try {
      await context.read<ApiClient>().put(
        '${AppConfig.apiPrefix}/attendance/sessions/${session['id']}/records',
        body: {
          'records': [values],
        },
      );
      if (mounted) {
        showSuccess(context, 'Attendance saved.');
        await load();
      }
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }
}

class EntityDetailPage extends StatefulWidget {
  const EntityDetailPage({super.key, required this.type, required this.id});
  final String type;
  final int id;
  @override
  State<EntityDetailPage> createState() => _EntityDetailPageState();
}

class _EntityDetailPageState extends State<EntityDetailPage> {
  late Future<Map<String, dynamic>> future = _load();
  Future<Map<String, dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final payload = Map<String, dynamic>.from(
      await api.get('${AppConfig.apiPrefix}/${widget.type}/${widget.id}')
          as Map,
    );
    final item = Map<String, dynamic>.from(
      (payload['data'] as Map?) ?? payload,
    );
    if (widget.type == 'courses') {
      final assignments = await api.get(
        '${AppConfig.apiPrefix}/assignments',
        query: {'course_id': widget.id, 'per_page': 100},
      );
      item['course_assignments'] = (assignments as Map)['data'];
      try {
        final summary = await api.get(
          '${AppConfig.apiPrefix}/courses/${widget.id}/grade-summary',
        );
        item['grade_summary'] = (summary as Map)['data'];
      } on AppException catch (error) {
        if (error.kind != AppErrorKind.forbidden) rethrow;
      }
    } else if (widget.type == 'assignments') {
      try {
        final submissions = await api.get(
          '${AppConfig.apiPrefix}/submissions',
          query: {'assignment_id': widget.id, 'per_page': 100},
        );
        item['submission_history'] = (submissions as Map)['data'];
      } on AppException catch (error) {
        if (error.kind != AppErrorKind.forbidden) rethrow;
      }
    }
    return {'data': item};
  }

  void _reload() => setState(() => future = _load());

  void _back() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/app/dashboard');
    }
  }

  PreferredSizeWidget _bar(String title) => AppBar(
    leading: BackButton(onPressed: _back),
    title: Text(title, overflow: TextOverflow.ellipsis),
  );

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
    future: future,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return Scaffold(
          appBar: _bar(''),
          body: const Center(child: CircularProgressIndicator()),
        );
      }
      if (snapshot.hasError) {
        final problem = snapshot.error is AppException
            ? snapshot.error! as AppException
            : const AppException('Unable to load this record.');
        return Scaffold(
          appBar: _bar(''),
          body: FeedbackView.error(problem, onRetry: _reload),
        );
      }
      final payload = snapshot.data!;
      final item = Map<String, dynamic>.from(
        (payload['data'] as Map?) ?? payload,
      );
      final user = context.watch<SessionController>().user;
      final title =
          _text(item['name'] ?? item['title']) ?? _typeName(widget.type);
      return Scaffold(
        appBar: _bar(title),
        body: RefreshIndicator(
          onRefresh: () async {
            _reload();
            await future;
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 900),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: switch (widget.type) {
                      'courses' => _course(item, user),
                      'assignments' => _assignment(item, user),
                      'submissions' => _submission(item, user),
                      'grades' => _grade(item),
                      _ => _generic(item),
                    },
                  ),
                ),
              ),
            ],
          ),
        ),
      );
    },
  );

  // ---- course -------------------------------------------------------------
  List<Widget> _course(Map<String, dynamic> item, AppUser? user) {
    final assignments = _list(item['course_assignments']);
    final summary = _list(item['grade_summary']);
    final student = user?.isStudent == true;
    return [
      _header(
        eyebrow: _text(item['code']),
        title: _text(item['name']) ?? 'Course',
        body: _text(item['description']),
      ),
      if (summary.isNotEmpty) ...[
        _section(student ? 'Your grade' : 'Grade overview'),
        _gradeSummary(summary, student),
      ],
      _section(
        'Assignments',
        count: assignments.isEmpty ? null : assignments.length,
      ),
      if (assignments.isEmpty)
        _empty(Icons.assignment_outlined, 'No assignments yet.')
      else
        _tiles([
          for (final assignment in assignments)
            ListTile(
              leading: const Icon(Icons.assignment_outlined),
              title: Text(_text(assignment['title']) ?? 'Assignment'),
              subtitle: _line([
                if (_date(assignment['due_at']) case final due?) 'Due $due',
                if (_number(assignment['max_score']) case final points?)
                  '$points points',
              ]),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (!student)
                    StatusBadge(
                      assignment['published_at'] == null
                          ? 'Draft'
                          : 'Published',
                    ),
                  const Icon(Icons.chevron_right),
                ],
              ),
              onTap: () =>
                  context.push('/detail/assignments/${assignment['id']}'),
            ),
        ]),
    ];
  }

  Widget _gradeSummary(List<Map<String, dynamic>> rows, bool student) {
    if (student) {
      final row = rows.first;
      return _tiles([
        ListTile(
          leading: const Icon(Icons.grade_outlined),
          title: Text(_percent(row['percentage'])),
          subtitle: Text(
            '${_number(row['earned']) ?? '0'} of '
            '${_number(row['possible']) ?? '0'} points in published grades',
          ),
        ),
      ]);
    }
    final average =
        rows
            .map((row) => num.tryParse('${row['percentage']}') ?? 0)
            .fold<num>(0, (sum, value) => sum + value) /
        rows.length;
    return _tiles([
      ListTile(
        leading: const Icon(Icons.insights_outlined),
        title: Text('Average ${_percent(average)}'),
        subtitle: Text(
          '${rows.length} student${rows.length == 1 ? '' : 's'} '
          'with published grades',
        ),
      ),
    ]);
  }

  // ---- assignment ---------------------------------------------------------
  List<Widget> _assignment(Map<String, dynamic> item, AppUser? user) {
    final course = item['course'] is Map ? item['course'] as Map : null;
    final history = _list(item['submission_history']);
    final student = user?.isStudent == true;
    final published = item['published_at'] != null;
    return [
      _header(
        eyebrow: course == null
            ? null
            : [
                _text(course['code']),
                _text(course['name']),
              ].whereType<String>().join(' · '),
        title: _text(item['title']) ?? 'Assignment',
        body: _text(item['description']),
        badges: [
          if (!student) StatusBadge(published ? 'Published' : 'Draft'),
          if (_isPast(item['due_at'])) const StatusBadge('Past due'),
        ],
      ),
      _facts([
        (Icons.event_outlined, 'Due', _date(item['due_at'])),
        (Icons.star_outline, 'Points', _number(item['max_score'])),
        if (!student)
          (Icons.publish_outlined, 'Published', _date(item['published_at'])),
      ]),
      if (student && published)
        Padding(
          padding: const EdgeInsets.only(top: 16),
          child: FilledButton.icon(
            onPressed: () => _submit(item),
            icon: const Icon(Icons.upload_file_outlined),
            label: const Text('Submit work'),
          ),
        ),
      _section(
        student ? 'Your submissions' : 'Submissions',
        count: history.isEmpty ? null : history.length,
      ),
      if (history.isEmpty)
        _empty(
          Icons.inbox_outlined,
          student
              ? 'You have not submitted this assignment yet.'
              : 'No submissions yet.',
        )
      else
        _tiles([
          for (final submission in history)
            ListTile(
              leading: CircleAvatar(
                child: Text('${submission['attempt_number'] ?? ''}'),
              ),
              title: Text(
                student
                    ? 'Attempt ${submission['attempt_number'] ?? ''}'
                    : _text((submission['student'] as Map?)?['name']) ??
                          'Student',
              ),
              subtitle: _line([
                if (!student) 'Attempt ${submission['attempt_number'] ?? ''}',
                ?_date(submission['submitted_at']),
                _filesSummary(submission['files']),
              ]),
              trailing: const Icon(Icons.chevron_right),
              onTap: () =>
                  context.push('/detail/submissions/${submission['id']}'),
            ),
        ]),
    ];
  }

  Future<void> _submit(Map<String, dynamic> item) async {
    if (await submitAssignmentWork(context, item) && mounted) _reload();
  }

  // ---- submission ---------------------------------------------------------
  List<Widget> _submission(Map<String, dynamic> item, AppUser? user) {
    final assignment = item['assignment'] is Map
        ? item['assignment'] as Map
        : null;
    final student = item['student'] is Map ? item['student'] as Map : null;
    final grade = item['grade'] is Map
        ? Map<String, dynamic>.from(item['grade'] as Map)
        : null;
    final files = _list(item['files']);
    return [
      _header(
        eyebrow: _text(assignment?['title']),
        title: 'Attempt ${item['attempt_number'] ?? ''}'.trim(),
        badges: [StatusBadge(grade == null ? 'Grade pending' : 'Graded')],
      ),
      _facts([
        if (user?.isStudent != true)
          (Icons.person_outline, 'Student', _text(student?['name'])),
        (Icons.schedule_outlined, 'Submitted', _date(item['submitted_at'])),
        (Icons.star_outline, 'Points', _number(assignment?['max_score'])),
      ]),
      if (_text(item['content']) case final content?) ...[
        _section('Response'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SelectableText(content),
          ),
        ),
      ],
      _section('Files', count: files.isEmpty ? null : files.length),
      if (files.isEmpty)
        _empty(Icons.attach_file, 'No files attached.')
      else
        _tiles([
          for (final file in files)
            ListTile(
              leading: const Icon(Icons.insert_drive_file_outlined),
              title: Text(
                _text(file['name']) ?? 'File',
                overflow: TextOverflow.ellipsis,
              ),
              subtitle: Text(_fileSize(file['size'])),
              trailing: IconButton(
                tooltip: 'Download',
                icon: const Icon(Icons.download_outlined),
                onPressed: () => _download(
                  context,
                  '${AppConfig.apiPrefix}/submission-files/${file['id']}/download',
                ),
              ),
            ),
        ]),
      if (grade != null) ...[
        _section('Grade'),
        _gradeTile(grade, assignment?['max_score']),
      ],
    ];
  }

  Widget _gradeTile(Map<String, dynamic> grade, dynamic maxScore) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            [
              _number(grade['score']) ?? '—',
              if (_number(maxScore) case final max?) '/ $max',
            ].join(' '),
            style: Theme.of(
              context,
            ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
          ),
          if (_text(grade['feedback']) case final feedback?) ...[
            const SizedBox(height: 8),
            SelectableText(feedback),
          ],
        ],
      ),
    ),
  );

  // ---- grade --------------------------------------------------------------
  List<Widget> _grade(Map<String, dynamic> item) => [
    _header(
      eyebrow: 'Grade',
      title: '${_number(item['score']) ?? '—'} points',
      badges: [
        StatusBadge(item['published_at'] == null ? 'Draft' : 'Published'),
      ],
    ),
    _facts([
      (Icons.publish_outlined, 'Published', _date(item['published_at'])),
    ]),
    if (_text(item['feedback']) case final feedback?) ...[
      _section('Feedback'),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: SelectableText(feedback),
        ),
      ),
    ],
    if (item['submission_id'] != null)
      Padding(
        padding: const EdgeInsets.only(top: 16),
        child: OutlinedButton.icon(
          onPressed: () =>
              context.push('/detail/submissions/${item['submission_id']}'),
          icon: const Icon(Icons.description_outlined),
          label: const Text('View submission'),
        ),
      ),
  ];

  // Anything else: plain fields only, never ids or nested data.
  List<Widget> _generic(Map<String, dynamic> item) => [
    _header(title: _text(item['name'] ?? item['title']) ?? 'Details'),
    _facts([
      for (final entry in item.entries)
        if (entry.value is! Map &&
            entry.value is! List &&
            entry.key != 'id' &&
            !entry.key.endsWith('_id') &&
            !entry.key.endsWith('_by'))
          (
            Icons.label_outline,
            _label(entry.key),
            _date(entry.value) ?? _text(entry.value),
          ),
    ]),
  ];

  // ---- building blocks ----------------------------------------------------
  Widget _header({
    required String title,
    String? eyebrow,
    String? body,
    List<Widget> badges = const [],
  }) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (eyebrow != null && eyebrow.isNotEmpty) ...[
              Text(
                eyebrow,
                style: theme.textTheme.labelLarge?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 6),
            ],
            Text(
              title,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            if (badges.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: badges),
            ],
            if (body != null) ...[
              const SizedBox(height: 14),
              Text(
                body,
                style: theme.textTheme.bodyLarge?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  height: 1.45,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _facts(List<(IconData, String, String?)> rows) {
    final shown = rows.where((row) => row.$3 != null).toList();
    if (shown.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: _tiles([
        for (final (icon, label, value) in shown)
          ListTile(
            leading: Icon(icon),
            title: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            subtitle: Text(value!, style: theme.textTheme.bodyLarge),
          ),
      ]),
    );
  }

  Widget _section(String title, {int? count}) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 24, 4, 8),
    child: Text(
      count == null ? title : '$title ($count)',
      style: Theme.of(
        context,
      ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    ),
  );

  Widget _tiles(List<Widget> tiles) => Card(
    clipBehavior: Clip.antiAlias,
    child: Column(
      children: [
        for (var i = 0; i < tiles.length; i++) ...[
          if (i > 0) const Divider(height: 1),
          tiles[i],
        ],
      ],
    ),
  );

  Widget _empty(IconData icon, String message) =>
      _tiles([ListTile(leading: Icon(icon), title: Text(message))]);
}

Widget? _line(List<String> parts) {
  final text = parts.where((part) => part.isNotEmpty).join(' · ');
  return text.isEmpty ? null : Text(text);
}

class _DataTools extends StatefulWidget {
  const _DataTools({required this.onImport});
  final Future<void> Function(String) onImport;
  @override
  State<_DataTools> createState() => _DataToolsState();
}

class _DataToolsState extends State<_DataTools> {
  int? courseId;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const PageHeader(
        title: 'Import and export',
        description:
            'Bulk-load validated university data and export course grades.',
      ),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Wrap(
            spacing: 12,
            runSpacing: 12,
            children: [
              ElevatedButton.icon(
                onPressed: () => widget.onImport('users'),
                icon: const Icon(Icons.person_add_alt),
                label: const Text('Import users CSV'),
              ),
              ElevatedButton.icon(
                onPressed: () => widget.onImport('enrollments'),
                icon: const Icon(Icons.how_to_reg),
                label: const Text('Import enrollments CSV'),
              ),
              SizedBox(
                width: 360,
                child: _CourseSelector(
                  label: 'Course to export',
                  onSelected: (value) => setState(() => courseId = value),
                ),
              ),
              OutlinedButton.icon(
                onPressed: courseId == null
                    ? null
                    : () => _download(
                        context,
                        '${AppConfig.apiPrefix}/courses/$courseId/grades.csv',
                      ),
                icon: const Icon(Icons.download_outlined),
                label: const Text('Export grades CSV'),
              ),
            ],
          ),
        ),
      ),
      const SizedBox(height: 12),
      const Text(
        'User CSV: name,email,role,password,university_id,department_id\nEnrollment CSV: student_email,course_code',
      ),
    ],
  );
}

class _MaterialDialog extends StatefulWidget {
  const _MaterialDialog();
  @override
  State<_MaterialDialog> createState() => _MaterialDialogState();
}

class _MaterialDialogState extends State<_MaterialDialog> {
  int? courseId;
  final title = TextEditingController();
  @override
  void dispose() {
    title.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Upload course material'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _CourseSelector(
          onSelected: (value) => setState(() => courseId = value),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: title,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Title'),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed: courseId == null || title.text.trim().isEmpty
            ? null
            : () => Navigator.pop(context, {
                'course_id': courseId.toString(),
                'title': title.text.trim(),
                'published': '1',
              }),
        child: const Text('Continue'),
      ),
    ],
  );
}

class _AnnouncementDialog extends StatefulWidget {
  const _AnnouncementDialog();
  @override
  State<_AnnouncementDialog> createState() => _AnnouncementDialogState();
}

class _AnnouncementDialogState extends State<_AnnouncementDialog> {
  int? courseId;
  final title = TextEditingController();
  final body = TextEditingController();
  @override
  void dispose() {
    title.dispose();
    body.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('New announcement'),
    content: SizedBox(
      width: 520,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CourseSelector(
            onSelected: (value) => setState(() => courseId = value),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: title,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(labelText: 'Title'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: body,
            onChanged: (_) => setState(() {}),
            minLines: 4,
            maxLines: 8,
            decoration: const InputDecoration(labelText: 'Message'),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed:
            courseId == null ||
                title.text.trim().isEmpty ||
                body.text.trim().isEmpty
            ? null
            : () => Navigator.pop(context, {
                'course_id': courseId,
                'title': title.text.trim(),
                'body': body.text.trim(),
                'published': true,
              }),
        child: const Text('Publish'),
      ),
    ],
  );
}

class _CalendarDialog extends StatefulWidget {
  const _CalendarDialog();
  @override
  State<_CalendarDialog> createState() => _CalendarDialogState();
}

class _CalendarDialogState extends State<_CalendarDialog> {
  int? courseId;
  final title = TextEditingController();
  final starts = TextEditingController();
  @override
  void dispose() {
    title.dispose();
    starts.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Add calendar event'),
    content: SizedBox(
      width: 500,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _CourseSelector(
            onSelected: (value) => setState(() => courseId = value),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: title,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(labelText: 'Title'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: starts,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              labelText: 'Starts at',
              helperText: 'Example: 2026-11-30 10:00',
            ),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed:
            courseId == null ||
                title.text.trim().isEmpty ||
                DateTime.tryParse(starts.text) == null
            ? null
            : () => Navigator.pop(context, {
                'course_id': courseId,
                'title': title.text.trim(),
                'starts_at': DateTime.parse(
                  starts.text,
                ).toUtc().toIso8601String(),
                'visibility': 'course',
              }),
        child: const Text('Create'),
      ),
    ],
  );
}

class _AttendanceSessionDialog extends StatefulWidget {
  const _AttendanceSessionDialog();
  @override
  State<_AttendanceSessionDialog> createState() =>
      _AttendanceSessionDialogState();
}

class _AttendanceSessionDialogState extends State<_AttendanceSessionDialog> {
  int? courseId;
  final title = TextEditingController();
  final held = TextEditingController();
  @override
  void dispose() {
    title.dispose();
    held.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('New attendance session'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _CourseSelector(
          onSelected: (value) => setState(() => courseId = value),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: title,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Title'),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: held,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Held at'),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed:
            courseId == null ||
                title.text.trim().isEmpty ||
                DateTime.tryParse(held.text) == null
            ? null
            : () => Navigator.pop(context, {
                'course_id': courseId,
                'title': title.text.trim(),
                'held_at': DateTime.parse(held.text).toUtc().toIso8601String(),
              }),
        child: const Text('Create'),
      ),
    ],
  );
}

class _AttendanceRecordDialog extends StatefulWidget {
  const _AttendanceRecordDialog({required this.courseId});
  final int courseId;
  @override
  State<_AttendanceRecordDialog> createState() =>
      _AttendanceRecordDialogState();
}

class _AttendanceRecordDialogState extends State<_AttendanceRecordDialog> {
  int? studentId;
  String status = 'present';

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Record attendance'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _StudentSelector(
          courseId: widget.courseId,
          onSelected: (value) => setState(() => studentId = value),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          initialValue: status,
          items: const ['present', 'absent', 'late', 'excused']
              .map(
                (value) => DropdownMenuItem(value: value, child: Text(value)),
              )
              .toList(),
          onChanged: (value) => status = value ?? status,
          decoration: const InputDecoration(labelText: 'Status'),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed: studentId == null
            ? null
            : () => Navigator.pop(context, {
                'student_id': studentId,
                'status': status,
              }),
        child: const Text('Save'),
      ),
    ],
  );
}

class _CourseSelector extends StatefulWidget {
  const _CourseSelector({this.label = 'Course', required this.onSelected});

  final String label;
  final ValueChanged<int?> onSelected;

  @override
  State<_CourseSelector> createState() => _CourseSelectorState();
}

class _CourseSelectorState extends State<_CourseSelector> {
  List<DropdownMenuEntry<int>> entries = const [];
  String? error;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    try {
      final payload = await context.read<ApiClient>().get(
        '${AppConfig.apiPrefix}/courses',
        query: {'per_page': 100},
      );
      entries = _rows(payload).map((course) {
        final id = (course['id'] as num).toInt();
        final code = course['code']?.toString().trim() ?? '';
        final name = course['name']?.toString() ?? 'Course $id';
        return DropdownMenuEntry(
          value: id,
          label: code.isEmpty ? name : '$code · $name',
        );
      }).toList();
    } on AppException catch (problem) {
      error = problem.friendlyMessage;
    }
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (error != null) {
      return Text(
        error!,
        style: TextStyle(color: Theme.of(context).colorScheme.error),
      );
    }
    if (entries.isEmpty) {
      return InputDecorator(
        decoration: InputDecoration(labelText: widget.label),
        child: const LinearProgressIndicator(),
      );
    }
    return DropdownMenu<int>(
      label: Text(widget.label),
      enableFilter: true,
      enableSearch: true,
      requestFocusOnTap: true,
      expandedInsets: EdgeInsets.zero,
      dropdownMenuEntries: entries,
      onSelected: widget.onSelected,
    );
  }
}

class _StudentSelector extends StatefulWidget {
  const _StudentSelector({required this.courseId, required this.onSelected});

  final int courseId;
  final ValueChanged<int?> onSelected;

  @override
  State<_StudentSelector> createState() => _StudentSelectorState();
}

class _StudentSelectorState extends State<_StudentSelector> {
  List<DropdownMenuEntry<int>> entries = const [];
  String? error;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    try {
      final payload = await context.read<ApiClient>().get(
        '${AppConfig.apiPrefix}/enrollments',
        query: {'course_id': widget.courseId, 'per_page': 100},
      );
      entries = _rows(payload)
          .where((enrollment) => enrollment['status'] == 'active')
          .map((enrollment) {
            final student = Map<String, dynamic>.from(
              enrollment['student'] as Map,
            );
            final id = (student['id'] as num).toInt();
            final name = student['name']?.toString() ?? 'Student $id';
            final email = student['email']?.toString() ?? '';
            return DropdownMenuEntry(
              value: id,
              label: email.isEmpty ? name : '$name · $email',
            );
          })
          .toList();
    } on AppException catch (problem) {
      error = problem.friendlyMessage;
    }
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (error != null) {
      return Text(
        error!,
        style: TextStyle(color: Theme.of(context).colorScheme.error),
      );
    }
    if (entries.isEmpty) {
      return const InputDecorator(
        decoration: InputDecoration(labelText: 'Student'),
        child: LinearProgressIndicator(),
      );
    }
    return DropdownMenu<int>(
      label: const Text('Student'),
      enableFilter: true,
      enableSearch: true,
      requestFocusOnTap: true,
      expandedInsets: EdgeInsets.zero,
      dropdownMenuEntries: entries,
      onSelected: widget.onSelected,
    );
  }
}

List<Map<String, dynamic>> _rows(dynamic payload) {
  final root = payload is Map ? payload['data'] : null;
  final rows = root is Map ? root['data'] : root;
  return (rows as List? ?? const [])
      .whereType<Map>()
      .map((row) => Map<String, dynamic>.from(row))
      .toList();
}

String _label(String key) => key
    .split('_')
    .map(
      (part) =>
          part.isEmpty ? '' : '${part[0].toUpperCase()}${part.substring(1)}',
    )
    .join(' ');

String _typeName(String type) => switch (type) {
  'courses' => 'Course',
  'assignments' => 'Assignment',
  'submissions' => 'Submission',
  'grades' => 'Grade',
  _ => 'Details',
};

String? _text(dynamic value) {
  if (value == null || value is Map || value is List) return null;
  final text = value.toString().trim();
  return text.isEmpty ? null : text;
}

DateTime? _parseDate(dynamic value) {
  final text = _text(value);
  // Only ISO timestamps from the API, not plain numbers or words.
  if (text == null || !RegExp(r'^\d{4}-\d{2}-\d{2}').hasMatch(text)) {
    return null;
  }
  return DateTime.tryParse(text)?.toLocal();
}

String? _date(dynamic value) {
  final date = _parseDate(value);
  return date == null ? null : DateFormat('MMM d, y · h:mm a').format(date);
}

bool _isPast(dynamic value) =>
    _parseDate(value)?.isBefore(DateTime.now()) ?? false;

String? _number(dynamic value) {
  final number = num.tryParse('${value ?? ''}');
  if (number == null) return null;
  if (number == number.roundToDouble()) return number.toInt().toString();
  return number
      .toStringAsFixed(2)
      .replaceFirst(RegExp(r'0+$'), '')
      .replaceFirst(RegExp(r'\.$'), '');
}

String _percent(dynamic value) => '${_number(value) ?? '0'}%';

String _fileSize(dynamic value) {
  final bytes = num.tryParse('${value ?? ''}');
  if (bytes == null) return '';
  if (bytes < 1024) return '${bytes.toInt()} B';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}

String _filesSummary(dynamic files) {
  final count = files is List ? files.length : 0;
  return count == 0 ? '' : '$count file${count == 1 ? '' : 's'}';
}

List<Map<String, dynamic>> _list(dynamic value) => value is List
    ? value
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList()
    : const [];
