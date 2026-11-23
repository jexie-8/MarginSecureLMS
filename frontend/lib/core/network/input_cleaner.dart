/// Tidies text typed by the user before it is sent to the API: removes control
/// characters and trims surrounding spaces. The server repeats and strengthens
/// this (it also strips markup from names and titles), because the server can
/// never trust the app. Passwords and tokens are sent exactly as typed.
const _untouchedKeys = {
  'password',
  'current_password',
  'password_confirmation',
  'token',
  'challenge_id',
};

final _controlCharacters = RegExp(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]');

Object? cleanOutgoing(Object? value, {String? key}) {
  if (key != null && _untouchedKeys.contains(key)) return value;
  if (value is String) {
    return value.replaceAll(_controlCharacters, '').trim();
  }
  if (value is Map) {
    return value.map(
      (k, v) => MapEntry(k, cleanOutgoing(v, key: k is String ? k : null)),
    );
  }
  if (value is List) {
    return value.map((item) => cleanOutgoing(item, key: key)).toList();
  }
  return value;
}

Map<String, String> cleanFields(Map<String, String> fields) => {
  for (final entry in fields.entries)
    entry.key: _untouchedKeys.contains(entry.key)
        ? entry.value
        : entry.value.replaceAll(_controlCharacters, '').trim(),
};
