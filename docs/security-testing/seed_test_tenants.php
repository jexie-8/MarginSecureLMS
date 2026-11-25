<?php
/**
 * Creates the extra accounts the security tests need, on top of the demo seed:
 *
 *   tenant A (MARGIN): student2@margin.test, enrolled only in SEC101
 *   tenant B (OTHER):  other-admin / other-lecturer / other-student@margin.test,
 *                      with course OTH101 and one published assignment
 *
 * Run inside the application image as the database superuser (see run-tests.md).
 * Safe to run twice.
 */

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\University;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$password = (string) config('margin.seed_admin_password');
if ($password === '') {
    fwrite(STDERR, "MARGIN_SEED_ADMIN_PASSWORD is not set\n");
    exit(1);
}

$make = function (string $name, string $email, string $role, ?int $uni, ?int $dept) use ($password): User {
    return User::query()->updateOrCreate(
        ['email' => $email],
        ['name' => $name, 'password' => Hash::make($password), 'role' => $role, 'account_status' => 'active',
         'university_id' => $uni, 'department_id' => $dept],
    );
};

$a = University::query()->where('code', 'MARGIN')->firstOrFail();
$aDept = Department::query()->where('university_id', $a->id)->firstOrFail();
$student2 = $make('Second Student', 'student2@margin.test', 'student', $a->id, $aDept->id);
$sec101 = Course::query()->where('university_id', $a->id)->where('code', 'SEC101')->firstOrFail();
Enrollment::query()->updateOrCreate(['course_id' => $sec101->id, 'student_id' => $student2->id], ['status' => 'active']);

$b = University::query()->firstOrCreate(['code' => 'OTHER'], ['name' => 'Other University']);
$bDept = Department::query()->firstOrCreate(['university_id' => $b->id, 'code' => 'GEN'], ['name' => 'General']);
$bAdmin = $make('Other Admin', 'other-admin@margin.test', 'university_admin', $b->id, null);
$bLect = $make('Other Lecturer', 'other-lecturer@margin.test', 'lecturer', $b->id, $bDept->id);
$bStud = $make('Other Student', 'other-student@margin.test', 'student', $b->id, $bDept->id);
$course = Course::query()->updateOrCreate(
    ['university_id' => $b->id, 'code' => 'OTH101'],
    ['name' => 'Other Course', 'description' => 'Belongs to the other university.'],
);
CourseStaff::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $bLect->id]);
Enrollment::query()->updateOrCreate(['course_id' => $course->id, 'student_id' => $bStud->id], ['status' => 'active']);
Assignment::query()->updateOrCreate(
    ['course_id' => $course->id, 'title' => 'Other Assignment'],
    ['created_by' => $bLect->id, 'description' => 'Other uni', 'max_score' => 100,
     'due_at' => now()->addDays(5), 'published_at' => now()],
);

echo json_encode([
    'tenantA' => ['university' => $a->id, 'student2' => $student2->id],
    'tenantB' => ['university' => $b->id, 'admin' => $bAdmin->id, 'lecturer' => $bLect->id, 'student' => $bStud->id, 'course' => $course->id],
]), "\n";
