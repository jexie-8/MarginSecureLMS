<?php

namespace Tests\Feature;

use App\Http\Middleware\SanitizeInput;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InputSanitizationTest extends TestCase
{
    use RefreshDatabase;

    /** Pass a JSON body through the middleware and return what the app would see. */
    private function sanitize(array $body): array
    {
        $request = Request::create('/api/v1/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        $seen = [];
        (new SanitizeInput)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->input();

            return response('ok');
        });

        return $seen;
    }

    public function test_plain_text_fields_lose_markup_and_hidden_characters(): void
    {
        $clean = $this->sanitize([
            'name' => "  <b>Sara</b>\u{202E} \u{200B}Ali  ",
            'title' => "<script>alert(1)</script>Week\n 1",
            'code' => ' SEC<i>101</i> ',
        ]);

        $this->assertSame('Sara Ali', $clean['name']);
        $this->assertSame('alert(1)Week 1', $clean['title']);
        $this->assertSame('SEC101', $clean['code']);
    }

    public function test_long_text_keeps_its_content_but_loses_control_characters(): void
    {
        $clean = $this->sanitize(['content' => "  if (a < b && c > d) {\n\treturn 1;\x00\x07 }  "]);

        $this->assertSame("if (a < b && c > d) {\n\treturn 1; }", $clean['content']);
    }

    public function test_passwords_and_tokens_are_never_modified(): void
    {
        $input = ['password' => '  <p>Pa\x00ss word  ', 'current_password' => ' x ', 'token' => ' abc ', 'challenge_id' => ' id '];

        $this->assertSame($input, $this->sanitize($input));
    }

    public function test_nested_values_and_non_strings_are_handled(): void
    {
        $clean = $this->sanitize([
            'rubric_scores' => [['criterion' => ' <b>Clarity</b> ', 'score' => 4.5], ['criterion' => 'Depth', 'score' => 3]],
            'published' => true,
            'count' => 3,
            'nothing' => null,
        ]);

        $this->assertSame('Clarity', $clean['rubric_scores'][0]['criterion']);
        $this->assertSame(4.5, $clean['rubric_scores'][0]['score']);
        $this->assertTrue($clean['published']);
        $this->assertSame(3, $clean['count']);
        $this->assertNull($clean['nothing']);
    }

    public function test_invalid_utf8_is_repaired_instead_of_reaching_the_database(): void
    {
        $request = Request::create('/api/v1/x', 'POST', ['title' => "Bad \xC3\x28 bytes"]);
        $seen = null;
        (new SanitizeInput)->handle($request, function (Request $r) use (&$seen) {
            $seen = $r->input('title');

            return response('ok');
        });

        $this->assertTrue(mb_check_encoding($seen, 'UTF-8'));
    }

    public function test_the_middleware_runs_on_real_api_requests(): void
    {
        $university = University::create(['name' => 'Uni', 'code' => 'U1']);
        $lecturer = User::create([
            'name' => 'Lena', 'email' => 'lena@example.test', 'password' => 'Pass-word-123!',
            'role' => 'lecturer', 'university_id' => $university->id, 'account_status' => 'active',
        ]);
        $course = Course::create(['university_id' => $university->id, 'name' => 'Security', 'code' => 'SEC1']);
        CourseStaff::create(['course_id' => $course->id, 'user_id' => $lecturer->id]);

        $this->actingAs($lecturer, 'web')->postJson('/api/v1/announcements', [
            'course_id' => $course->id,
            'title' => '<img src=x onerror=alert(1)>Schedule change',
            'body' => 'Bring a < ruler > and your laptop.',
            'published' => true,
        ])->assertCreated();

        $row = DB::table('announcements')->first();
        $this->assertSame('Schedule change', $row->title);
        $this->assertSame('Bring a < ruler > and your laptop.', $row->body);
    }
}
