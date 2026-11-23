<?php

namespace Tests\Feature;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Error responses must never help an attacker: no stack traces, file paths,
 * class names, SQL or framework internals. These tests run with debug off, as
 * in production.
 */
class ErrorLeakageTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_FRAGMENTS = [
        'vendor', 'App\\', 'Illuminate', 'Symfony', 'SQLSTATE', 'pg_', 'select ', 'insert into',
        '.php', 'trace', 'Stack', '/var/', 'C:\\', 'No query results', 'model [',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        $university = University::create(['name' => 'Uni', 'code' => 'U1']);
        $this->user = User::create([
            'name' => 'Sara', 'email' => 'sara@example.test', 'password' => 'Pass-word-123!',
            'role' => 'student', 'university_id' => $university->id, 'account_status' => 'active',
        ]);
    }

    private function assertNoLeak(TestResponse $response): void
    {
        $body = $response->getContent();
        $json = json_decode($body, true);

        $this->assertIsArray($json, 'Error responses must be JSON, got: '.substr($body, 0, 200));
        $this->assertEmpty(
            array_diff(array_keys($json), ['message', 'errors']),
            'Unexpected keys in error response: '.implode(', ', array_keys($json)),
        );
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $body, "Response leaks '{$fragment}': {$body}");
        }
    }

    public function test_unknown_routes_return_a_generic_404(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist')->assertNotFound();

        $this->assertNoLeak($response);
    }

    public function test_missing_records_do_not_reveal_model_names(): void
    {
        $response = $this->actingAs($this->user, 'web')->getJson('/api/v1/courses/999999')->assertNotFound();

        $this->assertNoLeak($response);
    }

    public function test_wrong_http_method_does_not_list_supported_methods_or_routes(): void
    {
        $response = $this->getJson('/api/v1/login')->assertStatus(405);

        $this->assertNoLeak($response);
        $this->assertStringNotContainsString('POST', $response->getContent());
        $this->assertStringNotContainsString('api/v1/login', $response->getContent());
    }

    public function test_authentication_and_authorisation_errors_are_generic(): void
    {
        $this->assertNoLeak($this->getJson('/api/v1/courses')->assertUnauthorized());
        $this->assertNoLeak(
            $this->actingAs($this->user, 'web')->getJson('/api/v1/audit-logs')->assertForbidden(),
        );
    }

    public function test_validation_errors_do_not_leak_internals(): void
    {
        $response = $this->postJson('/api/v1/login', ['email' => 'not-an-email'])->assertUnprocessable();

        $this->assertNoLeak($response);
    }

    public function test_unexpected_server_errors_hide_the_cause(): void
    {
        Route::middleware('api')->get('/api/v1/__boom', function () {
            throw new \RuntimeException('SQLSTATE[23505]: duplicate key in /var/www/app/Secret.php for App\\Models\\User');
        });

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500);

        $this->assertNoLeak($response);
        $this->assertSame('Server Error', $response->json('message'));
    }

    public function test_database_errors_hide_the_query(): void
    {
        Route::middleware('api')->get('/api/v1/__db', function () {
            return \Illuminate\Support\Facades\DB::select('select * from table_that_does_not_exist');
        });

        $response = $this->getJson('/api/v1/__db')->assertStatus(500);

        $this->assertNoLeak($response);
    }

    public function test_malformed_json_is_rejected_without_a_parser_message(): void
    {
        $response = $this->call('POST', '/api/v1/login', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], '{"email": ');

        $this->assertNoLeak($response);
    }

    public function test_the_production_environment_has_debug_off(): void
    {
        $example = file_get_contents(base_path('.env.production.example'));

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false\r?$/m', $example);
        $this->assertMatchesRegularExpression('/^APP_ENV=production\r?$/m', $example);
    }
}
