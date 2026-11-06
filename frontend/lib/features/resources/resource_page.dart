import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import 'package:file_picker/file_picker.dart';

import '../../core/app_config.dart';
import '../../core/app_strings.dart';
import '../../core/auth/session_controller.dart';
import '../../core/cache/app_cache.dart';
import '../../core/errors/app_exception.dart';
import '../../core/network/api_client.dart';
import '../../shared/app_widgets.dart';
import 'resource_catalog.dart';
import 'resource_repository.dart';

class ResourcePage extends StatefulWidget {
  const ResourcePage({super.key, required this.section, this.overrideConfig});
  final String section;
  final ResourceConfig? overrideConfig;
  @override
  State<ResourcePage> createState() => _ResourcePageState();
}

class _ResourcePageState extends State<ResourcePage> {
  late ResourceConfig config;
  late ResourceRepository repository;
  final _search = TextEditingController();
  Timer? _debounce;
  List<Map<String, dynamic>> items = [];
  Map<String, dynamic> meta = {};
  AppException? error;
  DateTime? cachedAt;
  bool loading = true;
  bool refreshing = false;
  int page = 1;

  @override
  void initState() {
    super.initState();
    config =
        widget.overrideConfig ?? ResourceCatalog.forSection(widget.section);
    final session = context.read<SessionController>();
    repository = ResourceRepository(
      context.read<ApiClient>(),
      context.read<AppCache>(),
      session.user!.id,
    );
    _search.addListener(_searchChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void didUpdateWidget(covariant ResourcePage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.section != widget.section ||
        oldWidget.overrideConfig != widget.overrideConfig) {
      config =
          widget.overrideConfig ?? ResourceCatalog.forSection(widget.section);
      page = 1;
      _search.clear();
      _load();
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _searchChanged() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), () {
      page = 1;
      _load();
    });
  }

  Map<String, dynamic> get _query => {
    'page': page,
    'per_page': 15,
    if (_search.text.trim().isNotEmpty) 'search': _search.text.trim(),
  };

  List<Map<String, dynamic>> get _visibleItems {
    final term = _search.text.trim().toLowerCase();
    if (term.isEmpty) return items;
    return items.where((item) => _searchable(item).contains(term)).toList();
  }

  String _searchable(dynamic value) {
    if (value is Map)
      return value.values.map(_searchable).join(' ').toLowerCase();
    if (value is List) return value.map(_searchable).join(' ').toLowerCase();
    return value?.toString().toLowerCase() ?? '';
  }

  Future<void> _load({bool force = false}) async {
    if (!mounted) return;
    final cached = force ? null : repository.cached(config.endpoint, _query);
    if (cached != null) {
      setState(() {
        items = cached.items;
        meta = cached.meta;
        cachedAt = cached.cachedAt;
        loading = false;
        refreshing = true;
        error = null;
      });
    } else {
      setState(() {
        loading = true;
        error = null;
      });
    }
    try {
      final result = await repository.list(config.endpoint, _query);
      if (!mounted) return;
      setState(() {
        items = result.items;
        meta = result.meta;
        cachedAt = null;
        loading = false;
        refreshing = false;
      });
    } on AppException catch (exception) {
      if (!mounted) return;
      setState(() {
        error = cached == null ? exception : null;
        loading = false;
        refreshing = false;
      });
    }
  }

  bool get _canWrite {
    final user = context.read<SessionController>().user!;
    if (user.isPlatformAdmin || user.isUniversityAdmin) return true;
    if (user.isDepartmentAdmin) {
      return config.endpoint == 'users' || config.endpoint == 'enrollments';
    }
    return user.isInstructor && config.endpoint == 'assignments';
  }

  Future<void> _edit([Map<String, dynamic>? item]) async {
    final saved = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => ResourceEditorDialog(config: config, existing: item),
    );
    if (saved == true && mounted) {
      showSuccess(
        context,
        item == null ? '${config.title} record created.' : 'Changes saved.',
      );
      await _load(force: true);
    }
  }

  Future<void> _view(Map<String, dynamic> item) async {
    final endpoint = config.endpoint == 'grading'
        ? 'submissions'
        : config.endpoint;
    if (const [
      'courses',
      'assignments',
      'submissions',
      'grades',
    ].contains(endpoint)) {
      context.go('/detail/$endpoint/${item['id']}');
      return;
    }
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(_display(item, config.primaryLabel)),
        content: SizedBox(
          width: 620,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: config.columns.entries
                  .map(
                    (entry) => Padding(
                      padding: const EdgeInsets.symmetric(vertical: 9),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          SizedBox(
                            width: 130,
                            child: Text(
                              entry.value,
                              style: TextStyle(
                                color: Theme.of(
                                  context,
                                ).colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                          Expanded(child: _valueWidget(item, entry.key)),
                        ],
                      ),
                    ),
                  )
                  .toList(),
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Future<void> _remove(Map<String, dynamic> item) async {
    final confirmed = await _confirm(
      'Remove assignment?',
      'This course staff assignment will be removed.',
    );
    if (!confirmed) return;
    try {
      await repository.remove(config.endpoint, (item['id'] as num).toInt());
      if (mounted) showSuccess(context, 'Assignment removed.');
      await _load(force: true);
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<bool> _confirm(String title, String message) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(title),
          content: Text(message),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Confirm'),
            ),
          ],
        ),
      ) ??
      false;

  Future<void> _publishAssignment(Map<String, dynamic> item) async {
    final api = context.read<ApiClient>();
    if (!await _confirm(
      'Publish assignment?',
      'Students will be able to view and submit this assignment.',
    ))
      return;
    try {
      await api.post(
        '${AppConfig.apiPrefix}/assignments/${item['id']}/publish',
      );
      if (mounted) showSuccess(context, 'Assignment published.');
      await _load(force: true);
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _submitAssignment(Map<String, dynamic> item) async {
    final api = context.read<ApiClient>();
    final controller = TextEditingController();
    final content = await showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: Text('Submit ${_plain(item['title'])}'),
        content: SizedBox(
          width: 520,
          child: TextField(
            controller: controller,
            minLines: 6,
            maxLines: 12,
            decoration: const InputDecoration(
              labelText: 'Written response',
              alignLabelWithHint: true,
              helperText:
                  'Your response is submitted securely to your instructor.',
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Submit work'),
          ),
          OutlinedButton.icon(
            onPressed: () => Navigator.pop(context, '__FILES__'),
            icon: const Icon(Icons.attach_file),
            label: const Text('Attach files'),
          ),
        ],
      ),
    );
    if (content == null) return;
    try {
      if (content == '__FILES__') {
        final picked = await FilePicker.platform.pickFiles(
          allowMultiple: true,
          withData: true,
        );
        if (picked == null || picked.files.isEmpty) return;
        await api.multipart(
          'POST',
          '${AppConfig.apiPrefix}/assignments/${item['id']}/submissions',
          files: [
            for (final file in picked.files.where((file) => file.bytes != null))
              UploadPart('files[]', file.name, file.bytes!),
          ],
        );
      } else {
        if (content.isEmpty) return;
        await api.post(
          '${AppConfig.apiPrefix}/assignments/${item['id']}/submissions',
          body: {'content': content},
        );
      }
      if (mounted) showSuccess(context, 'Assignment submitted successfully.');
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _grade(Map<String, dynamic> item) async {
    final api = context.read<ApiClient>();
    final existingGrade = item['grade'];
    final published =
        existingGrade is Map && existingGrade['published_at'] != null;
    final score = TextEditingController(text: _nested(item['grade'], 'score'));
    final feedback = TextEditingController(
      text: _nested(item['grade'], 'feedback'),
    );
    final reason = TextEditingController();
    final data = await showDialog<Map<String, dynamic>>(
      context: context,
      barrierDismissible: false,
      builder: (context) => AlertDialog(
        title: const Text('Grade submission'),
        content: SizedBox(
          width: 520,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: score,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Score'),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: feedback,
                minLines: 4,
                maxLines: 8,
                decoration: const InputDecoration(
                  labelText: 'Instructor feedback',
                  alignLabelWithHint: true,
                ),
              ),
              if (published) ...[
                const SizedBox(height: 14),
                TextField(
                  controller: reason,
                  minLines: 2,
                  maxLines: 4,
                  decoration: const InputDecoration(
                    labelText: 'Reason for changing the published grade',
                    helperText:
                        'Required. The previous and updated values will be retained in grade history.',
                    alignLabelWithHint: true,
                  ),
                ),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              final value = double.tryParse(score.text);
              if (value != null &&
                  value >= 0 &&
                  (!published || reason.text.trim().isNotEmpty)) {
                Navigator.pop(context, {
                  'score': value,
                  'feedback': feedback.text.trim(),
                  if (published) 'change_reason': reason.text.trim(),
                });
              }
            },
            child: const Text('Save grade'),
          ),
        ],
      ),
    );
    score.dispose();
    feedback.dispose();
    reason.dispose();
    if (data == null) return;
    try {
      await api.put(
        '${AppConfig.apiPrefix}/submissions/${item['id']}/grade',
        body: data,
      );
      if (mounted) showSuccess(context, 'Grade saved.');
      await _load(force: true);
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _publishGrade(Map<String, dynamic> item) async {
    final api = context.read<ApiClient>();
    final grade = item['grade'];
    if (grade is! Map || grade['id'] == null) return;
    if (!await _confirm(
      'Publish grade?',
      'The student will be able to view this score and feedback.',
    ))
      return;
    try {
      await api.post('${AppConfig.apiPrefix}/grades/${grade['id']}/publish');
      if (mounted) showSuccess(context, 'Grade published.');
      await _load(force: true);
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  Future<void> _changeEnrollment(Map<String, dynamic> item) async {
    final api = context.read<ApiClient>();
    final next = item['status'] == 'active' ? 'withdrawn' : 'active';
    if (!await _confirm(
      'Change enrollment status?',
      'The enrollment will be marked $next.',
    ))
      return;
    try {
      await api.patch(
        '${AppConfig.apiPrefix}/enrollments/${item['id']}',
        body: {'status': next},
      );
      if (mounted) showSuccess(context, 'Enrollment updated.');
      await _load(force: true);
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionController>().user!;
    final visibleItems = _visibleItems;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PageHeader(
          eyebrow: user.roleLabel,
          title: config.title,
          description: config.description,
          action: config.canCreate && _canWrite
              ? ElevatedButton.icon(
                  onPressed: () => _edit(),
                  icon: const Icon(Icons.add),
                  label: const Text('Create'),
                )
              : null,
        ),
        if (cachedAt != null)
          CachedBanner(savedAt: cachedAt!, refreshing: refreshing),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _search,
                        decoration: const InputDecoration(
                          prefixIcon: Icon(Icons.search),
                          hintText: 'Search',
                          isDense: true,
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    IconButton.filledTonal(
                      tooltip: 'Refresh',
                      onPressed: refreshing ? null : () => _load(force: true),
                      icon: refreshing
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.refresh),
                    ),
                  ],
                ),
                const SizedBox(height: 18),
                if (loading)
                  const PageSkeleton(rows: 6)
                else if (error != null)
                  SizedBox(
                    height: 430,
                    child: FeedbackView.error(
                      error!,
                      onRetry: () => _load(force: true),
                    ),
                  )
                else if (visibleItems.isEmpty)
                  SizedBox(
                    height: 360,
                    child: FeedbackView(
                      icon: Icons.inbox_outlined,
                      title: _search.text.isEmpty
                          ? 'Nothing here yet'
                          : 'No results found',
                      message: _search.text.isEmpty
                          ? AppStrings.noData
                          : AppStrings.noResults,
                      actionLabel: _search.text.isEmpty ? null : 'Clear search',
                      onAction: _search.text.isEmpty ? null : _search.clear,
                    ),
                  )
                else
                  _ResourceList(
                    config: config,
                    items: visibleItems,
                    userRole: user.role,
                    onView: _view,
                    onEdit: _canWrite && config.canEdit ? _edit : null,
                    onDelete: _canWrite && config.canDelete ? _remove : null,
                    onPublishAssignment:
                        user.isInstructor && config.endpoint == 'assignments'
                        ? _publishAssignment
                        : null,
                    onSubmit: user.isStudent && config.endpoint == 'assignments'
                        ? _submitAssignment
                        : null,
                    onGrade:
                        user.isInstructor && config.endpoint == 'submissions'
                        ? _grade
                        : null,
                    onPublishGrade:
                        user.isInstructor && config.endpoint == 'submissions'
                        ? _publishGrade
                        : null,
                    onEnrollmentStatus:
                        user.isAdministrator && config.endpoint == 'enrollments'
                        ? _changeEnrollment
                        : null,
                  ),
                if (!loading && error == null && items.isNotEmpty) ...[
                  const Divider(height: 28),
                  _Pagination(
                    meta: meta,
                    current: page,
                    onPage: (value) {
                      setState(() => page = value);
                      _load();
                    },
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _ResourceList extends StatelessWidget {
  const _ResourceList({
    required this.config,
    required this.items,
    required this.userRole,
    required this.onView,
    this.onEdit,
    this.onDelete,
    this.onPublishAssignment,
    this.onSubmit,
    this.onGrade,
    this.onPublishGrade,
    this.onEnrollmentStatus,
  });
  final ResourceConfig config;
  final List<Map<String, dynamic>> items;
  final String userRole;
  final ValueChanged<Map<String, dynamic>> onView;
  final ValueChanged<Map<String, dynamic>>? onEdit,
      onDelete,
      onPublishAssignment,
      onSubmit,
      onGrade,
      onPublishGrade,
      onEnrollmentStatus;

  List<Widget> _actions(Map<String, dynamic> item) => [
    IconButton(
      tooltip: 'View details',
      onPressed: () => onView(item),
      icon: const Icon(Icons.open_in_new, size: 19),
    ),
    if (onEdit != null)
      IconButton(
        tooltip: 'Edit',
        onPressed: () => onEdit!(item),
        icon: const Icon(Icons.edit_outlined, size: 19),
      ),
    if (onPublishAssignment != null && item['published_at'] == null)
      IconButton(
        tooltip: 'Publish',
        onPressed: () => onPublishAssignment!(item),
        icon: const Icon(Icons.publish_outlined, size: 19),
      ),
    if (onSubmit != null && item['published_at'] != null)
      IconButton(
        tooltip: 'Submit work',
        onPressed: () => onSubmit!(item),
        icon: const Icon(Icons.upload_file_outlined, size: 19),
      ),
    if (onGrade != null)
      IconButton(
        tooltip: 'Grade',
        onPressed: () => onGrade!(item),
        icon: const Icon(Icons.rate_review_outlined, size: 19),
      ),
    if (onPublishGrade != null &&
        item['grade'] is Map &&
        (item['grade'] as Map)['published_at'] == null)
      IconButton(
        tooltip: 'Publish grade',
        onPressed: () => onPublishGrade!(item),
        icon: const Icon(Icons.fact_check_outlined, size: 19),
      ),
    if (onEnrollmentStatus != null)
      IconButton(
        tooltip: 'Change status',
        onPressed: () => onEnrollmentStatus!(item),
        icon: const Icon(Icons.swap_horiz, size: 19),
      ),
    if (onDelete != null)
      IconButton(
        tooltip: 'Remove',
        onPressed: () => onDelete!(item),
        icon: const Icon(Icons.delete_outline, size: 19),
      ),
  ];

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      if (constraints.maxWidth < 760) {
        return Column(
          children: items
              .map(
                (item) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Container(
                    decoration: BoxDecoration(
                      border: Border.all(color: Theme.of(context).dividerColor),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _display(item, config.primaryLabel),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 10),
                        for (final entry in config.columns.entries)
                          if (entry.key != config.primaryLabel)
                            Padding(
                              padding: const EdgeInsets.only(bottom: 6),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  SizedBox(
                                    width: 95,
                                    child: Text(
                                      entry.value,
                                      style: TextStyle(
                                        fontSize: 11,
                                        color: Theme.of(
                                          context,
                                        ).colorScheme.onSurfaceVariant,
                                      ),
                                    ),
                                  ),
                                  Expanded(
                                    child: _valueWidget(item, entry.key),
                                  ),
                                ],
                              ),
                            ),
                        if (_actions(item).isNotEmpty)
                          Align(
                            alignment: Alignment.centerRight,
                            child: Wrap(children: _actions(item)),
                          ),
                      ],
                    ),
                  ),
                ),
              )
              .toList(),
        );
      }
      return SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columns: [
            ...config.columns.values.map(
              (label) => DataColumn(label: Text(label)),
            ),
            if (items.any((item) => _actions(item).isNotEmpty))
              const DataColumn(label: Text('Actions')),
          ],
          rows: items
              .map(
                (item) => DataRow(
                  cells: [
                    ...config.columns.keys.map(
                      (key) => DataCell(
                        ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 260),
                          child: _valueWidget(item, key),
                        ),
                      ),
                    ),
                    if (_actions(item).isNotEmpty)
                      DataCell(
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: _actions(item),
                        ),
                      ),
                  ],
                ),
              )
              .toList(),
        ),
      );
    },
  );
}

Widget _valueWidget(Map<String, dynamic> item, String key) {
  final value = _value(item, key);
  if (key.contains('status') || key == 'role' || key == 'published_at')
    return StatusBadge(
      key == 'published_at'
          ? (item[key] == null ? 'Draft' : 'Published')
          : value,
    );
  return Text(value, maxLines: 2, overflow: TextOverflow.ellipsis);
}

String _value(Map<String, dynamic> item, String key) {
  final raw = item[key];
  if (raw == null) return '—';
  if (raw is Map)
    return _display(
      Map<String, dynamic>.from(raw),
      raw.containsKey('name')
          ? 'name'
          : raw.containsKey('title')
          ? 'title'
          : 'score',
    );
  if (raw is List) return raw.map((value) => value.toString()).join(', ');
  if (key.endsWith('_at')) {
    final date = DateTime.tryParse(raw.toString())?.toLocal();
    if (date != null) return DateFormat('MMM d, y · h:mm a').format(date);
  }
  return raw.toString();
}

String _display(Map<String, dynamic> item, String key) => _plain(item[key]);
String _plain(dynamic value) =>
    value?.toString().trim().isNotEmpty == true ? value.toString() : '—';
String _nested(dynamic map, String key) => map is Map
    ? _plain(map[key]) == '—'
          ? ''
          : _plain(map[key])
    : '';

class _Pagination extends StatelessWidget {
  const _Pagination({
    required this.meta,
    required this.current,
    required this.onPage,
  });
  final Map<String, dynamic> meta;
  final int current;
  final ValueChanged<int> onPage;
  @override
  Widget build(BuildContext context) {
    final last = (meta['last_page'] as num?)?.toInt() ?? current;
    final total = (meta['total'] as num?)?.toInt() ?? 0;
    return Row(
      children: [
        Expanded(
          child: Text(
            '$total records',
            style: TextStyle(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        IconButton(
          onPressed: current > 1 ? () => onPage(current - 1) : null,
          icon: const Icon(Icons.chevron_left),
        ),
        Text('Page $current of $last'),
        IconButton(
          onPressed: current < last ? () => onPage(current + 1) : null,
          icon: const Icon(Icons.chevron_right),
        ),
      ],
    );
  }
}

class ResourceEditorDialog extends StatefulWidget {
  const ResourceEditorDialog({super.key, required this.config, this.existing});
  final ResourceConfig config;
  final Map<String, dynamic>? existing;
  @override
  State<ResourceEditorDialog> createState() => _ResourceEditorDialogState();
}

class _ResourceEditorDialogState extends State<ResourceEditorDialog> {
  final formKey = GlobalKey<FormState>();
  final controllers = <String, TextEditingController>{};
  final selected = <String, dynamic>{};
  final options = <String, List<Map<String, dynamic>>>{};
  bool loadingOptions = true;
  bool saving = false;
  String? formError;

  @override
  void initState() {
    super.initState();
    for (final field in widget.config.fields) {
      final value = widget.existing?[field.key];
      if (field.kind == FieldKind.choice ||
          field.kind == FieldKind.relation ||
          field.kind == FieldKind.multiRelation) {
        selected[field.key] = field.kind == FieldKind.multiRelation
            ? (value is List ? [...value] : <dynamic>[])
            : value;
      } else {
        controllers[field.key] = TextEditingController(
          text: field.kind == FieldKind.rubric && value is List
              ? value
                    .whereType<Map>()
                    .map(
                      (row) => '${row['name'] ?? ''}: ${row['points'] ?? ''}',
                    )
                    .join('\n')
              : value?.toString() ?? '',
        );
      }
    }
    _loadOptions();
  }

  Future<void> _loadOptions() async {
    final endpoints = widget.config.fields
        .map((field) => field.relationEndpoint)
        .whereType<String>()
        .toSet();
    try {
      for (final endpoint in endpoints) {
        final json = await context.read<ApiClient>().get(
          '${AppConfig.apiPrefix}/$endpoint',
          query: {'per_page': 100},
        );
        final data = (json as Map)['data'];
        options[endpoint] = data is List
            ? data
                  .whereType<Map>()
                  .map((e) => Map<String, dynamic>.from(e))
                  .toList()
            : [];
      }
    } catch (error) {
      formError = error is AppException
          ? error.friendlyMessage
          : AppStrings.genericError;
    }
    if (mounted) setState(() => loadingOptions = false);
  }

  @override
  void dispose() {
    for (final controller in controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!formKey.currentState!.validate() || saving) return;
    final data = <String, dynamic>{};
    for (final field in widget.config.fields) {
      dynamic value;
      if (controllers.containsKey(field.key))
        value = controllers[field.key]!.text.trim();
      else
        value = selected[field.key];
      if (field.kind == FieldKind.number && value.toString().isNotEmpty)
        value = num.tryParse(value.toString());
      if (field.kind == FieldKind.relation && value is num)
        value = value.toInt();
      if (field.kind == FieldKind.dateTime && value.toString().isNotEmpty) {
        final date = DateTime.tryParse(value.toString());
        if (date != null) value = date.toUtc().toIso8601String();
      }
      if (field.kind == FieldKind.rubric) {
        value = value
            .toString()
            .split('\n')
            .where((line) => line.trim().isNotEmpty)
            .map((line) {
              final parts = line.split(':');
              return {
                'name': parts.first.trim(),
                'points': parts.length > 1
                    ? num.tryParse(parts.sublist(1).join(':').trim()) ?? 0
                    : 0,
              };
            })
            .toList();
      }
      if (field.key == 'password' &&
          widget.existing != null &&
          value.toString().isEmpty)
        continue;
      if ((value == null || value.toString().isEmpty) && !field.required)
        value = null;
      data[field.key] = value;
    }
    setState(() {
      saving = true;
      formError = null;
    });
    try {
      final repository = ResourceRepository(
        context.read<ApiClient>(),
        context.read<AppCache>(),
        context.read<SessionController>().user!.id,
      );
      await repository.save(
        widget.config.endpoint,
        data,
        id: (widget.existing?['id'] as num?)?.toInt(),
      );
      if (mounted) Navigator.pop(context, true);
    } on AppException catch (error) {
      if (mounted)
        setState(
          () => formError =
              error.fieldErrors.values.expand((x) => x).firstOrNull ??
              error.friendlyMessage,
        );
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !saving,
    child: AlertDialog(
      title: Text(
        widget.existing == null
            ? 'Create ${widget.config.title.toLowerCase()}'
            : 'Edit ${widget.config.title.toLowerCase()}',
      ),
      content: SizedBox(
        width: 610,
        child: loadingOptions
            ? const SizedBox(
                height: 180,
                child: Center(child: CircularProgressIndicator()),
              )
            : Form(
                key: formKey,
                child: SingleChildScrollView(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (formError != null) ...[
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Theme.of(context).colorScheme.errorContainer,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            formError!,
                            style: TextStyle(
                              color: Theme.of(
                                context,
                              ).colorScheme.onErrorContainer,
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                      ],
                      for (final field in widget.config.fields)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 14),
                          child: _field(field),
                        ),
                    ],
                  ),
                ),
              ),
      ),
      actions: [
        TextButton(
          onPressed: saving ? null : () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: saving || loadingOptions ? null : _save,
          child: saving
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Save'),
        ),
      ],
    ),
  );

  Widget _field(ResourceField field) {
    String? validate(String? value) =>
        field.required && (value == null || value.trim().isEmpty)
        ? '${field.label} is required.'
        : null;
    if (field.kind == FieldKind.choice) {
      var choices = field.choices;
      if (field.key == 'role') {
        final actor = context.read<SessionController>().user!;
        choices = actor.isPlatformAdmin
            ? field.choices
            : actor.isUniversityAdmin
            ? const [
                'student',
                'teaching_assistant',
                'lecturer',
                'department_admin',
              ]
            : const ['student', 'teaching_assistant', 'lecturer'];
      }
      return DropdownButtonFormField<String>(
        initialValue: selected[field.key]?.toString(),
        decoration: InputDecoration(labelText: field.label),
        items: choices
            .map(
              (value) =>
                  DropdownMenuItem(value: value, child: Text(_title(value))),
            )
            .toList(),
        onChanged: (value) => selected[field.key] = value,
        validator: validate,
      );
    }
    if (field.kind == FieldKind.relation) {
      final list = options[field.relationEndpoint] ?? [];
      return DropdownButtonFormField<int>(
        initialValue: (selected[field.key] as num?)?.toInt(),
        decoration: InputDecoration(labelText: field.label),
        items: list
            .map(
              (item) => DropdownMenuItem(
                value: (item['id'] as num).toInt(),
                child: Text(
                  '${item['code'] != null ? '${item['code']} · ' : ''}${item[field.relationLabel] ?? item['name'] ?? item['email']}',
                ),
              ),
            )
            .toList(),
        onChanged: (value) => selected[field.key] = value,
        validator: (value) => field.required && value == null
            ? '${field.label} is required.'
            : null,
      );
    }
    if (field.kind == FieldKind.multiRelation) {
      final list = options[field.relationEndpoint] ?? [];
      final values = (selected[field.key] as List?) ?? <dynamic>[];
      return InputDecorator(
        decoration: InputDecoration(labelText: field.label),
        child: Wrap(
          spacing: 7,
          runSpacing: 5,
          children: list.map((item) {
            final id = (item['id'] as num).toInt();
            final active = values.contains(id);
            return FilterChip(
              label: Text(
                item['name']?.toString() ?? item['code']?.toString() ?? '$id',
              ),
              selected: active,
              onSelected: (on) => setState(() {
                if (on) {
                  values.add(id);
                } else {
                  values.remove(id);
                }
                selected[field.key] = values;
              }),
            );
          }).toList(),
        ),
      );
    }
    return TextFormField(
      controller: controllers[field.key],
      obscureText: field.kind == FieldKind.password,
      keyboardType: field.kind == FieldKind.number
          ? TextInputType.number
          : field.kind == FieldKind.email
          ? TextInputType.emailAddress
          : TextInputType.text,
      minLines:
          field.kind == FieldKind.multiline || field.kind == FieldKind.rubric
          ? 4
          : 1,
      maxLines:
          field.kind == FieldKind.multiline || field.kind == FieldKind.rubric
          ? 8
          : 1,
      decoration: InputDecoration(
        labelText: field.label,
        alignLabelWithHint: field.kind == FieldKind.multiline,
        helperText: field.kind == FieldKind.dateTime
            ? 'Example: 2026-11-30 17:00'
            : field.kind == FieldKind.rubric
            ? 'One criterion per line, for example: Analysis: 40'
            : field.kind == FieldKind.password && widget.existing != null
            ? 'Leave blank to keep the current password.'
            : null,
      ),
      validator: validate,
    );
  }
}

class AcademicStructurePage extends StatefulWidget {
  const AcademicStructurePage({super.key});
  @override
  State<AcademicStructurePage> createState() => _AcademicStructurePageState();
}

class _AcademicStructurePageState extends State<AcademicStructurePage> {
  int index = 0;
  final configs = const [
    ResourceCatalog.universities,
    ResourceCatalog.departments,
    ResourceCatalog.programs,
    ResourceCatalog.courses,
  ];
  @override
  Widget build(BuildContext context) => Column(
    children: [
      Align(
        alignment: Alignment.centerLeft,
        child: Wrap(
          spacing: 8,
          children: List.generate(
            configs.length,
            (i) => ChoiceChip(
              label: Text(configs[i].title),
              selected: index == i,
              onSelected: (_) => setState(() => index = i),
            ),
          ),
        ),
      ),
      const SizedBox(height: 18),
      ResourcePage(
        key: ValueKey(configs[index].key),
        section: configs[index].key,
        overrideConfig: configs[index],
      ),
    ],
  );
}

String _title(String value) => value
    .split('_')
    .map(
      (part) =>
          part.isEmpty ? '' : '${part[0].toUpperCase()}${part.substring(1)}',
    )
    .join(' ');

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
