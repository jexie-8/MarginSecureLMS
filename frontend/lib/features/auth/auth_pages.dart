import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/app_config.dart';
import '../../core/app_strings.dart';
import '../../core/auth/session_controller.dart';
import '../../core/errors/app_exception.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/theme_controller.dart';
import '../../shared/app_widgets.dart';

class _AuthFrame extends StatelessWidget {
  const _AuthFrame({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Row(
      children: [
        if (MediaQuery.sizeOf(context).width >= 850)
          Expanded(
            flex: 5,
            child: Container(
              color: const Color(0xFF0B2039),
              padding: const EdgeInsets.symmetric(horizontal: 64, vertical: 42),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const MarginLogo(light: true),
                  const Spacer(),
                  const Text(
                    'SECURE ACADEMIC ACCESS',
                    style: TextStyle(
                      color: Color(0xFF70C6BC),
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.4,
                    ),
                  ),
                  const SizedBox(height: 18),
                  const Text(
                    AppStrings.tagline,
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 42,
                      fontWeight: FontWeight.w700,
                      height: 1.15,
                    ),
                  ),
                  const SizedBox(height: 20),
                  const Text(
                    'Access coursework, submit assignments, and stay connected with your university—all in one secure place.',
                    style: TextStyle(
                      color: Color(0xFFAEBECD),
                      height: 1.65,
                      fontSize: 14,
                    ),
                  ),
                  const Spacer(),
                ],
              ),
            ),
          ),
        Expanded(
          flex: 6,
          child: Stack(
            children: [
              Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(28),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 420),
                    child: child,
                  ),
                ),
              ),
              Positioned(
                right: 18,
                top: 18,
                child: Consumer<ThemeController>(
                  builder: (context, theme, _) => IconButton(
                    tooltip: 'Change theme',
                    onPressed: () => theme.setMode(
                      theme.mode == ThemeMode.dark
                          ? ThemeMode.light
                          : ThemeMode.dark,
                    ),
                    icon: Icon(
                      theme.mode == ThemeMode.dark
                          ? Icons.light_mode_outlined
                          : Icons.dark_mode_outlined,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _submitting) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      final mfaRequired = await context.read<SessionController>().login(
        _email.text,
        _password.text,
      );
      if (mfaRequired && mounted) {
        final verified = await showDialog<bool>(
          context: context,
          barrierDismissible: false,
          builder: (_) => const _MfaChallengeDialog(),
        );
        if (verified != true) return;
      }
      if (mounted) context.go('/app/dashboard');
    } on AppException catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error.kind == AppErrorKind.rateLimited
            ? 'Too many sign-in attempts. Wait a minute and try again.'
            : error.kind == AppErrorKind.offline ||
                  error.kind == AppErrorKind.timeout
            ? AppStrings.offline
            : AppStrings.genericLoginError;
      });
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  InputDecoration _loginInput({
    required String label,
    required IconData icon,
    Widget? suffix,
  }) {
    final colors = Theme.of(context).colorScheme;
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: BorderSide(color: colors.outlineVariant),
    );
    return InputDecoration(
      labelText: label,
      floatingLabelBehavior: FloatingLabelBehavior.auto,
      filled: true,
      fillColor: colors.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 19),
      prefixIconConstraints: const BoxConstraints(minWidth: 62, minHeight: 54),
      prefixIcon: Padding(
        padding: const EdgeInsets.all(8),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: colors.primaryContainer,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(icon, color: colors.primary, size: 21),
        ),
      ),
      suffixIcon: suffix,
      border: border,
      enabledBorder: border,
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: colors.primary, width: 2),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: colors.error),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: colors.error, width: 2),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionController>();
    final expired = session.notice == 'session_expired';
    return _AuthFrame(
      child: Form(
        key: _formKey,
        child: AutofillGroup(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (MediaQuery.sizeOf(context).width < 850) ...[
                const Align(
                  alignment: Alignment.centerLeft,
                  child: MarginLogo(),
                ),
                const SizedBox(height: 48),
              ],
              Text(
                'Welcome back',
                style: Theme.of(context).textTheme.headlineLarge?.copyWith(
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.7,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Enter your university credentials to continue.',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
              if (expired) ...[
                const SizedBox(height: 18),
                _InlineMessage(
                  message: AppStrings.sessionExpired,
                  icon: Icons.schedule_outlined,
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 18),
                _InlineMessage(
                  message: _error!,
                  icon: Icons.error_outline,
                  error: true,
                ),
              ],
              const SizedBox(height: 26),
              TextFormField(
                controller: _email,
                autofillHints: const [
                  AutofillHints.username,
                  AutofillHints.email,
                ],
                keyboardType: TextInputType.emailAddress,
                style: const TextStyle(fontSize: 15),
                decoration: _loginInput(
                  label: 'University email',
                  icon: Icons.alternate_email_rounded,
                ),
                validator: (value) => value == null || !value.contains('@')
                    ? 'Enter a valid university email.'
                    : null,
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _password,
                autofillHints: const [AutofillHints.password],
                obscureText: _obscure,
                onFieldSubmitted: (_) => _submit(),
                style: const TextStyle(fontSize: 15),
                decoration: _loginInput(
                  label: 'Password',
                  icon: Icons.lock_outline_rounded,
                  suffix: IconButton(
                    tooltip: _obscure ? 'Show password' : 'Hide password',
                    onPressed: () => setState(() => _obscure = !_obscure),
                    icon: Icon(
                      _obscure
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                  ),
                ),
                validator: (value) => value == null || value.isEmpty
                    ? 'Enter your password.'
                    : null,
              ),
              const SizedBox(height: 10),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  onPressed: _submitting
                      ? null
                      : () => context.go('/forgot-password'),
                  child: const Text('Forgot password?'),
                ),
              ),
              const SizedBox(height: 10),
              ElevatedButton(
                onPressed: _submitting ? null : _submit,
                style: ElevatedButton.styleFrom(
                  minimumSize: const Size.fromHeight(54),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: _submitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Sign in'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MfaChallengeDialog extends StatefulWidget {
  const _MfaChallengeDialog();
  @override
  State<_MfaChallengeDialog> createState() => _MfaChallengeDialogState();
}

class _MfaChallengeDialogState extends State<_MfaChallengeDialog> {
  final code = TextEditingController();
  bool busy = false;
  String? error;
  @override
  void dispose() {
    code.dispose();
    super.dispose();
  }

  Future<void> verify() async {
    if (code.text.trim().isEmpty || busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await context.read<SessionController>().verifyMfa(code.text);
      if (mounted) Navigator.pop(context, true);
    } on AppException catch (exception) {
      if (mounted) setState(() => error = exception.friendlyMessage);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Security verification'),
    content: SizedBox(
      width: 380,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Text(
            'Enter the 6-digit authenticator code or a recovery code.',
          ),
          const SizedBox(height: 16),
          TextField(
            controller: code,
            autofocus: true,
            onSubmitted: (_) => verify(),
            decoration: InputDecoration(
              labelText: 'Verification code',
              errorText: error,
            ),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: busy ? null : () => Navigator.pop(context, false),
        child: const Text('Cancel'),
      ),
      ElevatedButton(
        onPressed: busy ? null : verify,
        child: busy
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Text('Verify'),
      ),
    ],
  );
}

class ForgotPasswordPage extends StatefulWidget {
  const ForgotPasswordPage({super.key});
  @override
  State<ForgotPasswordPage> createState() => _ForgotPasswordPageState();
}

class _ForgotPasswordPageState extends State<ForgotPasswordPage> {
  final _email = TextEditingController();
  bool _busy = false;
  bool _sent = false;
  String? _error;
  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_email.text.contains('@')) {
      setState(() => _error = 'Enter a valid university email.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/forgot-password',
        body: {'email': _email.text.trim()},
      );
      if (mounted) setState(() => _sent = true);
    } catch (error) {
      if (mounted)
        setState(
          () => _error = error is AppException
              ? error.friendlyMessage
              : AppStrings.genericError,
        );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => _AuthFrame(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Icon(
          _sent ? Icons.mark_email_read_outlined : Icons.lock_reset_outlined,
          size: 46,
          color: Theme.of(context).colorScheme.primary,
        ),
        const SizedBox(height: 20),
        Text(
          _sent ? 'Check your email' : 'Forgot your password?',
          textAlign: TextAlign.center,
          style: Theme.of(
            context,
          ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        Text(
          _sent
              ? 'If an active account matches that address, reset instructions will arrive shortly.'
              : 'Enter your email and we’ll send secure reset instructions.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
            height: 1.5,
          ),
        ),
        if (!_sent) ...[
          const SizedBox(height: 26),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: 'University email',
              prefixIcon: const Icon(Icons.mail_outline),
              errorText: _error,
            ),
          ),
          const SizedBox(height: 18),
          ElevatedButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Send reset link'),
          ),
        ],
        const SizedBox(height: 16),
        OutlinedButton(
          onPressed: () => context.go('/login'),
          child: const Text('Return to sign in'),
        ),
      ],
    ),
  );
}

class ResetPasswordPage extends StatefulWidget {
  const ResetPasswordPage({
    super.key,
    required this.token,
    required this.email,
  });
  final String token;
  final String email;
  @override
  State<ResetPasswordPage> createState() => _ResetPasswordPageState();
}

class _ResetPasswordPageState extends State<ResetPasswordPage> {
  late final _email = TextEditingController(text: widget.email);
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _busy = false;
  bool _done = false;
  String? _error;
  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (widget.token.isEmpty) {
      setState(() => _error = 'This reset link is incomplete or invalid.');
      return;
    }
    if (_password.text.length < 12 || _password.text != _confirmation.text) {
      setState(
        () => _error =
            'Use at least 12 characters and make both passwords match.',
      );
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<ApiClient>().post(
        '${AppConfig.apiPrefix}/reset-password',
        body: {
          'token': widget.token,
          'email': _email.text.trim(),
          'password': _password.text,
          'password_confirmation': _confirmation.text,
        },
      );
      if (mounted) setState(() => _done = true);
    } catch (error) {
      if (mounted)
        setState(
          () => _error = error is AppException
              ? error.friendlyMessage
              : AppStrings.genericError,
        );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => _AuthFrame(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Icon(
          _done ? Icons.check_circle_outline : Icons.password_outlined,
          size: 46,
          color: Theme.of(context).colorScheme.primary,
        ),
        const SizedBox(height: 18),
        Text(
          _done ? 'Password changed' : 'Create a new password',
          textAlign: TextAlign.center,
          style: Theme.of(
            context,
          ).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        Text(
          _done
              ? 'You can now sign in with your new password.'
              : 'Use 12+ characters with uppercase, lowercase, number, and symbol.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
        ),
        if (!_done) ...[
          const SizedBox(height: 24),
          TextField(
            controller: _email,
            decoration: const InputDecoration(labelText: 'University email'),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _password,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'New password'),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _confirmation,
            obscureText: true,
            decoration: InputDecoration(
              labelText: 'Confirm password',
              errorText: _error,
            ),
          ),
          const SizedBox(height: 18),
          ElevatedButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Reset password'),
          ),
        ] else ...[
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: () => context.go('/login'),
            child: const Text('Continue to sign in'),
          ),
        ],
      ],
    ),
  );
}

class _InlineMessage extends StatelessWidget {
  const _InlineMessage({
    required this.message,
    required this.icon,
    this.error = false,
  });
  final String message;
  final IconData icon;
  final bool error;
  @override
  Widget build(BuildContext context) {
    final color = error
        ? Theme.of(context).colorScheme.error
        : Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(9),
      ),
      child: Row(
        children: [
          Icon(icon, color: color, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(message, style: TextStyle(color: color, fontSize: 12)),
          ),
        ],
      ),
    );
  }
}
