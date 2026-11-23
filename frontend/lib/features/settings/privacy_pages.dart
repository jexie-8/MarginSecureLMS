import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/app_config.dart';
import '../../core/errors/app_exception.dart';
import '../../core/network/api_client.dart';

/// Shows the public privacy notice. Safe to open before signing in.
class PrivacyNoticeDialog extends StatefulWidget {
  const PrivacyNoticeDialog({super.key});

  @override
  State<PrivacyNoticeDialog> createState() => _PrivacyNoticeDialogState();
}

class _PrivacyNoticeDialogState extends State<PrivacyNoticeDialog> {
  Map<String, dynamic>? notice;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final json = await context.read<ApiClient>().get(
        '${AppConfig.apiPrefix}/privacy-notice',
      );
      if (mounted) setState(() => notice = json as Map<String, dynamic>);
    } on AppException catch (exception) {
      if (mounted) setState(() => error = exception.friendlyMessage);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sections = (notice?['sections'] as List?) ?? const [];
    return AlertDialog(
      title: const Text('Privacy notice'),
      content: SizedBox(
        width: 560,
        child: error != null
            ? Text(error!)
            : notice == null
            ? const Center(
                heightFactor: 2,
                child: CircularProgressIndicator(),
              )
            : SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Data controller: ${notice!['controller']}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    for (final section in sections.cast<Map>()) ...[
                      const SizedBox(height: 16),
                      Text(
                        '${section['title']}',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      const SizedBox(height: 4),
                      Text('${section['body']}'),
                    ],
                    const SizedBox(height: 16),
                    Text(
                      'Privacy contact: ${notice!['contact_email']}\n'
                      'Last updated: ${notice!['updated_at']}',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Close'),
        ),
      ],
    );
  }
}

/// Lets a signed-in user download a copy of their own data. Requires the
/// account password again, because the export contains personal information.
class ExportDataDialog extends StatefulWidget {
  const ExportDataDialog({super.key});

  @override
  State<ExportDataDialog> createState() => _ExportDataDialogState();
}

class _ExportDataDialogState extends State<ExportDataDialog> {
  final password = TextEditingController();
  bool busy = false;
  String? error;
  String? exported;

  @override
  void dispose() {
    password.dispose();
    super.dispose();
  }

  Future<void> _export() async {
    if (password.text.isEmpty) {
      setState(() => error = 'Enter your password to continue.');
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final json = await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/profile/export',
        body: {'password': password.text},
      );
      password.clear();
      if (mounted) {
        setState(
          () => exported = const JsonEncoder.withIndent('  ').convert(json),
        );
      }
    } on AppException catch (exception) {
      if (mounted) {
        setState(
          () => error =
              exception.fieldErrors.values.expand((item) => item).firstOrNull ??
              exception.friendlyMessage,
        );
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Download my data'),
    content: SizedBox(
      width: 560,
      child: exported == null
          ? Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'You will get a copy of the personal data this platform '
                  'holds about you: profile, enrolments, submissions, '
                  'published grades, attendance, notifications and your '
                  'recent security activity.',
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: password,
                  obscureText: true,
                  autofillHints: const [AutofillHints.password],
                  decoration: InputDecoration(
                    labelText: 'Confirm your password',
                    errorText: error,
                  ),
                  onSubmitted: (_) => busy ? null : _export(),
                ),
              ],
            )
          : Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Your data is below. Copy it somewhere safe, then close this '
                  'window; it is not stored on this device.',
                ),
                const SizedBox(height: 12),
                Container(
                  height: 260,
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    border: Border.all(
                      color: Theme.of(context).colorScheme.outlineVariant,
                    ),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: SingleChildScrollView(
                    child: SelectableText(
                      exported!,
                      style: const TextStyle(
                        fontFamily: 'monospace',
                        fontSize: 12,
                      ),
                    ),
                  ),
                ),
              ],
            ),
    ),
    actions: [
      TextButton(
        onPressed: busy ? null : () => Navigator.pop(context),
        child: const Text('Close'),
      ),
      if (exported == null)
        FilledButton(
          onPressed: busy ? null : _export,
          child: Text(busy ? 'Preparing…' : 'Download'),
        )
      else
        FilledButton.icon(
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: exported!));
            if (context.mounted) {
              ScaffoldMessenger.of(
                context,
              ).showSnackBar(const SnackBar(content: Text('Copied.')));
            }
          },
          icon: const Icon(Icons.copy),
          label: const Text('Copy'),
        ),
    ],
  );
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
