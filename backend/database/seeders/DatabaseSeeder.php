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
use Illuminate\Support\Facades\DB;
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
        $departmentAdmin = $this->user('Department Admin', 'department-admin@margin.test', 'department_admin', $password, $university->id, $department->id);
        $lecturer = $this->user('Demo Lecturer', 'lecturer@margin.test', 'lecturer', $password, $university->id, $department->id);
        $teachingAssistant = $this->user('Teaching Assistant', 'teaching-assistant@margin.test', 'teaching_assistant', $password, $university->id, $department->id);
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

        $courses = [];
        foreach ($courseDefinitions as $definition) {
            $course = $courses[] = Course::query()->updateOrCreate(
                ['university_id' => $university->id, 'code' => $definition['code']],
                ['name' => $definition['name'], 'description' => $definition['description']],
            );
            $course->programs()->syncWithoutDetaching([$program->id => ['university_id' => $university->id]]);
            CourseStaff::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $lecturer->id]);
            CourseStaff::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $teachingAssistant->id]);
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

        $this->seedDemoActivity($courses, $lecturer, $universityAdmin, $student);

        $this->command?->warn('Local demo accounts use MARGIN_SEED_ADMIN_PASSWORD or the documented local-only fallback.');
        $this->command?->line("Platform administrator: {$platformAdmin->email}");
        $this->command?->line("University administrator: {$universityAdmin->email}");
        $this->command?->line("Department administrator: {$departmentAdmin->email}");
        $this->command?->line("Teaching assistant: {$teachingAssistant->email}");
    }

    /**
     * Demonstration content so the attendance, announcements, calendar and
     * notifications pages have something to show. Re-running the seeder
     * updates these rows instead of adding duplicates.
     *
     * @param  list<Course>  $courses
     */
    private function seedDemoActivity(array $courses, User $lecturer, User $universityAdmin, User $student): void
    {
        $statusPattern = [
            ['present', null], ['present', null], ['late', 'Arrived 10 minutes late.'], ['present', null],
            ['absent', null], ['present', null], ['excused', 'Medical note received.'], ['present', null],
        ];
        $announcements = [
            'SEC101' => ['Welcome to Security Fundamentals', 'Welcome! Lecture slides are in Materials before each session. Bring questions about the risk assessment to Thursday\'s lab.'],
            'SEC202' => ['OWASP review: extra lab hours', 'There will be an extra lab hour on Wednesday at 14:00 for anyone who wants help with the OWASP review.'],
            'SEC240' => ['Code review report: sample code posted', 'The sample code for the code review report is now in Materials. Focus on input handling and authentication.'],
            'SEC260' => ['Guest lecture on incident response', 'Next week a guest speaker from a security operations centre will walk through a real incident timeline.'],
        ];
        $events = [
            'SEC101' => ['Lab: password cracking defences', 2, 10, 2],
            'SEC202' => ['Midterm review session', 6, 12, 2],
            'SEC240' => ['Secure coding workshop', 11, 9, 3],
            'SEC260' => ['Guest lecture: incident response', 8, 15, 1],
        ];

        foreach ($courses as $c => $course) {
            // Four past weekly sessions per course, with a mix of attendance statuses.
            for ($week = 4; $week >= 1; $week--) {
                $title = 'Week '.(5 - $week).' lecture';
                DB::table('attendance_sessions')->updateOrInsert(
                    ['course_id' => $course->id, 'title' => $title],
                    ['created_by' => $lecturer->id, 'held_at' => now()->subWeeks($week)->setTime(9 + $c, 0), 'created_at' => now(), 'updated_at' => now()],
                );
                $sessionId = DB::table('attendance_sessions')->where('course_id', $course->id)->where('title', $title)->value('id');
                [$status, $notes] = $statusPattern[($c * 2 + $week) % count($statusPattern)];
                DB::table('attendance_records')->updateOrInsert(
                    ['attendance_session_id' => $sessionId, 'student_id' => $student->id],
                    ['status' => $status, 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()],
                );
            }

            [$title, $body] = $announcements[$course->code];
            DB::table('announcements')->updateOrInsert(
                ['course_id' => $course->id, 'title' => $title],
                ['created_by' => $lecturer->id, 'body' => $body, 'published_at' => now()->subDays(1 + $c), 'created_at' => now(), 'updated_at' => now()],
            );

            [$title, $inDays, $hour, $hours] = $events[$course->code];
            $startsAt = now()->addDays($inDays)->setTime($hour, 0);
            DB::table('calendar_events')->updateOrInsert(
                ['course_id' => $course->id, 'title' => $title],
                ['created_by' => $lecturer->id, 'description' => $course->name, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours($hours), 'visibility' => 'course', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        DB::table('announcements')->updateOrInsert(
            ['course_id' => null, 'title' => 'Library opening hours extended'],
            ['created_by' => $universityAdmin->id, 'body' => 'During the assessment period the university library is open until 22:00 on weekdays.', 'published_at' => now()->subHours(6), 'created_at' => now(), 'updated_at' => now()],
        );

        $notifications = [
            ['announcement', 'New announcement: Guest lecture on incident response', 'Network Defense has a new announcement.', null],
            ['attendance', 'Attendance recorded', 'Your attendance for Week 4 lecture has been recorded.', null],
            ['assignment', 'Deadline soon: Security Risk Assessment', 'Security Risk Assessment is due in 3 days.', now()->subDay()],
        ];
        foreach ($notifications as [$type, $title, $message, $readAt]) {
            DB::table('lms_notifications')->updateOrInsert(
                ['user_id' => $student->id, 'title' => $title],
                ['type' => $type, 'message' => $message, 'read_at' => $readAt, 'created_at' => now(), 'updated_at' => now()],
            );
        }
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
