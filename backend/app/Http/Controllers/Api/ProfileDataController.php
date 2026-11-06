<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FileSecurityScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileDataController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $request->user()->update($data);

        return response()->json(['message' => 'Profile updated.', 'user' => $request->user()->only(['id', 'name', 'email', 'role'])]);
    }

    public function uploadAvatar(Request $request, FileSecurityScanner $scanner): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096']]);
        $file = $request->file('avatar');
        $scanner->validate($file);
        if ($request->user()->avatar_path) {
            Storage::disk('local')->delete($request->user()->avatar_path);
        }
        $path = $file->store('avatars/'.$request->user()->id, 'local');
        $request->user()->forceFill(['avatar_path' => $path])->save();

        return response()->json(['message' => 'Profile photo updated.', 'avatar_url' => url('/api/v1/profile/avatar/'.$request->user()->id)]);
    }

    public function avatar(Request $request, User $user): BinaryFileResponse
    {
        abort_unless($request->user()->id === $user->id || ($request->user()->university_id !== null && $request->user()->university_id === $user->university_id) || $request->user()->isPlatformAdmin(), 404);
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return response()->file(Storage::disk('local')->path($user->avatar_path), ['Cache-Control' => 'private, max-age=3600']);
    }

    public function importUsers(Request $request, FileSecurityScanner $scanner): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin'], true), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $scanner->validate($request->file('file'));
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map('trim', fgetcsv($handle) ?: []);
        $required = ['name', 'email', 'role', 'password'];
        abort_unless(! array_diff($required, $header), 422, 'CSV headers must include name,email,role,password.');
        $created = 0;
        $errors = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) {
                $errors[] = 'Skipped a row with the wrong number of columns.';

                continue;
            }
            $row = array_combine($header, $values);
            $validator = validator($row, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'role' => ['required', Rule::in(['department_admin', 'lecturer', 'teaching_assistant', 'student'])], 'password' => ['required', 'string', 'min:12']]);
            if ($validator->fails()) {
                $errors[] = $row['email'].': '.$validator->errors()->first();

                continue;
            }
            User::query()->create(['name' => $row['name'], 'email' => strtolower($row['email']), 'role' => $row['role'], 'password' => Hash::make($row['password']), 'account_status' => 'active', 'university_id' => $request->user()->isPlatformAdmin() ? ($row['university_id'] ?? null) : $request->user()->university_id, 'department_id' => $row['department_id'] ?: null]);
            $created++;
        }
        fclose($handle);

        return response()->json(['message' => "{$created} users imported.", 'created' => $created, 'errors' => array_slice($errors, 0, 100)]);
    }

    public function importEnrollments(Request $request, FileSecurityScanner $scanner): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin'], true), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $scanner->validate($request->file('file'));
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map('trim', fgetcsv($handle) ?: []);
        abort_unless(! array_diff(['student_email', 'course_code'], $header), 422, 'CSV headers must include student_email,course_code.');
        $count = 0;
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) {
                continue;
            } $row = array_combine($header, $values);
            $student = DB::table('users')->where('email', strtolower($row['student_email']))->where('role', 'student')->first();
            $course = DB::table('courses')->where('code', $row['course_code'])->when(! $request->user()->isPlatformAdmin(), fn ($q) => $q->where('university_id', $request->user()->university_id))->first();
            if ($student && $course) {
                DB::table('enrollments')->updateOrInsert(['course_id' => $course->id, 'student_id' => $student->id], ['status' => 'active', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $count++;
            }
        }
        fclose($handle);

        return response()->json(['message' => "{$count} enrollments imported.", 'imported' => $count]);
    }
}
