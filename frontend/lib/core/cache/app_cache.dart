import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../app_config.dart';

class CachedValue {
  const CachedValue(this.value, this.savedAt);
  final dynamic value;
  final DateTime savedAt;
  bool get isFresh => DateTime.now().difference(savedAt) < AppConfig.cacheTtl;
}

class AppCache {
  AppCache(this._preferences);
  final SharedPreferences _preferences;

  static const _prefix = 'margin_cache_';
  static const _safeEndpoints = <String>{
    'courses',
    'assignments',
    'universities',
    'departments',
    'programs',
  };

  bool canCache(String endpoint) => _safeEndpoints.contains(endpoint);

  String _key(int userId, String endpoint, String suffix) =>
      '$_prefix${userId}_${endpoint}_${suffix.hashCode}';

  Future<void> write(
    int userId,
    String endpoint,
    String suffix,
    dynamic value,
  ) async {
    if (!canCache(endpoint)) return;
    await _preferences.setString(
      _key(userId, endpoint, suffix),
      jsonEncode({
        'saved_at': DateTime.now().toUtc().toIso8601String(),
        'value': value,
      }),
    );
  }

  CachedValue? read(int userId, String endpoint, String suffix) {
    if (!canCache(endpoint)) return null;
    final raw = _preferences.getString(_key(userId, endpoint, suffix));
    if (raw == null) return null;
    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      return CachedValue(
        decoded['value'],
        DateTime.parse(decoded['saved_at'] as String).toLocal(),
      );
    } catch (_) {
      _preferences.remove(_key(userId, endpoint, suffix));
      return null;
    }
  }

  Future<void> clearUser(int userId) async {
    final marker = '$_prefix${userId}_';
    for (final key in _preferences.getKeys().where(
      (key) => key.startsWith(marker),
    )) {
      await _preferences.remove(key);
    }
  }

  Future<void> clearAll() async {
    for (final key in _preferences.getKeys().where(
      (key) => key.startsWith(_prefix),
    )) {
      await _preferences.remove(key);
    }
  }
}
