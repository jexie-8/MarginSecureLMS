<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-horse-123!';

    private function user(string $role, University $uni, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower($name).'@example.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'university_id' => $uni->id,
            'account_status' => 'active',
        ]);
    }

    public function test_the_privacy_notice_is_public_and_complete(): void
    {
        $response = $this->getJson('/api/v1/privacy-notice')->assertOk();

        $this->assertNotEmpty($response->json('contact_email'));
        $titles = collect($response->json('sections'))->pluck('title')->all();
        foreach (['What we collect', 'Who can see it', 'How long we keep your data', 'Your rights'] as $expected) {
            $this->assertContains($expected, $titles);
        }
    }

    public function test_exporting_data_requires_the_password(): void
    {
        $uni = University::create(['name' => 'Uni', 'code' => 'U1']);
        $student = $this->user('student', $uni, 'Sara');

        $this->actingAs($student, 'web')->postJson('/api/v1/profile/export')->assertStatus(422);
        $this->actingAs($student, 'web')->postJson('/api/v1/profile/export', ['password' => 'wrong'])->assertStatus(422);
    }

    public function test_the_export_contains_only_the_users_own_data_and_published_grades(): void
    {
        $uni = University::create(['name' => 'Uni', 'code' => 'U1']);
        $lecturer = $this->user('lecturer', $uni, 'Lena');
        $sara = $this->user('student', $uni, 'Sara');
        $omar = $this->user('student', $uni, 'Omar');
        $course = Course::create(['university_id' => $uni->id, 'name' => 'Security', 'code' => 'SEC1']);
        foreach ([$sara, $omar] as $s) {
            Enrollment::create(['course_id' => $course->id, 'student_id' => $s->id, 'status' => 'active']);
        }
        $assignment = Assignment::create(['course_id' => $course->id, 'created_by' => $lecturer->id, 'title' => 'Essay', 'max_score' => 100]);

        $mine = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $sara->id, 'attempt_number' => 1, 'content' => 'Sara essay']);
        $theirs = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $omar->id, 'attempt_number' => 1, 'content' => 'Omar essay']);
        Grade::create(['submission_id' => $mine->id, 'graded_by' => $lecturer->id, 'score' => 88, 'feedback' => 'Unpublished note']);
        Grade::create(['submission_id' => $theirs->id, 'graded_by' => $lecturer->id, 'score' => 55, 'feedback' => 'Omar private', 'published_at' => now(), 'published_by' => $lecturer->id]);

        $response = $this->actingAs($sara, 'web')->postJson('/api/v1/profile/export', ['password' => self::PASSWORD])->assertOk();
        $body = $response->getContent();

        $response->assertJsonPath('profile.email', 'sara@example.test');
        $response->assertJsonCount(1, 'submissions');
        $response->assertJsonPath('submissions.0.content', 'Sara essay');
        $response->assertJsonCount(0, 'grades');           // her own grade is not published yet
        $response->assertJsonCount(1, 'enrolments');
        foreach (['Omar essay', 'Omar private', 'Unpublished note', 'omar@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json('profile'))));
    }

    public function test_exports_are_audited(): void
    {
        $uni = University::create(['name' => 'Uni', 'code' => 'U1']);
        $student = $this->user('student', $uni, 'Sara');

        $this->actingAs($student, 'web')->postJson('/api/v1/profile/export', ['password' => self::PASSWORD])->assertOk();

        $this->assertTrue(AuditLog::query()->where('event', 'privacy.data_exported')->where('actor_id', $student->id)->exists());
    }
}
