<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demonstration seeder must not run in production.');
        }

        $password = (string) (config('margin.seed_admin_password') ?: 'Local-Only-ChangeMe!123');
        $university = University::query()->firstOrCreate(['code' => 'MARGIN'], ['name' => 'Margin University']);
        $department = Department::query()->firstOrCreate(
            ['university_id' => $university->id, 'code' => 'SEC'],
            ['name' => 'Cybersecurity'],
        );
        $program = Program::query()->firstOrCreate(
            ['university_id' => $university->id, 'code' => 'BSC-SEC'],
            ['name' => 'BSc Cybersecurity'],
        );
        $program->departments()->syncWithoutDetaching([$department->id => ['university_id' => $university->id]]);
        $platformAdmin = $this->user('Platform Admin', 'admin@margin.test', 'platform_admin', $password);
        $universityAdmin = $this->user('University Admin', 'university-admin@margin.test', 'university_admin', $password, $university->id);
        $lecturer = $this->user('Demo Lecturer', 'lecturer@margin.test', 'lecturer', $password, $university->id, $department->id);
        $student = $this->user('Demo Student', 'student@margin.test', 'student', $password, $university->id, $department->id);

        $courseDefinitions = [
            [
                'code' => 'SEC101',
                'name' => 'Security Fundamentals',
                'description' => 'Core principles of information security, risk, and defensive thinking.',
                'assignments' => [
                    ['title' => 'Security Risk Assessment', 'description' => 'Assess the risks and controls for a fictional university service.', 'max_score' => 100, 'due_days' => 3],
                    ['title' => 'Threat Modeling Exercise', 'description' => 'Create a structured threat model for a small web application.', 'max_score' => 80, 'due_days' => 10],
                ],
            ],
            [
                'code' => 'SEC202',
                'name' => 'Web Application Security',
                'description' => 'Secure web architecture, common vulnerabilities, and practical mitigations.',
                'assignments' => [
                    ['title' => 'OWASP Review', 'description' => 'Review a sample application against the OWASP Top 10.', 'max_score' => 100, 'due_days' => 5],
                    ['title' => 'Secure Session Design', 'description' => 'Design a secure cookie-session authentication flow.', 'max_score' => 75, 'due_days' => 14],
                ],
            ],
            [
                'code' => 'SEC240',
                'name' => 'Secure Software Engineering',
                'description' => 'Security requirements, secure coding, review, and software assurance.',
                'assignments' => [
                    ['title' => 'Secure Requirements Brief', 'description' => 'Write measurable security requirements for an LMS feature.', 'max_score' => 60, 'due_days' => 7],
                    ['title' => 'Code Review Report', 'description' => 'Identify and prioritize security issues in a supplied code sample.', 'max_score' => 100, 'due_days' => 16],
                ],
            ],
            [
                'code' => 'SEC260',
                'name' => 'Network Defense',
                'description' => 'Network monitoring, segmentation, incident response, and defensive operations.',
                'assignments' => [
                    ['title' => 'Network Segmentation Plan', 'description' => 'Propose a segmented network design for a university department.', 'max_score' => 90, 'due_days' => 9],
                    ['title' => 'Incident Triage Worksheet', 'description' => 'Analyze alerts and document an incident-triage sequence.', 'max_score' => 70, 'due_days' => 18],
                ],
            ],
        ];

        foreach ($courseDefinitions as $definition) {
            $course = Course::query()->updateOrCreate(
                ['university_id' => $university->id, 'code' => $definition['code']],
                ['name' => $definition['name'], 'description' => $definition['description']],
            );
            $course->programs()->syncWithoutDetaching([$program->id => ['university_id' => $university->id]]);
            CourseStaff::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $lecturer->id]);
            Enrollment::query()->updateOrCreate(
                ['course_id' => $course->id, 'student_id' => $student->id],
                ['status' => 'active'],
            );

            foreach ($definition['assignments'] as $assignment) {
                Assignment::query()->updateOrCreate(
                    ['course_id' => $course->id, 'title' => $assignment['title']],
                    [
                        'created_by' => $lecturer->id,
                        'description' => $assignment['description'],
                        'max_score' => $assignment['max_score'],
                        'due_at' => now()->addDays($assignment['due_days'])->setTime(23, 59),
                        'published_at' => now(),
                    ],
                );
            }
        }

        $this->command?->warn('Local demo accounts use MARGIN_SEED_ADMIN_PASSWORD or the documented local-only fallback.');
        $this->command?->line("Platform administrator: {$platformAdmin->email}");
        $this->command?->line("University administrator: {$universityAdmin->email}");
    }

    private function user(
        string $name,
        string $email,
        string $role,
        string $password,
        ?int $universityId = null,
        ?int $departmentId = null,
    ): User {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => $role,
                'account_status' => 'active',
                'university_id' => $universityId,
                'department_id' => $departmentId,
            ],
        );
    }
}
