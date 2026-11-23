<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\University;
use App\Models\User;
use App\Services\MfaVerifier;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Absolute session lifetime, one-time authenticator codes, and the
 * breached-password check.
 */
class AccountProtectionGapsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-horse-123!';

    private const BROWSER = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
        'Sec-Fetch-Site' => 'same-site',
        'User-Agent' => 'Mozilla/5.0 Test',
    ];

    private function user(string $role = 'student', array $extra = []): User
    {
        $university = University::firstOrCreate(['code' => 'U1'], ['name' => 'Uni']);

        return User::create(array_merge([
            'name' => 'Sara', 'email' => uniqid('u').'@example.test', 'password' => self::PASSWORD,
            'role' => $role, 'university_id' => $university->id, 'account_status' => 'active',
        ], $extra));
    }

    private function totpCode(string $secret, int $step): string
    {
        $method = new ReflectionMethod(TotpService::class, 'code');

        return $method->invoke(new TotpService, $secret, $step);
    }

    // ---- absolute session lifetime -------------------------------------------------

    public function test_a_session_ends_after_the_absolute_limit_even_if_it_is_active(): void
    {
        config(['margin.session_absolute_minutes' => 60, 'session.lifetime' => 100000]);
        $user = $this->user();

        $this->withHeaders(self::BROWSER)->postJson('/api/v1/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertOk();

        // Regular activity keeps the session alive inside the limit...
        $this->travel(30)->minutes();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertOk();
        $this->travel(25)->minutes();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertOk();

        // ...but not past it.
        $this->travel(10)->minutes();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertTrue(AuditLog::query()->where('event', 'session.absolute_timeout')->where('actor_id', $user->id)->exists());
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_limit_can_be_disabled_with_zero(): void
    {
        config(['margin.session_absolute_minutes' => 0, 'session.lifetime' => 100000]);
        $user = $this->user();

        $this->withHeaders(self::BROWSER)->postJson('/api/v1/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertOk();
        $this->travel(48)->hours();
        $this->withHeaders(self::BROWSER)->getJson('/api/v1/me')->assertOk();
    }

    // ---- one-time authenticator codes ----------------------------------------------

    public function test_an_authenticator_code_can_only_be_used_once(): void
    {
        Cache::flush();
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->user('university_admin', ['mfa_secret' => $secret]);
        $code = $this->totpCode($secret, intdiv(time(), 30));
        $verifier = app(MfaVerifier::class);

        $this->assertSame('totp', $verifier->verify($user, $code));
        $this->assertNull($verifier->verify($user, $code), 'the same code must not work twice');
    }

    public function test_a_replayed_code_is_refused_at_mobile_sign_in(): void
    {
        Cache::flush();
        $secret = 'JBSWY3DPEHPK3PXP';
        $admin = $this->user('university_admin', ['mfa_secret' => $secret]);
        $admin->forceFill(['mfa_confirmed_at' => now()])->save();
        $code = $this->totpCode($secret, intdiv(time(), 30));

        $signIn = function () use ($admin, $code) {
            $challenge = $this->postJson('/api/v1/mobile/login', ['email' => $admin->email, 'password' => self::PASSWORD])->json('challenge_id');

            return $this->postJson('/api/v1/mobile/mfa/challenge', ['challenge_id' => $challenge, 'code' => $code]);
        };

        $signIn()->assertOk();
        $signIn()->assertStatus(422);
    }

    public function test_a_different_valid_step_is_still_accepted(): void
    {
        Cache::flush();
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->user('university_admin', ['mfa_secret' => $secret]);
        $step = intdiv(time(), 30);
        $verifier = app(MfaVerifier::class);

        $this->assertSame('totp', $verifier->verify($user, $this->totpCode($secret, $step - 1)));
        $this->assertSame('totp', $verifier->verify($user, $this->totpCode($secret, $step)));
    }

    // ---- breached passwords --------------------------------------------------------

    private function fakeBreachList(?string $password): void
    {
        $sha = strtoupper(sha1((string) $password));
        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response(
                $password === null ? "0000000000000000000000000000000000A:1\r\n" : substr($sha, 5).":48213\r\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:2\r\n",
            ),
        ]);
    }

    private function changePassword(User $user, string $new)
    {
        return $this->actingAs($user, 'web')->putJson('/api/v1/password', [
            'current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new,
        ]);
    }

    public function test_a_password_found_in_a_breach_list_is_refused(): void
    {
        config(['margin.check_breached_passwords' => true]);
        $leaked = 'Summer2024!Summer';
        $this->fakeBreachList($leaked);

        $this->changePassword($this->user(), $leaked)->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_a_password_not_in_any_breach_list_is_accepted(): void
    {
        config(['margin.check_breached_passwords' => true]);
        $this->fakeBreachList(null);

        $this->changePassword($this->user(), 'Tr1cky-Unseen-Phrase!')->assertOk();
    }

    public function test_only_a_hash_prefix_is_sent_never_the_password(): void
    {
        config(['margin.check_breached_passwords' => true]);
        $this->fakeBreachList(null);
        $password = 'Tr1cky-Unseen-Phrase!';

        $this->changePassword($this->user(), $password)->assertOk();

        Http::assertSent(function ($request) use ($password) {
            return preg_match('#/range/[0-9A-F]{5}$#', $request->url()) === 1
                && ! str_contains($request->url(), $password)
                && $request->body() === '';
        });
    }

    public function test_the_check_does_not_block_users_when_the_service_is_down(): void
    {
        config(['margin.check_breached_passwords' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

        $this->changePassword($this->user(), 'Tr1cky-Unseen-Phrase!')->assertOk();
    }

    public function test_imported_accounts_must_meet_the_same_password_rules(): void
    {
        $admin = $this->user('university_admin');
        $csv = "name,email,role,password\nWeak User,weak@example.test,student,alllowercasepassword\nGood User,good@example.test,student,Gr3at-Passphrase-Here!\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('users.csv', $csv);

        $response = $this->actingAs($admin, 'web')->post('/api/v1/imports/users', ['file' => $file])->assertSuccessful();

        $this->assertNull(User::where('email', 'weak@example.test')->first());
        $this->assertNotNull(User::where('email', 'good@example.test')->first());
        $this->assertNotEmpty($response->json('errors'));
    }
}
