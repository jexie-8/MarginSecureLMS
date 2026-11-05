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
        $course = Course::query()->firstOrCreate(
            ['university_id' => $university->id, 'code' => 'SEC101'],
            ['name' => 'Security Fundamentals', 'description' => 'Demonstration course'],
        );
        $course->programs()->syncWithoutDetaching([$program->id => ['university_id' => $university->id]]);

        $platformAdmin = $this->user('Platform Admin', 'admin@margin.test', 'platform_admin', $password);
        $universityAdmin = $this->user('University Admin', 'university-admin@margin.test', 'university_admin', $password, $university->id);
        $lecturer = $this->user('Demo Lecturer', 'lecturer@margin.test', 'lecturer', $password, $university->id, $department->id);
        $student = $this->user('Demo Student', 'student@margin.test', 'student', $password, $university->id, $department->id);

        CourseStaff::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $lecturer->id]);
        Enrollment::query()->firstOrCreate(['course_id' => $course->id, 'student_id' => $student->id], ['status' => 'active']);
        Assignment::query()->firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Security Assessment'],
            ['created_by' => $lecturer->id, 'description' => 'Demonstration assignment', 'max_score' => 100, 'published_at' => now()],
        );

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
