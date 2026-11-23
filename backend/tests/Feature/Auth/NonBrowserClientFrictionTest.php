<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cookie-session requests must look like they come from our own web page, and
 * a session cookie must not work from a different client. This is friction
 * against scripted clients, not a guarantee (see RequireBrowserContext).
 */
class NonBrowserClientFrictionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-horse-123!';

    private const PAGE = ['Accept' => 'application/json', 'Origin' => 'http://localhost:3000'];

    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36';

    private function user(): User
    {
        $university = University::create(['name' => 'Uni', 'code' => 'U1']);

        return User::create([
            'name' => 'Sara', 'email' => 'sara@example.test', 'password' => self::PASSWORD,
            'role' => 'student', 'university_id' => $university->id, 'account_status' => 'active',
        ]);
    }

    public function test_a_request_claiming_to_be_our_page_but_missing_browser_headers_is_refused(): void
    {
        $this->user();

        // What Postman or curl sends when someone copies the Origin header.
        $this->withHeaders(self::PAGE)
            ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_cross_site_requests_are_refused(): void
    {
        $this->user();

        foreach (['cross-site', 'none', ''] as $site) {
            $this->withHeaders(self::PAGE + ['Sec-Fetch-Site' => $site])
                ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])
                ->assertForbidden();
        }
    }

    public function test_requests_from_our_own_page_are_accepted(): void
    {
        $this->user();

        foreach (['same-origin', 'same-site'] as $site) {
            $this->withHeaders(self::PAGE + ['Sec-Fetch-Site' => $site])
                ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])
                ->assertOk();
        }
    }

    public function test_the_check_can_be_switched_off_by_configuration(): void
    {
        config(['margin.require_browser_context' => false]);
        $this->user();

        $this->withHeaders(self::PAGE)
            ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_mobile_token_requests_are_not_affected(): void
    {
        $this->user();

        $token = $this->postJson('/api/v1/mobile/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])
            ->assertOk()->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_session_cookie_replayed_from_another_client_is_ended_and_audited(): void
    {
        $user = $this->user();
        $browser = self::PAGE + ['Sec-Fetch-Site' => 'same-site', 'User-Agent' => self::BROWSER_UA];

        $this->withHeaders($browser)
            ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->withHeaders($browser)->getJson('/api/v1/me')->assertOk();

        // The same cookie arrives from a different tool.
        $this->withHeaders(['User-Agent' => 'PostmanRuntime/7.39.0'] + $browser)
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertTrue(
            AuditLog::query()->where('event', 'session.client_mismatch')->where('actor_id', $user->id)->exists(),
        );

        // The session is gone for the real browser too: the user must sign in again.
        $this->withHeaders($browser)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_same_browser_keeps_its_session(): void
    {
        $this->user();
        $browser = self::PAGE + ['Sec-Fetch-Site' => 'same-site', 'User-Agent' => self::BROWSER_UA];

        $this->withHeaders($browser)
            ->postJson('/api/v1/login', ['email' => 'sara@example.test', 'password' => self::PASSWORD])->assertOk();

        foreach (range(1, 3) as $_) {
            $this->withHeaders($browser)->getJson('/api/v1/me')->assertOk();
        }
    }

    public function test_cors_only_allows_the_headers_and_methods_the_app_uses(): void
    {
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }
}
