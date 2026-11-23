import 'package:flutter_test/flutter_test.dart';
import 'package:margin_lms_frontend/core/network/input_cleaner.dart';

void main() {
  test('trims text and removes control characters', () {
    final cleaned = cleanOutgoing({
      'title': '  Week 1\u0000\u0007  ',
      'body': 'line one\nline two\t',
      'nested': [
        {'name': '  Sara '},
      ],
      'count': 3,
      'flag': true,
      'none': null,
    });

    expect(cleaned, {
      'title': 'Week 1',
      'body': 'line one\nline two',
      'nested': [
        {'name': 'Sara'},
      ],
      'count': 3,
      'flag': true,
      'none': null,
    });
  });

  test('passwords and tokens are sent exactly as typed', () {
    final cleaned = cleanOutgoing({
      'password': '  Pa ss word  ',
      'current_password': ' x ',
      'token': ' abc ',
    });

    expect(cleaned, {
      'password': '  Pa ss word  ',
      'current_password': ' x ',
      'token': ' abc ',
    });
  });

  test('multipart fields are cleaned the same way', () {
    expect(cleanFields({'title': ' Notes\u0000 ', 'password': ' p '}), {
      'title': 'Notes',
      'password': ' p ',
    });
  });
}
