import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ThemeController extends ChangeNotifier {
  ThemeController(this._preferences) {
    final saved = _preferences.getString(_key);
    mode = ThemeMode.values.firstWhere(
      (item) => item.name == saved,
      orElse: () => ThemeMode.system,
    );
  }

  static const _key = 'margin_theme_mode';
  final SharedPreferences _preferences;
  late ThemeMode mode;

  Future<void> setMode(ThemeMode value) async {
    if (mode == value) return;
    mode = value;
    await _preferences.setString(_key, value.name);
    notifyListeners();
  }
}
