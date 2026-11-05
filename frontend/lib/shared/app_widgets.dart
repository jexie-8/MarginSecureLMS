import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../core/errors/app_exception.dart';
import '../core/theme/app_theme.dart';

class MarginLogo extends StatelessWidget {
  const MarginLogo({super.key, this.compact = false, this.light = false});
  final bool compact;
  final bool light;

  @override
  Widget build(BuildContext context) {
    final color = light
        ? Colors.white
        : Theme.of(context).colorScheme.onSurface;
    final logo = Image.asset(
      'assets/images/margin_lms_logo.png',
      fit: BoxFit.contain,
      filterQuality: FilterQuality.high,
      semanticLabel: 'Margin LMS logo',
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 58,
          height: 48,
          child: light
              ? ColorFiltered(
                  colorFilter: const ColorFilter.mode(
                    Color(0xFF70D5C9),
                    BlendMode.srcIn,
                  ),
                  child: logo,
                )
              : logo,
        ),
        if (!compact) ...[
          const SizedBox(width: 10),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'Margin',
                style: TextStyle(
                  color: color,
                  fontSize: 19,
                  fontWeight: FontWeight.w700,
                ),
              ),
              Text(
                'LMS',
                style: TextStyle(
                  color: light ? const Color(0xFF88B9DA) : AppColors.blue,
                  fontSize: 10,
                  letterSpacing: 1.4,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

class PageHeader extends StatelessWidget {
  const PageHeader({
    super.key,
    required this.title,
    required this.description,
    this.eyebrow,
    this.action,
  });
  final String title;
  final String description;
  final String? eyebrow;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final content = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (eyebrow != null)
          Text(
            eyebrow!.toUpperCase(),
            style: TextStyle(
              color: Theme.of(context).colorScheme.secondary,
              fontSize: 11,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.2,
            ),
          ),
        if (eyebrow != null) const SizedBox(height: 7),
        Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 7),
        Text(
          description,
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
    return LayoutBuilder(
      builder: (context, constraints) => Padding(
        padding: const EdgeInsets.only(bottom: 24),
        child: constraints.maxWidth < 600 || action == null
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  content,
                  if (action != null) ...[const SizedBox(height: 16), action!],
                ],
              )
            : Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Expanded(child: content),
                  const SizedBox(width: 20),
                  action!,
                ],
              ),
      ),
    );
  }
}

class StatCard extends StatelessWidget {
  const StatCard({
    super.key,
    required this.label,
    required this.value,
    required this.icon,
    this.note,
  });
  final String label;
  final String value;
  final IconData icon;
  final String? note;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.primaryContainer,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: Theme.of(context).colorScheme.primary),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  value,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                if (note != null)
                  Text(
                    note!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

class StatusBadge extends StatelessWidget {
  const StatusBadge(this.value, {super.key});
  final String value;

  @override
  Widget build(BuildContext context) {
    final lower = value.toLowerCase();
    final color =
        lower.contains('active') ||
            lower.contains('published') ||
            lower.contains('graded')
        ? AppColors.success
        : lower.contains('late') ||
              lower.contains('pending') ||
              lower.contains('draft')
        ? AppColors.warning
        : lower.contains('suspend') ||
              lower.contains('missing') ||
              lower.contains('inactive')
        ? AppColors.error
        : Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        value,
        style: TextStyle(
          color: color,
          fontSize: 11,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class FeedbackView extends StatelessWidget {
  const FeedbackView({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });
  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  factory FeedbackView.error(
    AppException error, {
    VoidCallback? onRetry,
  }) => FeedbackView(
    icon:
        error.kind == AppErrorKind.offline || error.kind == AppErrorKind.timeout
        ? Icons.cloud_off_outlined
        : Icons.error_outline,
    title:
        error.kind == AppErrorKind.offline || error.kind == AppErrorKind.timeout
        ? 'Connection unavailable'
        : 'Unable to load this page',
    message: error.friendlyMessage,
    actionLabel: onRetry == null ? null : 'Try again',
    onAction: onRetry,
  );

  @override
  Widget build(BuildContext context) => Center(
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 430),
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.primaryContainer,
                shape: BoxShape.circle,
              ),
              child: Icon(
                icon,
                color: Theme.of(context).colorScheme.primary,
                size: 28,
              ),
            ),
            const SizedBox(height: 18),
            Text(
              title,
              textAlign: TextAlign.center,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
                height: 1.5,
              ),
            ),
            if (onAction != null) ...[
              const SizedBox(height: 20),
              ElevatedButton.icon(
                onPressed: onAction,
                icon: const Icon(Icons.refresh),
                label: Text(actionLabel ?? 'Retry'),
              ),
            ],
          ],
        ),
      ),
    ),
  );
}

class SkeletonBox extends StatefulWidget {
  const SkeletonBox({super.key, this.height = 16, this.width, this.radius = 8});
  final double height;
  final double? width;
  final double radius;

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  )..repeat();
  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.disableAnimationsOf(context)) {
      return Container(
        width: widget.width,
        height: widget.height,
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(widget.radius),
        ),
      );
    }
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final base = Theme.of(context).colorScheme.surfaceContainerHighest;
        final highlight = Theme.of(context).colorScheme.surface;
        return Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(widget.radius),
            gradient: LinearGradient(
              begin: Alignment(-1.5 + _controller.value * 3, 0),
              end: Alignment(-.5 + _controller.value * 3, 0),
              colors: [base, highlight, base],
            ),
          ),
        );
      },
    );
  }
}

class PageSkeleton extends StatelessWidget {
  const PageSkeleton({super.key, this.rows = 5});
  final int rows;
  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const SkeletonBox(width: 210, height: 28),
      const SizedBox(height: 10),
      const SkeletonBox(width: 340),
      const SizedBox(height: 24),
      for (var i = 0; i < rows; i++) ...[
        const SkeletonBox(height: 58, radius: 10),
        const SizedBox(height: 10),
      ],
    ],
  );
}

class CachedBanner extends StatelessWidget {
  const CachedBanner({
    super.key,
    required this.savedAt,
    required this.refreshing,
  });
  final DateTime savedAt;
  final bool refreshing;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 14),
    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
    decoration: BoxDecoration(
      color: Theme.of(context).colorScheme.secondaryContainer,
      borderRadius: BorderRadius.circular(9),
    ),
    child: Row(
      children: [
        if (refreshing)
          const SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          )
        else
          const Icon(Icons.cloud_done_outlined, size: 18),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            '${refreshing ? 'Updating' : 'Saved'} · Last updated ${DateFormat.jm().format(savedAt)}',
            style: const TextStyle(fontSize: 12),
          ),
        ),
      ],
    ),
  );
}

void showSuccess(BuildContext context, String message) {
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Row(
        children: [
          const Icon(Icons.check_circle_outline, color: Colors.white),
          const SizedBox(width: 10),
          Expanded(child: Text(message)),
        ],
      ),
    ),
  );
}

void showFailure(BuildContext context, Object error) {
  final message = error is AppException
      ? error.friendlyMessage
      : 'Something went wrong. Please try again.';
  ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      backgroundColor: Theme.of(context).colorScheme.error,
      content: Row(
        children: [
          const Icon(Icons.error_outline, color: Colors.white),
          const SizedBox(width: 10),
          Expanded(
            child: Text(message, style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    ),
  );
}
