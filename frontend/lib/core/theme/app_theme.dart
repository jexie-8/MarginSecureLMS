import 'package:flutter/material.dart';

abstract final class AppColors {
  static const navy = Color(0xFF0B2039);
  static const blue = Color(0xFF1F5D96);
  static const teal = Color(0xFF087D76);
  static const success = Color(0xFF147D53);
  static const warning = Color(0xFFA95C08);
  static const error = Color(0xFFB83232);
  static const lightBackground = Color(0xFFF5F7FA);
  static const darkBackground = Color(0xFF07121F);
}

abstract final class AppTheme {
  static ThemeData light() => _build(
    brightness: Brightness.light,
    background: AppColors.lightBackground,
    surface: Colors.white,
    text: const Color(0xFF172536),
    border: const Color(0xFFDFE5EC),
  );

  static ThemeData dark() => _build(
    brightness: Brightness.dark,
    background: AppColors.darkBackground,
    surface: const Color(0xFF102033),
    text: const Color(0xFFE9F0F6),
    border: const Color(0xFF2A3D52),
  );

  static ThemeData _build({
    required Brightness brightness,
    required Color background,
    required Color surface,
    required Color text,
    required Color border,
  }) {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.blue,
      brightness: brightness,
      primary: brightness == Brightness.light
          ? AppColors.blue
          : const Color(0xFF70B7EE),
      secondary: brightness == Brightness.light
          ? AppColors.teal
          : const Color(0xFF55C9BE),
      surface: surface,
      error: brightness == Brightness.light
          ? AppColors.error
          : const Color(0xFFFF8A8A),
    );
    final base = ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      fontFamily: 'Inter',
    );
    return base.copyWith(
      textTheme: base.textTheme.apply(bodyColor: text, displayColor: text),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: border),
          borderRadius: BorderRadius.circular(12),
        ),
      ),
      dividerColor: border,
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(9),
          borderSide: BorderSide(color: border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(9),
          borderSide: BorderSide(color: border),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 14,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
          minimumSize: const Size(44, 46),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(44, 46),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(9)),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: brightness == Brightness.light
            ? AppColors.navy
            : const Color(0xFF091827),
        selectedIconTheme: const IconThemeData(color: Colors.white),
        unselectedIconTheme: const IconThemeData(color: Color(0xFFAEBECD)),
        selectedLabelTextStyle: const TextStyle(color: Colors.white),
        unselectedLabelTextStyle: const TextStyle(color: Color(0xFFAEBECD)),
        indicatorColor: Colors.white.withValues(alpha: .12),
      ),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.macOS: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(),
        },
      ),
    );
  }
}
