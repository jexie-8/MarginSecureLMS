<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class AuthenticationSessionSecurityTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDatabaseWithoutSafetyCheck;
    }

    private const PASSWORD = 'Correct-Horse-Battery-9!';

    /**
     * @var array<string, string>
     */
    private array $spaHeaders = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['localhost:3000']);
    }

    protected function refreshTestDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'pgsql' || $database !== 'margin_test') {
            $this->fail(
                "Refusing database refresh: expected pgsql/margin_test, got {$driver}/{$database}. "
                .'Run with --configuration phpunit.margin.xml.'
            );
        }

        $this->refreshDatabaseWithoutSafetyCheck();
    }

    public function test_valid_login_regenerates_session_updates_last_login_and_returns_safe_user_data(): void
    {
        $user = $this->makeUser('active');

        $this->withSession(['session_probe' => true]);
        $oldSessionId = session()->getId();

        $response = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.account_status')
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_login_is_generic_and_does_not_authenticate(): void
    {
        $user = $this->makeUser('active');

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Invalid credentials or account unavailable.');

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_inactive_login_matches_invalid_login_and_leaves_no_session(): void
    {
        $inactive = $this->makeUser('inactive');

        $inactiveResponse = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $inactive->email,
            'password' => self::PASSWORD,
        ]);

        $missingResponse = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => 'missing@example.test',
            'password' => self::PASSWORD,
        ]);

        $inactiveResponse->assertUnprocessable();
        $missingResponse->assertUnprocessable();
        $this->assertSame($missingResponse->json('errors.email'), $inactiveResponse->json('errors.email'));
        $this->assertGuest('web');
        $this->assertNull($inactive->fresh()->last_login_at);
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = $this->makeUser('active');
        $this->login($user);
        $authenticatedSessionId = session()->getId();

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertGuest('web');
        $this->assertNotSame($authenticatedSessionId, session()->getId());
        $this->withHeaders($this->spaHeaders)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_account_suspended_after_login_is_logged_out_immediately(): void
    {
        $user = $this->makeUser('active');
        $this->login($user);

        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->withHeaders($this->spaHeaders)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Account unavailable.']);

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $email = 'limited@example.test';
        $key = $this->loginThrottleKey($email);
        RateLimiter::clear($key);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertJsonPath('errors.email.0', 'Too many login attempts. Try again later.');

        RateLimiter::clear($key);
    }

    public function test_stateful_login_requires_csrf_outside_the_testing_bypass(): void
    {
        $user = $this->makeUser('active');
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertStatus(419);

            $this->withHeaders($this->spaHeaders)->get('/sanctum/csrf-cookie')->assertNoContent();
            $csrfToken = session()->token();

            $this->withHeaders($this->spaHeaders + ['X-CSRF-TOKEN' => $csrfToken])
                ->postJson('/api/v1/login', [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ])->assertOk();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_routes_use_one_stateful_stack_and_reject_bearer_tokens(): void
    {
        $login = collect(app('router')->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/login'
        );
        $me = collect(app('router')->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/me'
        );

        $this->assertNotNull($login);
        $this->assertNotNull($me);
        $this->assertContains(EnsureFrontendRequestsAreStateful::class, $login->gatherMiddleware());
        $this->assertNotContains('web', $login->gatherMiddleware());
        $this->assertContains('auth:web', $me->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $me->gatherMiddleware());

        $this->withHeaders($this->spaHeaders + ['Authorization' => 'Bearer not-a-margin-auth-method'])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    private function makeUser(string $status): User
    {
        return User::factory()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make(self::PASSWORD),
            'account_status' => $status,
            'last_login_at' => null,
        ]);
    }

    private function login(User $user): void
    {
        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    private function loginThrottleKey(string $email): string
    {
        return 'margin-login:'.hash('sha256', mb_strtolower($email).'|127.0.0.1');
    }
}

