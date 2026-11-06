<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\University;
use App\Models\User;
use App\Services\FileSecurityScanner;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $spaHeaders = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:5173',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:5173']);
    }

    public function test_published_grade_change_requires_reason_and_preserves_private_history(): void
    {
        $context = $this->courseContext();
        $assignment = Assignment::query()->create([
            'course_id' => $context['course']->id,
            'created_by' => $context['lecturer']->id,
            'title' => 'Final',
            'max_score' => 100,
            'published_at' => now(),
        ]);
        $submission = Submission::query()->create([
            'assignment_id' => $assignment->id,
            'student_id' => $context['student']->id,
            'attempt_number' => 1,
            'content' => 'Answer',
            'submitted_at' => now(),
        ]);
        $grade = Grade::query()->create([
            'submission_id' => $submission->id,
            'graded_by' => $context['lecturer']->id,
            'score' => 70,
            'feedback' => 'Original feedback',
            'published_by' => $context['lecturer']->id,
            'published_at' => now(),
        ]);

        $this->actingAs($context['lecturer'])->putJson("/api/v1/submissions/{$submission->id}/grade", [
            'score' => 80,
            'feedback' => 'Updated feedback',
        ])->assertUnprocessable()->assertJsonValidationErrors('change_reason');

        $this->actingAs($context['assistant'])->putJson("/api/v1/submissions/{$submission->id}/grade", [
            'score' => 80,
            'feedback' => 'Updated feedback',
            'change_reason' => 'Review completed',
        ])->assertForbidden();

        $this->actingAs($context['lecturer'])->putJson("/api/v1/submissions/{$submission->id}/grade", [
            'score' => 80,
            'feedback' => 'Updated feedback',
            'change_reason' => 'Rechecked the rubric calculation.',
        ])->assertOk();

        $this->assertDatabaseHas('grade_revisions', [
            'grade_id' => $grade->id,
            'changed_by' => $context['lecturer']->id,
            'reason' => 'Rechecked the rubric calculation.',
            'previous_score' => 70,
            'new_score' => 80,
        ]);
        $this->actingAs($context['lecturer'])->getJson("/api/v1/grades/{$grade->id}")
            ->assertOk()
            ->assertJsonPath('data.revision_history.0.previous_score', '70.00')
            ->assertJsonPath('data.revision_history.0.new_score', '80.00');
        $this->actingAs($context['student'])->getJson("/api/v1/grades/{$grade->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.revision_history');
    }

    public function test_login_failures_success_and_logout_are_audited_without_plain_email(): void
    {
        $user = User::factory()->create([
            'email' => 'security-audit@example.test',
            'password' => Hash::make('Strong-Password!123'),
        ]);

        $this->withSession([])->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
        $failed = AuditLog::query()->where('event', 'auth.login_failed')->firstOrFail();
        $this->assertNull($failed->actor_id);
        $this->assertArrayHasKey('identifier_hash', $failed->metadata);
        $this->assertStringNotContainsString($user->email, json_encode($failed->metadata, JSON_THROW_ON_ERROR));

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Strong-Password!123',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'auth.login_succeeded']);

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/logout')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'event' => 'auth.logout']);
    }

    public function test_authorization_denial_is_audited_and_security_headers_are_set(): void
    {
        $administrator = User::factory()->role('platform_admin')->create();

        $this->actingAs($administrator)->getJson('/api/v1/submissions')
            ->assertForbidden()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $administrator->id,
            'event' => 'authorization.denied',
        ]);
    }

    public function test_user_can_list_and_revoke_only_another_own_session_with_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Strong-Password!123')]);
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            [
                'id' => 'owned-other-session',
                'user_id' => $user->id,
                'ip_address' => '192.0.2.10',
                'user_agent' => 'Test Browser',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'foreign-session',
                'user_id' => $other->id,
                'ip_address' => '192.0.2.20',
                'user_agent' => 'Other Browser',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
        ]);
        $fingerprint = hash_hmac('sha256', 'owned-other-session', (string) config('app.key'));
        $foreignFingerprint = hash_hmac('sha256', 'foreign-session', (string) config('app.key'));

        $this->withSession([])->withHeaders($this->spaHeaders)->actingAs($user)->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonFragment(['id' => $fingerprint, 'ip_address' => '192.0.2.10'])
            ->assertJsonMissing(['id' => $foreignFingerprint]);
        $this->withHeaders($this->spaHeaders)->actingAs($user)->deleteJson("/api/v1/sessions/{$foreignFingerprint}", [
            'current_password' => 'Strong-Password!123',
        ])->assertNotFound();
        $this->withHeaders($this->spaHeaders)->actingAs($user)->deleteJson("/api/v1/sessions/{$fingerprint}", [
            'current_password' => 'Strong-Password!123',
        ])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    public function test_audit_prune_removes_only_expired_records(): void
    {
        config(['margin.audit_retention_days' => 365]);
        DB::table('audit_logs')->insert([
            ['event' => 'old', 'metadata' => '{}', 'created_at' => now()->subDays(366)],
            ['event' => 'current', 'metadata' => '{}', 'created_at' => now()->subDays(364)],
        ]);

        $this->assertSame(0, Artisan::call('audit:prune'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'old']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'current']);
    }

    public function test_admin_mfa_lifecycle_requires_password_and_consumes_recovery_code(): void
    {
        $password = 'Strong-Password!123';
        $administrator = User::factory()->role('platform_admin')->create([
            'password' => Hash::make($password),
        ]);

        $this->withSession([])->withHeaders($this->spaHeaders)->actingAs($administrator)
            ->postJson('/api/v1/mfa/setup')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $setup = $this->withHeaders($this->spaHeaders)->actingAs($administrator)
            ->postJson('/api/v1/mfa/setup', ['password' => $password])
            ->assertOk();
        $secret = $setup->json('secret');
        $this->assertNotSame($secret, DB::table('users')->where('id', $administrator->id)->value('mfa_secret'));

        $totp = app(TotpService::class);
        $method = new ReflectionMethod($totp, 'code');
        $currentCode = $method->invoke($totp, $secret, intdiv(time(), 30));
        $confirmation = $this->withHeaders($this->spaHeaders)->actingAs($administrator)
            ->postJson('/api/v1/mfa/confirm', ['code' => $currentCode])
            ->assertOk()
            ->assertJsonCount(8, 'recovery_codes');
        $recoveryCode = $confirmation->json('recovery_codes.0');

        auth('web')->logout();
        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $administrator->email,
            'password' => $password,
        ])->assertStatus(202)->assertJsonPath('mfa_required', true);
        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/mfa/challenge', [
            'code' => strtolower($recoveryCode),
        ])->assertOk();

        $this->assertCount(7, $administrator->fresh()->mfa_recovery_codes);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $administrator->id,
            'event' => 'mfa.recovery_code_used',
        ]);

        $this->withHeaders($this->spaHeaders)->deleteJson('/api/v1/mfa', [
            'password' => $password,
        ])->assertOk();
        $this->assertNull($administrator->fresh()->mfa_confirmed_at);
    }

    public function test_required_malware_scanning_fails_closed_when_scanner_is_unavailable(): void
    {
        config(['margin.file_scan_required' => true, 'margin.clamav_command' => null]);

        $this->expectException(ValidationException::class);
        app(FileSecurityScanner::class)->validate(
            UploadedFile::fake()->createWithContent('safe.txt', 'content'),
        );
    }

    /** @return array{course: Course, lecturer: User, assistant: User, student: User} */
    private function courseContext(): array
    {
        $university = University::query()->create(['name' => 'Security University', 'code' => 'SECU']);
        $course = Course::query()->create(['university_id' => $university->id, 'name' => 'Secure Course', 'code' => 'SEC401']);
        $lecturer = User::factory()->role('lecturer')->create(['university_id' => $university->id]);
        $assistant = User::factory()->role('teaching_assistant')->create(['university_id' => $university->id]);
        $student = User::factory()->role('student')->create(['university_id' => $university->id]);
        CourseStaff::query()->create(['course_id' => $course->id, 'user_id' => $lecturer->id]);
        CourseStaff::query()->create(['course_id' => $course->id, 'user_id' => $assistant->id]);
        Enrollment::query()->create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active']);

        return compact('course', 'lecturer', 'assistant', 'student');
    }
}
