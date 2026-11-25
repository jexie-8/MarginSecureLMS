import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/app_config.dart';
import '../../core/network/api_client.dart';
import '../../shared/app_widgets.dart';

/// Lets a student submit a written response or files for [assignment].
/// Used by the assignment list and the assignment detail page.
/// Returns true when a submission was sent.
Future<bool> submitAssignmentWork(
  BuildContext context,
  Map<String, dynamic> assignment,
) async {
  final api = context.read<ApiClient>();
  final controller = TextEditingController();
  final title = assignment['title']?.toString().trim() ?? '';
  final content = await showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (context) => AlertDialog(
      title: Text(title.isEmpty ? 'Submit work' : 'Submit $title'),
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
  controller.dispose();
  if (content == null || !context.mounted) return false;
  final path =
      '${AppConfig.apiPrefix}/assignments/${assignment['id']}/submissions';
  try {
    if (content == '__FILES__') {
      final picked = await FilePicker.platform.pickFiles(
        allowMultiple: true,
        withData: true,
      );
      if (picked == null || picked.files.isEmpty) return false;
      await api.multipart(
        'POST',
        path,
        files: [
          for (final file in picked.files.where((file) => file.bytes != null))
            UploadPart('files[]', file.name, file.bytes!),
        ],
      );
    } else {
      if (content.isEmpty) return false;
      await api.post(path, body: {'content': content});
    }
    if (context.mounted) {
      showSuccess(context, 'Assignment submitted successfully.');
    }
    return true;
  } catch (e) {
    if (context.mounted) showFailure(context, e);
    return false;
  }
}
