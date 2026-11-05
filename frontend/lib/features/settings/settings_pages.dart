import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:file_picker/file_picker.dart';

import '../../core/app_config.dart';
import '../../core/auth/session_controller.dart';
import '../../core/errors/app_exception.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/theme_controller.dart';
import '../../shared/app_widgets.dart';

class ProfilePage extends StatelessWidget {
  const ProfilePage({super.key});

  Future<void> _signOut(BuildContext context) async {
    final session = context.read<SessionController>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Sign out?'),
        content: const Text(
          'Your locally cached course information will be cleared from this browser.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          ElevatedButton.icon(
            onPressed: () => Navigator.pop(dialogContext, true),
            icon: const Icon(Icons.logout),
            label: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (confirmed == true) await session.logout();
  }

  Future<void> _uploadAvatar(BuildContext context) async {
    final picked = await FilePicker.platform.pickFiles(
      type: FileType.image,
      withData: true,
    );
    if (picked == null || picked.files.single.bytes == null || !context.mounted)
      return;
    try {
      await context.read<ApiClient>().multipart(
        'POST',
        '${AppConfig.apiPrefix}/profile/avatar',
        files: [
          UploadPart(
            'avatar',
            picked.files.single.name,
            picked.files.single.bytes!,
          ),
        ],
      );
      if (context.mounted) {
        showSuccess(context, 'Profile photo updated.');
        await context.read<SessionController>().bootstrap();
      }
    } catch (error) {
      if (context.mounted) showFailure(context, error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionController>().user!;
    final profile = Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 32,
                  foregroundImage: user.avatarUrl == null
                      ? null
                      : NetworkImage(
                          '${AppConfig.apiOrigin}${Uri.parse(user.avatarUrl!).path}',
                        ),
                  child: Text(
                    _initials(user.name),
                    style: const TextStyle(
                      fontSize: 19,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'Change profile photo',
                  onPressed: () => _uploadAvatar(context),
                  icon: const Icon(Icons.add_a_photo_outlined),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        user.name,
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        user.email,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            StatusBadge(user.roleLabel),
            const Divider(height: 36),
            _Detail(
              'University ID',
              user.universityId?.toString() ?? 'Not assigned',
            ),
            _Detail(
              'Department ID',
              user.departmentId?.toString() ?? 'Not assigned',
            ),
          ],
        ),
      ),
    );

    final appearance = Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Consumer<ThemeController>(
          builder: (context, theme, _) => Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Appearance',
                style: Theme.of(
                  context,
                ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 6),
              Text(
                'Choose the theme used on this device.',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 20),
              SegmentedButton<ThemeMode>(
                segments: const [
                  ButtonSegment(
                    value: ThemeMode.system,
                    label: Text('System'),
                    icon: Icon(Icons.brightness_auto_outlined),
                  ),
                  ButtonSegment(
                    value: ThemeMode.light,
                    label: Text('Light'),
                    icon: Icon(Icons.light_mode_outlined),
                  ),
                  ButtonSegment(
                    value: ThemeMode.dark,
                    label: Text('Dark'),
                    icon: Icon(Icons.dark_mode_outlined),
                  ),
                ],
                selected: {theme.mode},
                onSelectionChanged: (values) => theme.setMode(values.first),
              ),
            ],
          ),
        ),
      ),
    );

    final account = Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Account',
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Text(
              'Update your password or securely end this session.',
              style: TextStyle(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 20),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                OutlinedButton.icon(
                  onPressed: () => showDialog(
                    context: context,
                    barrierDismissible: false,
                    builder: (_) => const ChangePasswordDialog(),
                  ),
                  icon: const Icon(Icons.password_outlined),
                  label: const Text('Reset password'),
                ),
                if (user.isAdministrator)
                  OutlinedButton.icon(
                    onPressed: () => showDialog(
                      context: context,
                      barrierDismissible: false,
                      builder: (_) => const MfaSetupDialog(),
                    ),
                    icon: const Icon(Icons.phonelink_lock_outlined),
                    label: Text(user.mfaEnabled ? 'Manage MFA' : 'Enable MFA'),
                  ),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Theme.of(context).colorScheme.error,
                  ),
                  onPressed: () => _signOut(context),
                  icon: const Icon(Icons.logout),
                  label: const Text('Sign out'),
                ),
              ],
            ),
          ],
        ),
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const PageHeader(
          title: 'Settings',
          description: 'Manage your profile, appearance, and account.',
        ),
        LayoutBuilder(
          builder: (context, constraints) => constraints.maxWidth >= 900
              ? Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: profile),
                    const SizedBox(width: 18),
                    Expanded(child: appearance),
                  ],
                )
              : Column(
                  children: [profile, const SizedBox(height: 18), appearance],
                ),
        ),
        const SizedBox(height: 18),
        account,
      ],
    );
  }

  String _initials(String name) => name
      .split(' ')
      .where((part) => part.isNotEmpty)
      .take(2)
      .map((part) => part[0].toUpperCase())
      .join();
}

class _Detail extends StatelessWidget {
  const _Detail(this.label, this.value);
  final String label, value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Row(
      children: [
        SizedBox(
          width: 130,
          child: Text(
            label,
            style: TextStyle(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
        ),
      ],
    ),
  );
}

class ChangePasswordDialog extends StatefulWidget {
  const ChangePasswordDialog({super.key});

  @override
  State<ChangePasswordDialog> createState() => _ChangePasswordDialogState();
}

class _ChangePasswordDialogState extends State<ChangePasswordDialog> {
  final current = TextEditingController();
  final password = TextEditingController();
  final confirmation = TextEditingController();
  bool busy = false;
  String? error;

  @override
  void dispose() {
    current.dispose();
    password.dispose();
    confirmation.dispose();
    super.dispose();
  }

  Future<void> save() async {
    if (current.text.isEmpty) {
      setState(() => error = 'Enter your current password.');
      return;
    }
    if (password.text.length < 12 || password.text != confirmation.text) {
      setState(
        () => error =
            'Use at least 12 characters and make both new passwords match.',
      );
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await context.read<ApiClient>().put(
        '${AppConfig.apiPrefix}/password',
        body: {
          'current_password': current.text,
          'password': password.text,
          'password_confirmation': confirmation.text,
        },
      );
      if (mounted) {
        Navigator.pop(context);
        showSuccess(
          context,
          'Password changed. Other sessions were signed out.',
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
  Widget build(BuildContext context) => PopScope(
    canPop: !busy,
    child: AlertDialog(
      title: const Text('Reset password'),
      content: SizedBox(
        width: 520,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Updating your password signs out your other active sessions.',
              style: TextStyle(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 18),
            TextField(
              controller: current,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Current password'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: password,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: 'New password',
                helperText:
                    '12+ characters with mixed case, number, and symbol',
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: confirmation,
              obscureText: true,
              decoration: InputDecoration(
                labelText: 'Confirm new password',
                errorText: error,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: busy ? null : () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: busy ? null : save,
          child: busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Update password'),
        ),
      ],
    ),
  );
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}

class MfaSetupDialog extends StatefulWidget {
  const MfaSetupDialog({super.key});
  @override
  State<MfaSetupDialog> createState() => _MfaSetupDialogState();
}

class _MfaSetupDialogState extends State<MfaSetupDialog> {
  String? secret;
  String? uri;
  List<String> recovery = [];
  final code = TextEditingController();
  bool busy = false;
  String? error;
  @override
  void initState() {
    super.initState();
    _setup();
  }

  @override
  void dispose() {
    code.dispose();
    super.dispose();
  }

  Future<void> _setup() async {
    try {
      final json = await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/mfa/setup',
      );
      if (mounted)
        setState(() {
          secret = (json as Map)['secret']?.toString();
          uri = json['otpauth_url']?.toString();
        });
    } on AppException catch (e) {
      if (mounted) setState(() => error = e.friendlyMessage);
    }
  }

  Future<void> _confirm() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final json = await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/mfa/confirm',
        body: {'code': code.text.trim()},
      );
      if (mounted)
        setState(
          () => recovery = ((json as Map)['recovery_codes'] as List? ?? [])
              .map((e) => e.toString())
              .toList(),
        );
    } on AppException catch (e) {
      if (mounted) setState(() => error = e.friendlyMessage);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Admin multi-factor authentication'),
    content: SizedBox(
      width: 560,
      child: recovery.isNotEmpty
          ? Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'MFA is enabled. Save these one-time recovery codes somewhere secure.',
                ),
                const SizedBox(height: 12),
                SelectableText(
                  recovery.join('\n'),
                  style: const TextStyle(fontFamily: 'monospace'),
                ),
              ],
            )
          : Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Add this secret or setup address to your authenticator app, then enter its 6-digit code.',
                ),
                const SizedBox(height: 12),
                if (secret == null && error == null)
                  const Center(child: CircularProgressIndicator())
                else ...[
                  SelectableText('Secret: ${secret ?? 'Unavailable'}'),
                  if (uri != null) SelectableText(uri!),
                  const SizedBox(height: 14),
                  TextField(
                    controller: code,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: '6-digit code',
                      errorText: error,
                    ),
                  ),
                ],
              ],
            ),
    ),
    actions: [
      if (recovery.isEmpty)
        ElevatedButton(
          onPressed: busy || secret == null ? null : _confirm,
          child: busy
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Enable MFA'),
        ),
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(recovery.isEmpty ? 'Cancel' : 'Done'),
      ),
    ],
  );
}
