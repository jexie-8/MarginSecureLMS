/// Turns server values that are meant for code into text for people.
library;

/// 'auth.login_succeeded' -> 'Auth login succeeded'.
String humanizeEvent(String event) =>
    _sentence(event.replaceAll(RegExp(r'[._]+'), ' '));

/// 'App\Models\GradeRevision' -> 'Grade revision'.
String humanizeType(String type) => _sentence(
  type
      .split(r'\')
      .last
      .replaceAllMapped(RegExp(r'(?<=[a-z0-9])(?=[A-Z])'), (_) => ' '),
);

/// A short name for a related record: 'SEC260 · Network Defense',
/// 'Demo Lecturer', or '#4' when nothing better is known.
String recordName(Map<dynamic, dynamic> row) {
  final name = (row['name'] ?? row['title'] ?? row['email'])?.toString();
  final code = row['code']?.toString();
  if (name == null || name.trim().isEmpty) {
    return code?.trim().isNotEmpty == true ? code! : '#${row['id'] ?? '?'}';
  }
  return code == null || code.trim().isEmpty ? name : '$code · $name';
}

String _sentence(String text) {
  final words = text.trim().toLowerCase().split(RegExp(r'\s+'));
  if (words.isEmpty || words.first.isEmpty) return text;
  final joined = words.join(' ');
  return '${joined[0].toUpperCase()}${joined.substring(1)}';
}
