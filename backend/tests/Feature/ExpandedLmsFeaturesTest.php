<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Submission;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpandedLmsFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $university = University::create(['name' => 'Margin Test University', 'code' => 'MTU']);
        $lecturer = User::create(['name' => 'Lecturer', 'email' => 'lecturer-expanded@test.local', 'password' => 'Password!12345', 'role' => 'lecturer', 'account_status' => 'active', 'university_id' => $university->id]);
        $student = User::create(['name' => 'Student', 'email' => 'student-expanded@test.local', 'password' => 'Password!12345', 'role' => 'student', 'account_status' => 'active', 'university_id' => $university->id]);
        $outsider = User::create(['name' => 'Outsider', 'email' => 'outsider-expanded@test.local', 'password' => 'Password!12345', 'role' => 'student', 'account_status' => 'active', 'university_id' => $university->id]);
        $course = Course::create(['university_id' => $university->id, 'name' => 'Applied Security', 'code' => 'ASEC']);
        CourseStaff::create(['course_id' => $course->id, 'user_id' => $lecturer->id]);
        Enrollment::create(['course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active']);

        return compact('university', 'lecturer', 'student', 'outsider', 'course');
    }

    public function test_course_material_is_private_and_downloadable_by_enrolled_student(): void
    {
        Storage::fake('local');
        $data = $this->records();
        $response = $this->actingAs($data['lecturer'])->post('/api/v1/materials', ['course_id' => $data['course']->id, 'title' => 'Week 1', 'file' => UploadedFile::fake()->create('week1.pdf', 20, 'application/pdf')]);
        $response->assertCreated();
        $material = (int) $response->json('id');
        $this->actingAs($data['student'])->get("/api/v1/materials/{$material}/download")->assertOk();
        $this->actingAs($data['outsider'])->get("/api/v1/materials/{$material}/download")->assertNotFound();
    }

    public function test_announcement_creates_student_notification(): void
    {
        $data = $this->records();
        $this->actingAs($data['lecturer'])->postJson('/api/v1/announcements', ['course_id' => $data['course']->id, 'title' => 'Schedule change', 'body' => 'Class starts at ten.', 'published' => true])->assertCreated();
        $this->actingAs($data['student'])->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.data.0.title', 'Schedule change');
        $this->actingAs($data['outsider'])->getJson('/api/v1/announcements')->assertJsonCount(0, 'data.data');
    }

    public function test_calendar_search_attendance_and_grade_summary_are_course_scoped(): void
    {
        $data = $this->records();
        $assignment = Assignment::create(['course_id' => $data['course']->id, 'created_by' => $data['lecturer']->id, 'title' => 'Threat Model', 'max_score' => 100, 'due_at' => now()->addWeek(), 'published_at' => now()]);
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $data['student']->id, 'attempt_number' => 1, 'content' => 'Done', 'submitted_at' => now()]);
        Grade::create(['submission_id' => $submission->id, 'graded_by' => $data['lecturer']->id, 'score' => 85, 'published_by' => $data['lecturer']->id, 'published_at' => now()]);

        $this->actingAs($data['student'])->getJson('/api/v1/search?q=Threat')->assertJsonPath('data.assignments.0.title', 'Threat Model');
        $this->actingAs($data['student'])->getJson('/api/v1/calendar')->assertJsonPath('data.0.title', 'Threat Model');
        $this->actingAs($data['student'])->getJson('/api/v1/courses/'.$data['course']->id.'/grade-summary')->assertJsonPath('data.0.percentage', 85);
        $session = $this->actingAs($data['lecturer'])->postJson('/api/v1/attendance/sessions', ['course_id' => $data['course']->id, 'title' => 'Lecture 1', 'held_at' => now()->toISOString()])->assertCreated()->json('id');
        $this->actingAs($data['lecturer'])->putJson("/api/v1/attendance/sessions/{$session}/records", ['records' => [['student_id' => $data['outsider']->id, 'status' => 'present']]])->assertUnprocessable();
        $this->actingAs($data['lecturer'])->putJson("/api/v1/attendance/sessions/{$session}/records", ['records' => [['student_id' => $data['student']->id, 'status' => 'present']]])->assertOk();
        $this->actingAs($data['student'])->getJson('/api/v1/attendance')->assertJsonPath('data.0.records.0.status', 'present');
    }

    public function test_students_cannot_use_admin_import_or_mfa_setup(): void
    {
        $data = $this->records();
        $this->actingAs($data['student'])->post('/api/v1/imports/users', ['file' => UploadedFile::fake()->createWithContent('users.csv', "name,email,role,password\nA,a@test.local,student,Password!12345")])->assertForbidden();
        $this->actingAs($data['student'])->postJson('/api/v1/mfa/setup')->assertForbidden();
    }
}
