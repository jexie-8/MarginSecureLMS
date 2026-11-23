<?php

namespace Tests\Feature;

use App\Http\Middleware\SetDatabaseUserContext;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the PostgreSQL Row-Level Security policies work on their own, without
 * the Laravel policies. Fixtures are created as the (superuser) test account,
 * then queries run as the unprivileged margin_app role with a user context set,
 * exactly as the production runtime account behaves.
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $d;

    protected function setUp(): void
    {
        parent::setUp();

        $isSuperuser = DB::selectOne('select rolsuper from pg_roles where rolname = current_user')->rolsuper;
        $roleExists = DB::selectOne("select 1 as ok from pg_roles where rolname = 'margin_app'");
        if (! $isSuperuser || $roleExists === null) {
            $this->markTestSkipped('RLS tests need a superuser connection and the margin_app role.');
        }

        $this->d = $this->makeRecords();
    }

    private function user(string $role, int $universityId): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => uniqid($role.'_').'@example.test',
            'password' => 'test-password',
            'role' => $role,
            'university_id' => $universityId,
            'account_status' => 'active',
        ]);
    }

    private function makeRecords(): array
    {
        $uni = University::create(['name' => 'Uni A', 'code' => uniqid('A')]);
        $otherUni = University::create(['name' => 'Uni B', 'code' => uniqid('B')]);

        $lecturer = $this->user('lecturer', $uni->id);
        $ta = $this->user('teaching_assistant', $uni->id);
        $unassigned = $this->user('lecturer', $uni->id);
        $foreignLecturer = $this->user('lecturer', $otherUni->id);
        $student = $this->user('student', $uni->id);
        $otherStudent = $this->user('student', $uni->id);
        $admin = $this->user('university_admin', $uni->id);

        $course = Course::create(['university_id' => $uni->id, 'name' => 'Security', 'code' => 'SEC1']);
        foreach ([$lecturer, $ta] as $staff) {
            CourseStaff::create(['course_id' => $course->id, 'user_id' => $staff->id]);
        }
        foreach ([$student, $otherStudent] as $s) {
            Enrollment::create(['course_id' => $course->id, 'student_id' => $s->id, 'status' => 'active']);
        }

        $assignment = Assignment::create([
            'course_id' => $course->id,
            'created_by' => $lecturer->id,
            'title' => 'Essay',
            'max_score' => 100,
        ]);

        $submission = Submission::create([
            'assignment_id' => $assignment->id, 'student_id' => $student->id,
            'attempt_number' => 1, 'content' => 'mine',
        ]);
        $otherSubmission = Submission::create([
            'assignment_id' => $assignment->id, 'student_id' => $otherStudent->id,
            'attempt_number' => 1, 'content' => 'theirs',
        ]);
        $grade = Grade::create(['submission_id' => $submission->id, 'graded_by' => $lecturer->id, 'score' => 80]);

        return compact(
            'uni', 'lecturer', 'ta', 'unassigned', 'foreignLecturer', 'student', 'otherStudent',
            'admin', 'course', 'assignment', 'submission', 'otherSubmission', 'grade',
        );
    }

    /** Run $callback as the unprivileged application role with a user context. */
    private function asUser(?User $user, callable $callback): mixed
    {
        DB::statement('SET ROLE margin_app');
        if ($user !== null) {
            SetDatabaseUserContext::apply($user->id, $user->role, $user->university_id);
        }

        try {
            return $callback();
        } finally {
            SetDatabaseUserContext::clear();
            DB::statement('RESET ROLE');
        }
    }

    private function submissionCount(?User $user): int
    {
        return $this->asUser($user, fn () => DB::table('submissions')->count());
    }

    private function gradeCount(?User $user): int
    {
        return $this->asUser($user, fn () => DB::table('grades')->count());
    }

    public function test_no_user_context_means_no_rows(): void
    {
        $this->assertSame(0, $this->submissionCount(null));
        $this->assertSame(0, $this->gradeCount(null));
    }

    public function test_assigned_staff_see_course_submissions_and_grades(): void
    {
        foreach (['lecturer', 'ta'] as $key) {
            $this->assertSame(2, $this->submissionCount($this->d[$key]), $key);
            $this->assertSame(1, $this->gradeCount($this->d[$key]), $key);
        }
    }

    public function test_unassigned_foreign_and_admin_users_see_nothing(): void
    {
        foreach (['unassigned', 'foreignLecturer', 'admin'] as $key) {
            $this->assertSame(0, $this->submissionCount($this->d[$key]), $key);
            $this->assertSame(0, $this->gradeCount($this->d[$key]), $key);
        }
    }

    public function test_students_see_only_their_own_submission(): void
    {
        $this->assertSame(1, $this->submissionCount($this->d['student']));
        $ids = $this->asUser($this->d['student'], fn () => DB::table('submissions')->pluck('id')->all());
        $this->assertSame([$this->d['submission']->id], $ids);
    }

    public function test_students_see_a_grade_only_once_it_is_published(): void
    {
        $this->assertSame(0, $this->gradeCount($this->d['student']));

        $this->d['grade']->forceFill(['published_by' => $this->d['lecturer']->id, 'published_at' => now()])->save();

        $this->assertSame(1, $this->gradeCount($this->d['student']));
        $this->assertSame(0, $this->gradeCount($this->d['otherStudent']), 'another student must not see it');
    }

    public function test_withdrawn_students_lose_access(): void
    {
        Enrollment::query()->where('student_id', $this->d['student']->id)->update(['status' => 'withdrawn']);

        $this->assertSame(0, $this->submissionCount($this->d['student']));
    }

    public function test_only_lecturers_can_change_a_published_grade(): void
    {
        $gradeId = $this->d['grade']->id;
        $update = fn () => DB::table('grades')->where('id', $gradeId)->update(['score' => 90]);

        $this->assertSame(1, $this->asUser($this->d['ta'], $update), 'TA may edit an unpublished grade');

        $this->d['grade']->forceFill(['published_by' => $this->d['lecturer']->id, 'published_at' => now()])->save();

        $this->assertSame(0, $this->asUser($this->d['ta'], $update), 'TA must not edit a published grade');
        $this->assertSame(0, $this->asUser($this->d['unassigned'], $update));
        $this->assertSame(1, $this->asUser($this->d['lecturer'], $update), 'lecturer may edit a published grade');
    }

    public function test_a_teaching_assistant_cannot_publish_a_grade(): void
    {
        $this->expectException(QueryException::class);

        $this->asUser($this->d['ta'], fn () => DB::table('grades')
            ->where('id', $this->d['grade']->id)
            ->update(['published_at' => now(), 'published_by' => $this->d['ta']->id]));
    }

    public function test_a_lecturer_can_publish_a_grade(): void
    {
        $affected = $this->asUser($this->d['lecturer'], fn () => DB::table('grades')
            ->where('id', $this->d['grade']->id)
            ->update(['published_at' => now(), 'published_by' => $this->d['lecturer']->id]));

        $this->assertSame(1, $affected);
    }

    public function test_grades_cannot_be_created_for_other_courses_or_published_on_insert(): void
    {
        $this->expectException(QueryException::class);

        $this->asUser($this->d['unassigned'], fn () => DB::table('grades')->insert([
            'submission_id' => $this->d['otherSubmission']->id,
            'graded_by' => $this->d['unassigned']->id,
            'score' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_assigned_staff_can_create_an_unpublished_grade(): void
    {
        $inserted = $this->asUser($this->d['ta'], fn () => DB::table('grades')->insert([
            'submission_id' => $this->d['otherSubmission']->id,
            'graded_by' => $this->d['ta']->id,
            'score' => 70,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertTrue($inserted);
    }

    public function test_students_cannot_submit_as_someone_else(): void
    {
        $this->expectException(QueryException::class);

        $this->asUser($this->d['student'], fn () => DB::table('submissions')->insert([
            'assignment_id' => $this->d['assignment']->id,
            'student_id' => $this->d['otherStudent']->id,
            'attempt_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_enrolled_students_can_submit_as_themselves(): void
    {
        $inserted = $this->asUser($this->d['student'], fn () => DB::table('submissions')->insert([
            'assignment_id' => $this->d['assignment']->id,
            'student_id' => $this->d['student']->id,
            'attempt_number' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertTrue($inserted);
    }

    public function test_submissions_and_grades_cannot_be_deleted_or_submissions_edited(): void
    {
        foreach ([
            fn () => DB::table('submissions')->where('id', $this->d['submission']->id)->update(['content' => 'changed']),
            fn () => DB::table('submissions')->where('id', $this->d['submission']->id)->delete(),
            fn () => DB::table('grades')->where('id', $this->d['grade']->id)->delete(),
        ] as $attempt) {
            try {
                // A savepoint keeps the test's outer transaction usable after the error.
                $this->asUser($this->d['lecturer'], fn () => DB::transaction($attempt));
                $this->fail('The database allowed a statement that should be forbidden.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('mine', Submission::find($this->d['submission']->id)->content);
        $this->assertNotNull(Grade::find($this->d['grade']->id));
    }

    public function test_the_request_context_is_cleared_after_each_request(): void
    {
        $this->actingAs($this->d['lecturer'], 'web')->getJson('/api/v1/grades')->assertOk();

        $leftover = DB::selectOne("select current_setting('app.user_id', true) as uid");
        $this->assertContains($leftover->uid, [null, '']);
    }

    public function test_the_api_still_scopes_results_when_connected_as_the_app_role(): void
    {
        DB::statement('SET ROLE margin_app');

        try {
            $lecturerView = $this->actingAs($this->d['lecturer'], 'web')->getJson('/api/v1/submissions');
            $unassignedView = $this->actingAs($this->d['unassigned'], 'web')->getJson('/api/v1/submissions');
        } finally {
            DB::statement('RESET ROLE');
        }

        $lecturerView->assertOk();
        $this->assertCount(2, $lecturerView->json('data'));
        $unassignedView->assertOk();
        $this->assertCount(0, $unassignedView->json('data'));
    }
}
