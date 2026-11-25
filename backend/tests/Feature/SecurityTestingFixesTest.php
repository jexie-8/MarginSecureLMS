<?php

namespace Tests\Feature;

use App\Http\Middleware\SetDatabaseUserContext;
use App\Models\University;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\TestCase;

/**
 * Regression tests for problems found by the OWASP WSTG test run
 * (docs/security-testing). Each one failed before it was fixed.
 */
class SecurityTestingFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $university = University::create(['name' => 'Uni', 'code' => 'U1']);
        $this->user = User::create([
            'name' => 'Sara', 'email' => 'sara@example.test', 'password' => 'Pass-word-123!',
            'role' => 'student', 'university_id' => $university->id, 'account_status' => 'active',
        ]);
    }

    public function test_row_level_security_context_is_set_before_route_binding(): void
    {
        $priority = app(Kernel::class)->getMiddlewarePriority();
        $context = array_search(SetDatabaseUserContext::class, $priority, true);
        $binding = array_search(SubstituteBindings::class, $priority, true);

        $this->assertNotFalse($context, 'SetDatabaseUserContext must be in the priority list');
        $this->assertNotFalse($binding);
        $this->assertLessThan($binding, $context, 'bindings would run queries without a database user context');
    }

    public function test_non_numeric_and_oversized_ids_are_404_not_500(): void
    {
        $this->actingAs($this->user, 'web');
        foreach (['abc', '99999999999999999999', "1'%20OR%20'1'='1"] as $id) {
            $this->getJson("/api/v1/courses/{$id}")->assertNotFound();
            $this->getJson("/api/v1/assignments/{$id}")->assertNotFound();
        }
    }

    public function test_filters_with_the_wrong_type_are_a_validation_error(): void
    {
        $this->actingAs($this->user, 'web');
        $this->getJson("/api/v1/assignments?course_id=' OR '1'='1")->assertStatus(422);
        $this->getJson('/api/v1/enrollments?course_id=abc')->assertStatus(422);
        $this->getJson('/api/v1/submissions?assignment_id[]=1')->assertStatus(422);
    }

    public function test_an_anonymous_request_that_does_not_ask_for_json_gets_401_not_500(): void
    {
        $this->get('/api/v1/me', ['Accept' => 'text/html'])->assertStatus(401);
    }

    public function test_forgot_password_answers_the_same_when_mail_delivery_fails(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'sara@example.test']);
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertOk();
        $this->assertSame($unknown->status(), $known->status());
        $this->assertSame($unknown->json(), $known->json());
    }

    public function test_pagination_links_do_not_echo_the_host_header(): void
    {
        config(['margin.force_root_url' => true, 'app.url' => 'https://lms.example.test']);
        (new AppServiceProvider($this->app))->boot();

        $this->actingAs($this->user, 'web')
            ->getJson('/api/v1/assignments', ['Host' => 'evil.example'])
            ->assertOk()
            ->assertDontSee('evil.example');
    }

    public function test_the_session_and_me_routes_are_rate_limited_like_the_rest_of_the_api(): void
    {
        $route = app('router')->getRoutes()->getByName('margin.auth.me');

        $this->assertContains('throttle:margin-api', $route->gatherMiddleware());
    }
}
