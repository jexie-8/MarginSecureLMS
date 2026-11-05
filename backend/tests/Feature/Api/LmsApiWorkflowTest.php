<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\University;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LmsApiWorkflowTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDatabaseWithoutSafetyCheck;
    }

    protected function refreshTestDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'pgsql' || $database !== 'margin_test') {
            $this->fail("Refusing database refresh: expected pgsql/margin_test, got {$driver}/{$database}.");
        }

        $this->refreshDatabaseWithoutSafetyCheck();
    }

    public function test_platform_administrator_creates_university_and_audit_log(): void
    {
        $administrator = User::factory()->role('platform_admin')->create();

        $response = $this->actingAs($administrator, 'web')->postJson('/api/v1/universities', [
            'name' => 'New University',
            'code' => 'NEW-UNI',
        ]);

        $response->assertCreated()->assertJsonPath('data.code', 'NEW-UNI');
        $this->assertDatabaseHas('universities', ['code' => 'NEW-UNI']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $administrator->id, 'event' => 'university.created']);
    }

    public function test_university_administrator_receives_404_for_cross_university_record(): void
    {
        $first = University::query()->create(['name' => 'First', 'code' => 'FIRST']);
        $second = University::query()->create(['name' => 'Second', 'code' => 'SECOND']);
        $administrator = User::factory()->role('university_admin')->create(['university_id' => $first->id]);
        $course = Course::query()->create(['university_id' => $second->id, 'name' => 'Hidden', 'code' => 'HIDDEN']);

        $this->actingAs($administrator, 'web')->getJson("/api/v1/courses/{$course->id}")->assertNotFound();
    }

    public function test_university_administrator_cannot_create_platform_administrator(): void
    {
        $university = University::query()->create(['name' => 'University', 'code' => 'UNI']);
        $administrator = User::factory()->role('university_admin')->create(['university_id' => $university->id]);

        $this->actingAs($administrator, 'web')->postJson('/api/v1/users', [
            'name' => 'Escalated User',
            'email' => 'escalated@example.test',
            'password' => 'Strong-Password!123',
            'role' => 'platform_admin',
            'university_id' => $university->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
    }

    public function test_student_only_lists_courses_with_an_active_enrollment(): void
    {
        $context = $this->academicContext();
        $hiddenCourse = Course::query()->create([
            'university_id' => $context['university']->id,
            'name' => 'Hidden Course',
            'code' => 'HID101',
        ]);

        $response = $this->actingAs($context['student'], 'web')->getJson('/api/v1/courses');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $context['course']->id);
        $this->assertNotSame($hiddenCourse->id, $response->json('data.0.id'));
    }

    public function test_assignment_submission_grading_and_publication_workflow(): void
    {
        $context = $this->academicContext();

        $assignmentResponse = $this->actingAs($context['lecturer'], 'web')->postJson('/api/v1/assignments', [
            'course_id' => $context['course']->id,
            'title' => 'Final Assessment',
            'max_score' => 100,
            'publish' => true,
        ])->assertCreated();
        $assignment = Assignment::query()->findOrFail($assignmentResponse->json('data.id'));

        $submissionResponse = $this->actingAs($context['student'], 'web')
            ->postJson("/api/v1/assignments/{$assignment->id}/submissions", ['content' => 'Student answer'])
            ->assertCreated();
        $submission = Submission::query()->findOrFail($submissionResponse->json('data.id'));

        $gradeResponse = $this->actingAs($context['assistant'], 'web')
            ->putJson("/api/v1/submissions/{$submission->id}/grade", ['score' => 88, 'feedback' => 'Good work'])
            ->assertCreated();
        $grade = Grade::query()->findOrFail($gradeResponse->json('data.id'));

        $this->actingAs($context['student'], 'web')->getJson("/api/v1/grades/{$grade->id}")->assertForbidden();
        $this->actingAs($context['lecturer'], 'web')->postJson("/api/v1/grades/{$grade->id}/publish")->assertOk();
        $this->actingAs($context['student'], 'web')->getJson("/api/v1/grades/{$grade->id}")
            ->assertOk()->assertJsonPath('data.score', '88.00');

        $this->assertDatabaseHas('audit_logs', ['event' => 'submission.created', 'auditable_id' => $submission->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'grade.published', 'auditable_id' => $grade->id]);
    }

    public function test_student_cannot_submit_after_assignment_deadline(): void
    {
        $context = $this->academicContext();
        $assignment = Assignment::query()->create([
            'course_id' => $context['course']->id,
            'created_by' => $context['lecturer']->id,
            'title' => 'Expired',
            'max_score' => 10,
            'published_at' => now()->subDays(2),
            'due_at' => now()->subDay(),
        ]);

        $this->actingAs($context['student'], 'web')
            ->postJson("/api/v1/assignments/{$assignment->id}/submissions", ['content' => 'Late answer'])
            ->assertForbidden();

        $this->assertDatabaseMissing('submissions', ['assignment_id' => $assignment->id]);
    }

    public function test_administrator_cannot_access_private_submissions_or_grades(): void
    {
        $context = $this->academicContext();
        $administrator = User::factory()->role('university_admin')->create(['university_id' => $context['university']->id]);

        $this->actingAs($administrator, 'web')->getJson('/api/v1/submissions')->assertForbidden();
        $this->actingAs($administrator, 'web')->getJson('/api/v1/grades')->assertForbidden();
    }

    public function test_student_submission_response_hides_unpublished_grade(): void
    {
        $context = $this->academicContext();
        $assignment = Assignment::query()->create([
            'course_id' => $context['course']->id,
            'created_by' => $context['lecturer']->id,
            'title' => 'Assessment',
            'max_score' => 100,
            'published_at' => now(),
        ]);
        $submission = Submission::query()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $context['student']->id,
            'attempt_number' => 1,
            'content' => 'Answer',
        ]);
        Grade::query()->create([
            'submission_id' => $submission->id,
            'graded_by' => $context['assistant']->id,
            'score' => 90,
        ]);

        $this->actingAs($context['student'], 'web')->getJson("/api/v1/submissions/{$submission->id}")
            ->assertOk()->assertJsonMissingPath('data.grade');
    }

    public function test_suspending_user_removes_all_database_sessions(): void
    {
        $administrator = User::factory()->role('platform_admin')->create();
        $target = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($administrator, 'web')->patchJson("/api/v1/users/{$target->id}", [
            'account_status' => 'suspended',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertSame('suspended', $target->fresh()->account_status);
    }

    public function test_authenticated_user_changes_password_with_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-Password!123')]);

        $this->actingAs($user, 'web')->putJson('/api/v1/password', [
            'current_password' => 'Old-Password!123',
            'password' => 'New-Password!456',
            'password_confirmation' => 'New-Password!456',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-Password!456', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'password.changed', 'actor_id' => $user->id]);
    }

    public function test_forgot_password_response_is_generic_and_only_active_account_is_notified(): void
    {
        Notification::fake();
        $active = User::factory()->create(['email' => 'active@example.test']);
        $suspended = User::factory()->suspended()->create(['email' => 'suspended@example.test']);

        $activeResponse = $this->postJson('/api/v1/forgot-password', ['email' => $active->email]);
        $missingResponse = $this->postJson('/api/v1/forgot-password', ['email' => 'missing@example.test']);
        $this->postJson('/api/v1/forgot-password', ['email' => $suspended->email]);

        $activeResponse->assertOk();
        $missingResponse->assertOk();
        $this->assertSame($activeResponse->json('message'), $missingResponse->json('message'));
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($suspended, ResetPassword::class);
    }

    /** @return array{university: University, department: Department, course: Course, lecturer: User, assistant: User, student: User} */
    private function academicContext(): array
    {
        $university = University::query()->create(['name' => 'University', 'code' => fake()->unique()->lexify('UNI???')]);
        $department = Department::query()->create(['university_id' => $university->id, 'name' => 'Department', 'code' => 'DEP']);
        $course = Course::query()->create(['university_id' => $university->id, 'name' => 'Course', 'code' => fake()->unique()->lexify('SEC???')]);
        $lecturer = User::factory()->role('lecturer')->create(['university_id' => $university->id, 'department_id' => $department->id]);
        $assistant = User::factory()->role('teaching_assistant')->create(['university_id' => $university->id, 'department_id' => $department->id]);
        $student = User::factory()->role('student')->create(['university_id' => $university->id, 'department_id' => $department->id]);
        CourseStaff::query()->create(['course_id' => $course->id, 'user_id' => $lecturer->id]);
        CourseStaff::query()->create(['course_id' => $course->id, 'user_id' => $assistant->id]);
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active']);

        return compact('university', 'department', 'course', 'lecturer', 'assistant', 'student');
    }
}
