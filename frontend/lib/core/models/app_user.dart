class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.universityId,
    this.departmentId,
    this.avatarUrl,
    this.mfaEnabled = false,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
    id: (json['id'] as num?)?.toInt() ?? 0,
    name: json['name']?.toString() ?? 'Margin user',
    email: json['email']?.toString() ?? '',
    role: json['role']?.toString().toLowerCase() ?? 'student',
    universityId: (json['university_id'] as num?)?.toInt(),
    departmentId: (json['department_id'] as num?)?.toInt(),
    avatarUrl: json['avatar_url']?.toString(),
    mfaEnabled: json['mfa_enabled'] == true,
  );

  final int id;
  final String name;
  final String email;
  final String role;
  final int? universityId;
  final int? departmentId;
  final String? avatarUrl;
  final bool mfaEnabled;

  bool get isStudent => role == 'student';
  bool get isInstructor =>
      role == 'instructor' ||
      role == 'lecturer' ||
      role == 'teaching_assistant';
  bool get isPlatformAdmin => role == 'platform_admin';
  bool get isUniversityAdmin => role == 'university_admin';
  bool get isDepartmentAdmin => role == 'department_admin';
  bool get isAdministrator =>
      isPlatformAdmin ||
      isUniversityAdmin ||
      isDepartmentAdmin ||
      role == 'administrator' ||
      role == 'admin';

  String get roleLabel => switch (role) {
    'platform_admin' => 'Platform Administrator',
    'university_admin' => 'University Administrator',
    'department_admin' => 'Department Administrator',
    'lecturer' => 'Lecturer',
    'teaching_assistant' => 'Teaching Assistant',
    'instructor' => 'Instructor',
    _ => 'Student',
  };

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'role': role,
    'university_id': universityId,
    'department_id': departmentId,
    'avatar_url': avatarUrl,
    'mfa_enabled': mfaEnabled,
  };
}
