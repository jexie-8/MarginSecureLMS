import 'package:flutter/material.dart';

enum FieldKind {
  text,
  email,
  password,
  number,
  multiline,
  dateTime,
  choice,
  relation,
  multiRelation,
  rubric,
}

class ResourceField {
  const ResourceField(
    this.key,
    this.label, {
    this.kind = FieldKind.text,
    this.required = true,
    this.choices = const [],
    this.relationEndpoint,
    this.relationLabel = 'name',
  });
  final String key;
  final String label;
  final FieldKind kind;
  final bool required;
  final List<String> choices;
  final String? relationEndpoint;
  final String relationLabel;
}

class ResourceConfig {
  const ResourceConfig({
    required this.key,
    required this.title,
    required this.description,
    required this.endpoint,
    required this.icon,
    required this.columns,
    this.fields = const [],
    this.canCreate = false,
    this.canEdit = false,
    this.canDelete = false,
    this.primaryLabel = 'name',
  });
  final String key;
  final String title;
  final String description;
  final String endpoint;
  final IconData icon;
  final Map<String, String> columns;
  final List<ResourceField> fields;
  final bool canCreate;
  final bool canEdit;
  final bool canDelete;
  final String primaryLabel;
}

abstract final class ResourceCatalog {
  static const courses = ResourceConfig(
    key: 'courses',
    title: 'Courses',
    description: 'Browse available course information.',
    endpoint: 'courses',
    icon: Icons.menu_book_outlined,
    columns: {'code': 'Code', 'name': 'Course', 'description': 'Description'},
    fields: [
      ResourceField(
        'university_id',
        'University',
        kind: FieldKind.relation,
        relationEndpoint: 'universities',
      ),
      ResourceField('name', 'Course name'),
      ResourceField('code', 'Course code'),
      ResourceField(
        'description',
        'Description',
        kind: FieldKind.multiline,
        required: false,
      ),
      ResourceField(
        'program_ids',
        'Programs',
        kind: FieldKind.multiRelation,
        relationEndpoint: 'programs',
      ),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const assignments = ResourceConfig(
    key: 'assignments',
    title: 'Assignments',
    description: 'Review coursework, deadlines, and publication status.',
    endpoint: 'assignments',
    icon: Icons.assignment_outlined,
    columns: {
      'title': 'Assignment',
      'course_id': 'Course',
      'max_score': 'Max score',
      'due_at': 'Due',
      'published_at': 'Status',
    },
    fields: [
      ResourceField(
        'course_id',
        'Course',
        kind: FieldKind.relation,
        relationEndpoint: 'courses',
      ),
      ResourceField('title', 'Title'),
      ResourceField(
        'description',
        'Instructions',
        kind: FieldKind.multiline,
        required: false,
      ),
      ResourceField('max_score', 'Maximum score', kind: FieldKind.number),
      ResourceField('due_at', 'Due date and time', kind: FieldKind.dateTime),
      ResourceField(
        'rubric',
        'Rubric criteria',
        kind: FieldKind.rubric,
        required: false,
      ),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const grades = ResourceConfig(
    key: 'grades',
    title: 'Grades',
    description: 'View published results and instructor feedback.',
    endpoint: 'grades',
    icon: Icons.bar_chart_outlined,
    columns: {
      'submission_id': 'Submission',
      'score': 'Score',
      'feedback': 'Feedback',
      'published_at': 'Published',
    },
    primaryLabel: 'score',
  );
  static const submissions = ResourceConfig(
    key: 'submissions',
    title: 'Submissions',
    description: 'Review student work and grading progress.',
    endpoint: 'submissions',
    icon: Icons.inventory_2_outlined,
    columns: {
      'student': 'Student',
      'assignment': 'Assignment',
      'attempt_number': 'Attempt',
      'submitted_at': 'Submitted',
      'grade': 'Grade',
    },
    primaryLabel: 'id',
  );
  static const users = ResourceConfig(
    key: 'users',
    title: 'User management',
    description: 'Manage university accounts, roles, and access status.',
    endpoint: 'users',
    icon: Icons.people_outline,
    columns: {
      'name': 'Name',
      'email': 'Email',
      'role': 'Role',
      'account_status': 'Status',
      'last_login_at': 'Last login',
    },
    fields: [
      ResourceField('name', 'Full name'),
      ResourceField('email', 'Email', kind: FieldKind.email),
      ResourceField('password', 'Temporary password', kind: FieldKind.password),
      ResourceField(
        'role',
        'Role',
        kind: FieldKind.choice,
        choices: [
          'student',
          'teaching_assistant',
          'lecturer',
          'department_admin',
          'university_admin',
          'platform_admin',
        ],
      ),
      ResourceField(
        'account_status',
        'Account status',
        kind: FieldKind.choice,
        choices: ['active', 'suspended'],
      ),
      ResourceField(
        'university_id',
        'University',
        kind: FieldKind.relation,
        relationEndpoint: 'universities',
      ),
      ResourceField(
        'department_id',
        'Department',
        kind: FieldKind.relation,
        relationEndpoint: 'departments',
        required: false,
      ),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const universities = ResourceConfig(
    key: 'universities',
    title: 'Universities',
    description: 'Manage university records.',
    endpoint: 'universities',
    icon: Icons.account_balance_outlined,
    columns: {'code': 'Code', 'name': 'University', 'created_at': 'Created'},
    fields: [
      ResourceField('name', 'University name'),
      ResourceField('code', 'Code'),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const departments = ResourceConfig(
    key: 'departments',
    title: 'Departments',
    description: 'Manage departments within universities.',
    endpoint: 'departments',
    icon: Icons.domain_outlined,
    columns: {
      'code': 'Code',
      'name': 'Department',
      'university_id': 'University',
    },
    fields: [
      ResourceField(
        'university_id',
        'University',
        kind: FieldKind.relation,
        relationEndpoint: 'universities',
      ),
      ResourceField('name', 'Department name'),
      ResourceField('code', 'Code'),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const programs = ResourceConfig(
    key: 'programs',
    title: 'Programs',
    description: 'Manage programs and their department relationships.',
    endpoint: 'programs',
    icon: Icons.school_outlined,
    columns: {
      'code': 'Code',
      'name': 'Program',
      'university_id': 'University',
      'department_ids': 'Departments',
    },
    fields: [
      ResourceField(
        'university_id',
        'University',
        kind: FieldKind.relation,
        relationEndpoint: 'universities',
      ),
      ResourceField('name', 'Program name'),
      ResourceField('code', 'Code'),
      ResourceField(
        'department_ids',
        'Departments',
        kind: FieldKind.multiRelation,
        relationEndpoint: 'departments',
      ),
    ],
    canCreate: true,
    canEdit: true,
  );
  static const enrollments = ResourceConfig(
    key: 'enrollments',
    title: 'Enrollments',
    description: 'Enroll students and manage enrollment status.',
    endpoint: 'enrollments',
    icon: Icons.how_to_reg_outlined,
    columns: {
      'student': 'Student',
      'course': 'Course',
      'status': 'Status',
      'enrolled_at': 'Enrolled',
    },
    fields: [
      ResourceField(
        'student_id',
        'Student',
        kind: FieldKind.relation,
        relationEndpoint: 'users',
        relationLabel: 'email',
      ),
      ResourceField(
        'course_id',
        'Course',
        kind: FieldKind.relation,
        relationEndpoint: 'courses',
      ),
      ResourceField(
        'status',
        'Status',
        kind: FieldKind.choice,
        choices: ['active', 'withdrawn'],
      ),
    ],
    canCreate: true,
    canEdit: false,
  );
  static const courseStaff = ResourceConfig(
    key: 'course-staff',
    title: 'Course staff',
    description: 'Assign instructors to their courses.',
    endpoint: 'course-staff',
    icon: Icons.group_work_outlined,
    columns: {'user': 'Instructor', 'course': 'Course'},
    fields: [
      ResourceField(
        'user_id',
        'Instructor',
        kind: FieldKind.relation,
        relationEndpoint: 'users',
        relationLabel: 'email',
      ),
      ResourceField(
        'course_id',
        'Course',
        kind: FieldKind.relation,
        relationEndpoint: 'courses',
      ),
    ],
    canCreate: true,
    canDelete: true,
  );
  static const auditLogs = ResourceConfig(
    key: 'audit-logs',
    title: 'Audit logs',
    description: 'Read-only security and administrative activity.',
    endpoint: 'audit-logs',
    icon: Icons.shield_outlined,
    columns: {
      'event': 'Event',
      'actor_id': 'Actor',
      'auditable_type': 'Resource',
      'auditable_id': 'Resource ID',
      'ip_address': 'IP address',
      'created_at': 'Time',
    },
    primaryLabel: 'event',
  );

  static ResourceConfig forSection(String section) => switch (section) {
    'courses' => courses,
    'assignments' => assignments,
    'grades' => grades,
    'submissions' || 'grading' => submissions,
    'users' => users,
    'enrollments' => enrollments,
    'course-staff' => courseStaff,
    'audit-logs' => auditLogs,
    _ => courses,
  };
}
