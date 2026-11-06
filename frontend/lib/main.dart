import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_web_plugins/url_strategy.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'core/app_strings.dart';
import 'core/auth/session_controller.dart';
import 'core/cache/app_cache.dart';
import 'core/network/api_client.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/theme_controller.dart';
import 'features/auth/auth_pages.dart';
import 'features/shell/app_shell.dart';
import 'features/learning/learning_pages.dart';
import 'shared/state_pages.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  usePathUrlStrategy();
  final preferences = await SharedPreferences.getInstance();
  final api = ApiClient();
  final cache = AppCache(preferences);
  final session = SessionController(api, cache);
  final theme = ThemeController(preferences);
  runApp(MarginApp(session: session, theme: theme, cache: cache, api: api));
}

class MarginApp extends StatefulWidget {
  const MarginApp({
    super.key,
    required this.session,
    required this.theme,
    required this.cache,
    required this.api,
  });

  final SessionController session;
  final ThemeController theme;
  final AppCache cache;
  final ApiClient api;

  @override
  State<MarginApp> createState() => _MarginAppState();
}

class _MarginAppState extends State<MarginApp> {
  late final GoRouter _router = GoRouter(
    initialLocation: '/splash',
    refreshListenable: widget.session,
    redirect: (context, state) {
      final status = widget.session.state;
      final location = state.matchedLocation;
      if (status == SessionState.booting) {
        return location == '/splash' ? null : '/splash';
      }
      if (status == SessionState.startupError) {
        return location == '/startup-error' ? null : '/startup-error';
      }
      final isPublic =
          location == '/login' ||
          location == '/forgot-password' ||
          location == '/reset-password';
      if (status == SessionState.signedOut) {
        return isPublic ? null : '/login';
      }
      if (status == SessionState.signedIn &&
          (isPublic || location == '/splash' || location == '/startup-error')) {
        return '/app/dashboard';
      }
      return null;
    },
    errorBuilder: (_, __) => const NotFoundPage(),
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const SplashPage()),
      GoRoute(
        path: '/startup-error',
        builder: (_, __) => StartupErrorPage(onRetry: widget.session.bootstrap),
      ),
      GoRoute(path: '/login', builder: (_, __) => const LoginPage()),
      GoRoute(
        path: '/forgot-password',
        builder: (_, __) => const ForgotPasswordPage(),
      ),
      GoRoute(
        path: '/reset-password',
        builder: (_, state) => ResetPasswordPage(
          token: state.uri.queryParameters['token'] ?? '',
          email: state.uri.queryParameters['email'] ?? '',
        ),
      ),
      GoRoute(
        path: '/app/:section',
        builder: (_, state) =>
            AppShell(section: state.pathParameters['section'] ?? 'dashboard'),
      ),
      GoRoute(
        path: '/detail/:type/:id',
        builder: (_, state) => EntityDetailPage(
          type: state.pathParameters['type'] ?? 'courses',
          id: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
        ),
      ),
    ],
  );

  @override
  void initState() {
    super.initState();
    scheduleMicrotask(() => widget.session.bootstrap());
  }

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: widget.session),
        ChangeNotifierProvider.value(value: widget.theme),
        Provider.value(value: widget.cache),
        Provider.value(value: widget.api),
      ],
      child: Consumer<ThemeController>(
        builder: (context, theme, _) => MaterialApp.router(
          title: AppStrings.appName,
          debugShowCheckedModeBanner: false,
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          themeMode: theme.mode,
          routerConfig: _router,
        ),
      ),
    );
  }
}
