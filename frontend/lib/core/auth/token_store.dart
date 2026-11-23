import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Keeps the mobile bearer token in the device's secure storage (encrypted with
/// a key held in the Android Keystore), never in plain preferences.
class TokenStore {
  TokenStore([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'margin.api_token';
  static const _expiryKey = 'margin.api_token_expires_at';

  final FlutterSecureStorage _storage;

  /// Returns the saved token, or null if there is none or it has expired.
  Future<String?> read() async {
    final token = await _storage.read(key: _tokenKey);
    if (token == null) return null;
    final expiry = DateTime.tryParse(
      await _storage.read(key: _expiryKey) ?? '',
    );
    if (expiry != null && !expiry.isAfter(DateTime.now())) {
      await clear();
      return null;
    }
    return token;
  }

  Future<void> save(String token, String? expiresAt) async {
    await _storage.write(key: _tokenKey, value: token);
    if (expiresAt != null) {
      await _storage.write(key: _expiryKey, value: expiresAt);
    }
  }

  Future<void> clear() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _expiryKey);
  }
}
