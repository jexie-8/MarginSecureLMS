import 'package:flutter/material.dart';

import '../core/app_strings.dart';
import 'app_widgets.dart';

class SplashPage extends StatelessWidget {
  const SplashPage({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: const Color(0xFF0B2039),
    body: Semantics(
      label: 'Margin LMS is starting',
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const MarginLogo(light: true),
            const SizedBox(height: 32),
            SizedBox(
              width: 160,
              child: LinearProgressIndicator(
                minHeight: 3,
                borderRadius: BorderRadius.circular(4),
                color: const Color(0xFF55C9BE),
                backgroundColor: Colors.white12,
              ),
            ),
            const SizedBox(height: 14),
            const Text(
              'Preparing your secure workspace…',
              style: TextStyle(color: Color(0xFFAEBECD), fontSize: 12),
            ),
          ],
        ),
      ),
    ),
  );
}

class StartupErrorPage extends StatelessWidget {
  const StartupErrorPage({super.key, required this.onRetry});
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => Scaffold(
    body: FeedbackView(
      icon: Icons.cloud_off_outlined,
      title: 'Unable to start Margin LMS',
      message: AppStrings.offline,
      actionLabel: 'Try again',
      onAction: onRetry,
    ),
  );
}

class NotFoundPage extends StatelessWidget {
  const NotFoundPage({super.key});
  @override
  Widget build(BuildContext context) => const Scaffold(
    body: FeedbackView(
      icon: Icons.travel_explore_outlined,
      title: 'Page not found',
      message: 'The page may have moved or the address may be incorrect.',
    ),
  );
}

class AccessDeniedPage extends StatelessWidget {
  const AccessDeniedPage({super.key});
  @override
  Widget build(BuildContext context) => const FeedbackView(
    icon: Icons.lock_outline,
    title: 'Access denied',
    message: AppStrings.forbidden,
  );
}
