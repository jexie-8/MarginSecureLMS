<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Enrollment;
use App\Models\University;
use App\Models\User;
use App\Services\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Uploads must go to the configured private disk (the encrypted S3 bucket in
 * production), never the server's own disk, and downloads must still be
 * permission-checked and streamed. A fake disk named "vault" stands in for S3.
 */
class PrivateFileStorageTest extends TestCase
{
    use RefreshDatabase;

    private array $d;

    protected function setUp(): void
    {
        parent::setUp();

        config(['margin.files_disk' => 'vault', 'filesystems.disks.vault' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/vault'), 'visibility' => 'private']]);
        Storage::fake('vault');
        Storage::fake('local');

        $uni = University::create(['name' => 'Uni', 'code' => 'U1']);
        $otherUni = University::create(['name' => 'Other', 'code' => 'U2']);
        $make = fn (string $role, int $uniId, string $name) => User::create([
            'name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'Pass-word-123!',
            'role' => $role, 'university_id' => $uniId, 'account_status' => 'active',
        ]);
        $lecturer = $make('lecturer', $uni->id, 'Lena');
        $student = $make('student', $uni->id, 'Sara');
        $classmate = $make('student', $uni->id, 'Omar');
        $outsider = $make('student', $otherUni->id, 'Nora');
        $course = Course::create(['university_id' => $uni->id, 'name' => 'Security', 'code' => 'SEC1']);
        CourseStaff::create(['course_id' => $course->id, 'user_id' => $lecturer->id]);
        foreach ([$student, $classmate] as $s) {
            Enrollment::create(['course_id' => $course->id, 'student_id' => $s->id, 'status' => 'active']);
        }
        $assignment = Assignment::create([
            'course_id' => $course->id, 'created_by' => $lecturer->id, 'title' => 'Essay', 'max_score' => 100,
            'published_at' => now(),
        ]);

        $this->d = compact('lecturer', 'student', 'classmate', 'outsider', 'course', 'assignment');
    }

    /** A real solid-colour PNG, built by hand because the GD extension is not required. */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\x00".str_repeat("\x37\x80\x91", $width);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }

    public function test_materials_are_stored_on_the_private_disk_and_not_on_the_server(): void
    {
        $response = $this->actingAs($this->d['lecturer'], 'web')->post('/api/v1/materials', [
            'course_id' => $this->d['course']->id,
            'title' => 'Week 1',
            'file' => UploadedFile::fake()->create('week1.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $path = DB::table('course_materials')->where('id', $response->json('id'))->value('path');

        Storage::disk('vault')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_enrolled_students_can_download_streamed_files_and_others_cannot(): void
    {
        $id = $this->actingAs($this->d['lecturer'], 'web')->post('/api/v1/materials', [
            'course_id' => $this->d['course']->id,
            'title' => 'Week 1',
            'published' => true,
            'file' => UploadedFile::fake()->createWithContent('week1.pdf', 'PDF-BYTES'),
        ])->assertCreated()->json('id');

        $download = $this->actingAs($this->d['student'], 'web')->get("/api/v1/materials/{$id}/download")->assertOk();
        $this->assertSame('PDF-BYTES', $download->streamedContent());
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $this->actingAs($this->d['outsider'], 'web')->get("/api/v1/materials/{$id}/download")->assertNotFound();
    }

    public function test_submission_files_go_to_the_private_disk_and_only_the_owner_and_staff_can_download_them(): void
    {
        $submission = $this->actingAs($this->d['student'], 'web')->post("/api/v1/assignments/{$this->d['assignment']->id}/submissions", [
            'files' => [UploadedFile::fake()->createWithContent('answer.txt', 'MY ANSWER')],
        ])->assertCreated();

        $file = DB::table('submission_files')->first();
        Storage::disk('vault')->assertExists($file->path);
        Storage::disk('local')->assertMissing($file->path);

        $this->actingAs($this->d['student'], 'web')->get("/api/v1/submission-files/{$file->id}/download")->assertOk();
        $this->actingAs($this->d['lecturer'], 'web')->get("/api/v1/submission-files/{$file->id}/download")->assertOk();
        $this->actingAs($this->d['classmate'], 'web')->get("/api/v1/submission-files/{$file->id}/download")->assertForbidden();
    }

    public function test_avatars_use_the_private_disk_and_replacing_one_removes_the_old_file(): void
    {
        $upload = fn () => $this->actingAs($this->d['student'], 'web')->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('me.png', $this->png(200, 200)),
        ])->assertOk();

        $upload();
        $first = $this->d['student']->fresh()->avatar_path;
        Storage::disk('vault')->assertExists($first);
        Storage::disk('local')->assertMissing($first);

        $upload();
        $second = $this->d['student']->fresh()->avatar_path;
        Storage::disk('vault')->assertMissing($first);
        Storage::disk('vault')->assertExists($second);

        $this->actingAs($this->d['classmate'], 'web')->get("/api/v1/profile/avatar/{$this->d['student']->id}")->assertOk();
        $this->actingAs($this->d['outsider'], 'web')->get("/api/v1/profile/avatar/{$this->d['student']->id}")->assertNotFound();
    }

    public function test_a_public_disk_is_refused_for_private_uploads(): void
    {
        config(['margin.files_disk' => 'public']);

        $this->expectException(RuntimeException::class);
        app(PrivateFiles::class)->disk();
    }

    public function test_any_disk_marked_public_is_refused(): void
    {
        config(['filesystems.disks.vault.visibility' => 'public']);

        $this->expectException(RuntimeException::class);
        app(PrivateFiles::class)->disk();
    }

    public function test_production_configuration_points_uploads_at_s3(): void
    {
        $manifest = file_get_contents(base_path('../copilot/api/manifest.yml'));
        $this->assertMatchesRegularExpression('/FILESYSTEM_DISK:\s*s3/', $manifest);

        $env = file_get_contents(base_path('.env.production.example'));
        $this->assertMatchesRegularExpression('/^FILESYSTEM_DISK=s3\r?$/m', $env);
    }
}
