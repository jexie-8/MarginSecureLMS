<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileTokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-horse-123!';

    private University $university;

    protected function setUp(): void
    {
        parent::setUp();
        $this->university = University::create(['name' => 'Uni', 'code' => uniqid('U')]);
    }

    private function makeUser(string $role = 'student', string $status = 'active', array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role),
            'email' => uniqid($role.'_').'@example.test',
            'password' => self::PASSWORD,
            'role' => $role,
            'university_id' => $this->university->id,
            'account_status' => $status,
        ], $extra));
    }

    /** Each request must authenticate afresh, as it would in production. */
    private function api(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->{$method}($uri, $data);
    }

    private function loginToken(User $user): string
    {
        $response = $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'Pixel 8',
        ])->assertOk();

        return $response->json('token');
    }

    public function test_a_user_can_sign_in_and_use_the_token(): void
    {
        $user = $this->makeUser();
        $response = $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'Pixel 8',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');
        $this->assertNotEmpty($response->json('expires_at'));

        $token = $response->json('token');
        $this->api($token, 'getJson', '/api/v1/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->api($token, 'getJson', '/api/v1/courses')->assertOk();
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/v1/courses')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/api/v1/courses')->assertUnauthorized();
    }

    public function test_bad_credentials_and_inactive_accounts_get_the_same_generic_error(): void
    {
        $active = $this->makeUser();
        $disabled = $this->makeUser('student', 'suspended');

        $wrongPassword = $this->postJson('/api/v1/mobile/login', ['email' => $active->email, 'password' => 'wrong']);
        $unknownUser = $this->postJson('/api/v1/mobile/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD]);
        $inactive = $this->postJson('/api/v1/mobile/login', ['email' => $disabled->email, 'password' => self::PASSWORD]);

        foreach ([$wrongPassword, $unknownUser, $inactive] as $response) {
            $response->assertStatus(422)->assertJsonPath('errors.email.0', 'Invalid credentials or account unavailable.');
        }
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count());
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/mobile/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        // Even the correct password is refused while locked out.
        $this->postJson('/api/v1/mobile/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_tokens_expire(): void
    {
        $token = $this->loginToken($this->makeUser());

        $this->api($token, 'getJson', '/api/v1/me')->assertOk();

        $this->travel(config('margin.mobile_token_hours') + 1)->hours();

        $this->api($token, 'getJson', '/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->loginToken($this->makeUser());

        $this->api($token, 'postJson', '/api/v1/mobile/logout')->assertOk();

        $this->api($token, 'getJson', '/api/v1/me')->assertUnauthorized();
    }

    public function test_a_deactivated_account_loses_access_immediately(): void
    {
        $user = $this->makeUser();
        $token = $this->loginToken($user);

        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->api($token, 'getJson', '/api/v1/me')->assertForbidden();
        $this->api($token, 'getJson', '/api/v1/me')->assertUnauthorized();
    }

    public function test_changing_the_password_revokes_other_devices(): void
    {
        $user = $this->makeUser();
        $phone = $this->loginToken($user);
        $tablet = $this->loginToken($user);

        $this->api($phone, 'putJson', '/api/v1/password', [
            'current_password' => self::PASSWORD,
            'password' => 'Brand-new-pass-456!',
            'password_confirmation' => 'Brand-new-pass-456!',
        ])->assertOk();

        $this->api($tablet, 'getJson', '/api/v1/me')->assertUnauthorized();
        $this->api($phone, 'getJson', '/api/v1/me')->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mfaRoles(): array
    {
        return [
            'university admin' => ['university_admin'],
            'lecturer' => ['lecturer'],
            'teaching assistant' => ['teaching_assistant'],
            'student' => ['student'],
        ];
    }

    #[DataProvider('mfaRoles')]
    public function test_any_role_with_mfa_must_complete_the_challenge(string $role): void
    {
        $admin = $this->makeUser($role, 'active', [
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_recovery_codes' => [Hash::make('RECOVERYONE')],
        ]);
        $admin->forceFill(['mfa_confirmed_at' => now()])->save();

        $login = $this->postJson('/api/v1/mobile/login', ['email' => $admin->email, 'password' => self::PASSWORD]);
        $login->assertStatus(202)->assertJsonPath('mfa_required', true)->assertJsonMissingPath('token');
        $challenge = $login->json('challenge_id');

        $this->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => 'WRONGCODE1'])->assertStatus(422);

        $ok = $this->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => 'recoveryone'])->assertOk();
        $this->api($ok->json('token'), 'getJson', '/api/v1/me')->assertOk();

        // A recovery code works once only, and a finished challenge cannot be reused.
        $this->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => 'recoveryone'])->assertStatus(422);
    }

    public function test_a_mfa_challenge_dies_after_too_many_wrong_codes(): void
    {
        $admin = $this->makeUser('university_admin', 'active', [
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_recovery_codes' => [Hash::make('RECOVERYONE')],
        ]);
        $admin->forceFill(['mfa_confirmed_at' => now()])->save();
        $challenge = $this->postJson('/api/v1/mobile/login', ['email' => $admin->email, 'password' => self::PASSWORD])->json('challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
                ->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => 'WRONGCODE1'])->assertStatus(422);
        }

        // Even the right code no longer works: the user must start again with the password.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => 'RECOVERYONE'])
            ->assertStatus(422)->assertJsonPath('message', 'The MFA challenge has expired.');
    }

    public function test_mobile_sign_ins_are_audited(): void
    {
        $user = $this->makeUser();
        $this->loginToken($user);

        $log = AuditLog::query()->where('event', 'auth.login_succeeded')->where('actor_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('mobile', $log->metadata['client']);
    }
}
