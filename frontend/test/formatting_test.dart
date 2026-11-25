import 'package:flutter_test/flutter_test.dart';
import 'package:margin_lms_frontend/shared/formatting.dart';

void main() {
  test('audit event codes read as words', () {
    expect(humanizeEvent('auth.login_succeeded'), 'Auth login succeeded');
    expect(humanizeEvent('grade.published'), 'Grade published');
  });

  test('model class names lose the PHP namespace', () {
    expect(humanizeType(r'App\Models\User'), 'User');
    expect(humanizeType(r'App\Models\GradeRevision'), 'Grade revision');
  });

  test('related records show a name, never just an id', () {
    expect(
      recordName({'id': 4, 'code': 'SEC260', 'name': 'Network Defense'}),
      'SEC260 · Network Defense',
    );
    expect(recordName({'id': 2, 'name': 'Demo Lecturer'}), 'Demo Lecturer');
    expect(recordName({'id': 9, 'email': 'a@b.test'}), 'a@b.test');
    expect(recordName({'id': 7}), '#7');
  });
}
