<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AcademicAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(
        string $role,
        int $universityId,
        string $status = 'active'
    ): User {
        return User::create([
            'name' => 'Test User',
            'email' => uniqid('user_') . '@example.test',
            'password' => 'test-password',
            'role' => $role,
            'university_id' => $universityId,
            'account_status' => $status,
        ]);
    }

    private function makeAcademicRecords(): array
    {
        $university = \App\Models\University::create([
            'name' => 'Test University',
            'code' => uniqid('UNI'),
        ]);

        $lecturer = $this->makeUser(
            'lecturer',
            $university->id
        );

        $student = $this->makeUser(
            'student',
            $university->id
        );

        $course = Course::create([
            'university_id' => $university->id,
            'name' => 'Security Fundamentals',
            'code' => 'SEC101',
        ]);

        $assignment = Assignment::create([
            'course_id' => $course->id,
            'created_by' => $lecturer->id,
            'title' => 'Security Assessment',
            'max_score' => 100,
        ]);

        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'content' => 'Test submission',
        ]);

        $grade = Grade::create([
            'submission_id' => $submission->id,
            'graded_by' => $lecturer->id,
            'score' => 85,
        ]);

        return compact(
            'university',
            'lecturer',
            'student',
            'course',
            'assignment',
            'submission',
            'grade'
        );
    }

    public function test_administrators_cannot_view_private_records(): void
    {
        $data = $this->makeAcademicRecords();

        foreach ([
            'platform_admin',
            'university_admin',
            'department_admin',
        ] as $role) {
            $admin = $this->makeUser(
                $role,
                $data['university']->id
            );

            $this->assertFalse(
                Gate::forUser($admin)->allows(
                    'view',
                    $data['submission']
                )
            );

            $this->assertFalse(
                Gate::forUser($admin)->allows(
                    'view',
                    $data['grade']
                )
            );
        }
    }

    public function test_student_cannot_view_another_students_submission(): void
    {
        $data = $this->makeAcademicRecords();

        $otherStudent = $this->makeUser(
            'student',
            $data['university']->id
        );

        $this->assertFalse(
            Gate::forUser($otherStudent)->allows(
                'view',
                $data['submission']
            )
        );
    }

    public function test_unassigned_lecturer_cannot_view_submission(): void
    {
        $data = $this->makeAcademicRecords();

        $this->assertFalse(
            Gate::forUser($data['lecturer'])->allows(
                'view',
                $data['submission']
            )
        );
    }

    public function test_only_assigned_lecturer_can_publish_grade(): void
    {
        $data = $this->makeAcademicRecords();

        \App\Models\CourseStaff::create([
            'course_id' => $data['course']->id,
            'user_id' => $data['lecturer']->id,
        ]);

        $this->assertTrue(
            Gate::forUser($data['lecturer'])->allows(
                'publish',
                $data['grade']
            )
        );

        $ta = $this->makeUser(
            'teaching_assistant',
            $data['university']->id
        );

        \App\Models\CourseStaff::create([
            'course_id' => $data['course']->id,
            'user_id' => $ta->id,
        ]);

        $this->assertFalse(
            Gate::forUser($ta)->allows(
                'publish',
                $data['grade']
            )
        );
    }

    public function test_cross_university_access_is_denied(): void
    {
        $data = $this->makeAcademicRecords();

        $otherUniversity = \App\Models\University::create([
            'name' => 'Other University',
            'code' => 'OTHER',
        ]);

        $outsider = $this->makeUser(
            'lecturer',
            $otherUniversity->id
        );

        \App\Models\CourseStaff::create([
            'course_id' => $data['course']->id,
            'user_id' => $outsider->id,
        ]);

        $this->assertFalse(
            Gate::forUser($outsider)->allows(
                'view',
                $data['submission']
            )
        );

        $this->assertFalse(
            Gate::forUser($outsider)->allows(
                'publish',
                $data['grade']
            )
        );
    }
}
