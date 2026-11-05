<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\SubmissionFile;
use App\Services\FileSecurityScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\User;
use App\Notifications\AnnouncementPublishedNotification;
use Illuminate\Support\Facades\Notification;

class LearningToolsController extends Controller
{
    public function materials(Request $request): JsonResponse
    {
        $query = DB::table('course_materials')->join('courses', 'courses.id', '=', 'course_materials.course_id')
            ->select('course_materials.*', 'courses.name as course_name', 'courses.code as course_code')
            ->orderByDesc('course_materials.created_at');
        $this->scopeCourses($request, $query, 'course_materials.course_id');

        return response()->json(['data' => $query->paginate($this->perPage($request))]);
    }

    public function storeMaterial(Request $request, FileSecurityScanner $scanner): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'], 'published' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:25600'],
        ]);
        $this->manageCourse($request, (int) $data['course_id']);
        $scanner->validate($request->file('file'));
        $file = $request->file('file');
        $path = $file->store('materials/'.((int) $data['course_id']), 'local');
        $id = DB::table('course_materials')->insertGetId([
            'course_id' => $data['course_id'], 'uploaded_by' => $request->user()->id,
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            'published_at' => ($data['published'] ?? true) ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Material uploaded.', 'id' => $id], 201);
    }

    public function downloadMaterial(Request $request, int $material): BinaryFileResponse
    {
        $item = DB::table('course_materials')->where('id', $material)->first();
        abort_unless($item, 404);
        $this->viewCourse($request, (int) $item->course_id);
        abort_unless(Storage::disk('local')->exists($item->path), 404, 'File not found.');

        return response()->download(Storage::disk('local')->path($item->path), $item->original_name, ['Content-Type' => $item->mime_type]);
    }

    public function submissionFile(Request $request, SubmissionFile $file): BinaryFileResponse
    {
        $file->load('submission.assignment.course');
        $submission = $file->submission;
        abort_unless($request->user()->id === $submission->student_id || $this->canManage($request, $submission->assignment->course_id), 403);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return response()->download(Storage::disk('local')->path($file->path), $file->original_name, ['Content-Type' => $file->mime_type]);
    }

    public function announcements(Request $request): JsonResponse
    {
        $query = DB::table('announcements')->leftJoin('courses', 'courses.id', '=', 'announcements.course_id')
            ->join('users', 'users.id', '=', 'announcements.created_by')
            ->select('announcements.*', 'courses.name as course_name', 'users.name as author_name')->orderByDesc('published_at');
        $courseIds = $this->visibleCourseIds($request);
        $query->where(fn ($q) => $q->whereNull('announcements.course_id')->orWhereIn('announcements.course_id', $courseIds));

        return response()->json(['data' => $query->paginate($this->perPage($request))]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $data = $request->validate(['course_id' => ['nullable', 'integer', 'exists:courses,id'], 'title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:30000'], 'published' => ['nullable', 'boolean']]);
        if (isset($data['course_id'])) $this->manageCourse($request, (int) $data['course_id']);
        else abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin'], true), 403);
        $publishedAt = ($data['published'] ?? true) ? now() : null;
        $id = DB::transaction(function () use ($request, $data, $publishedAt): int {
            $id = DB::table('announcements')->insertGetId(['course_id' => $data['course_id'] ?? null, 'created_by' => $request->user()->id, 'title' => $data['title'], 'body' => $data['body'], 'published_at' => $publishedAt, 'created_at' => now(), 'updated_at' => now()]);
            if ($publishedAt) {
                $userIds = isset($data['course_id'])
                    ? DB::table('enrollments')->where('course_id', $data['course_id'])->where('status', 'active')->pluck('student_id')
                    : DB::table('users')->where('account_status', 'active')->pluck('id');
                $rows = $userIds->unique()->map(fn ($userId) => ['user_id' => $userId, 'type' => 'announcement', 'title' => $data['title'], 'message' => str($data['body'])->limit(240), 'data' => json_encode(['announcement_id' => $id]), 'created_at' => now(), 'updated_at' => now()])->all();
                if ($rows) DB::table('lms_notifications')->insert($rows);
            }
            return $id;
        });
        if ($publishedAt && config('margin.mail_notifications')) {
            $recipientIds = isset($data['course_id'])
                ? DB::table('enrollments')->where('course_id', $data['course_id'])->where('status', 'active')->pluck('student_id')
                : DB::table('users')->where('account_status', 'active')->pluck('id');
            Notification::send(User::query()->whereKey($recipientIds)->get(), new AnnouncementPublishedNotification($data['title'], $data['body']));
        }
        return response()->json(['message' => 'Announcement saved.', 'id' => $id], 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        $query = DB::table('lms_notifications')->where('user_id', $request->user()->id)->orderByDesc('created_at');
        return response()->json(['unread_count' => (clone $query)->whereNull('read_at')->count(), 'data' => $query->paginate($this->perPage($request))]);
    }

    public function readNotification(Request $request, int $notification): JsonResponse
    {
        $changed = DB::table('lms_notifications')->where('id', $notification)->where('user_id', $request->user()->id)->update(['read_at' => now(), 'updated_at' => now()]);
        abort_unless($changed, 404);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        DB::table('lms_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function attendance(Request $request): JsonResponse
    {
        $query = DB::table('attendance_sessions')->join('courses', 'courses.id', '=', 'attendance_sessions.course_id')
            ->select('attendance_sessions.*', 'courses.name as course_name')->orderByDesc('held_at');
        $this->scopeCourses($request, $query, 'attendance_sessions.course_id');
        $sessions = $query->get();
        $records = DB::table('attendance_records')->whereIn('attendance_session_id', $sessions->pluck('id'))
            ->when($request->user()->role === 'student', fn ($q) => $q->where('student_id', $request->user()->id))->get()->groupBy('attendance_session_id');
        return response()->json(['data' => $sessions->map(fn ($session) => (array) $session + ['records' => $records->get($session->id, collect())->values()])]);
    }

    public function storeAttendanceSession(Request $request): JsonResponse
    {
        $data = $request->validate(['course_id' => ['required', 'integer', 'exists:courses,id'], 'title' => ['required', 'string', 'max:255'], 'held_at' => ['required', 'date']]);
        $this->manageCourse($request, (int) $data['course_id']);
        $id = DB::table('attendance_sessions')->insertGetId($data + ['created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Attendance session created.', 'id' => $id], 201);
    }

    public function recordAttendance(Request $request, int $session): JsonResponse
    {
        $courseId = DB::table('attendance_sessions')->where('id', $session)->value('course_id'); abort_unless($courseId, 404);
        $this->manageCourse($request, (int) $courseId);
        $data = $request->validate(['records' => ['required', 'array', 'max:500'], 'records.*.student_id' => ['required', 'integer', 'exists:users,id'], 'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])], 'records.*.notes' => ['nullable', 'string', 'max:1000']]);
        foreach ($data['records'] as $record) DB::table('attendance_records')->updateOrInsert(['attendance_session_id' => $session, 'student_id' => $record['student_id']], ['status' => $record['status'], 'notes' => $record['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Attendance saved.']);
    }

    public function calendar(Request $request): JsonResponse
    {
        $courseIds = $this->visibleCourseIds($request);
        $events = DB::table('calendar_events')->where(fn ($q) => $q->whereNull('course_id')->orWhereIn('course_id', $courseIds))->orderBy('starts_at')->get();
        $deadlines = DB::table('assignments')->whereIn('course_id', $courseIds)->whereNotNull('published_at')->whereNotNull('due_at')->selectRaw("id, course_id, title, description, due_at as starts_at, due_at as ends_at, 'assignment' as visibility")->get();
        return response()->json(['data' => $events->concat($deadlines)->sortBy('starts_at')->values()]);
    }

    public function storeCalendarEvent(Request $request): JsonResponse
    {
        $data = $request->validate(['course_id' => ['nullable', 'integer', 'exists:courses,id'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'visibility' => ['required', Rule::in(['course', 'university'])]]);
        if (isset($data['course_id'])) $this->manageCourse($request, (int) $data['course_id']); else abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin'], true), 403);
        $id = DB::table('calendar_events')->insertGetId($data + ['created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Calendar event created.', 'id' => $id], 201);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $ids = $this->visibleCourseIds($request);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        return response()->json(['data' => [
            'courses' => DB::table('courses')->whereIn('id', $ids)->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))->limit(20)->get(),
            'assignments' => DB::table('assignments')->whereIn('course_id', $ids)->where('title', 'ilike', $like)->limit(20)->get(),
            'announcements' => DB::table('announcements')->where(fn ($q) => $q->whereNull('course_id')->orWhereIn('course_id', $ids))->where('title', 'ilike', $like)->limit(20)->get(),
            'materials' => DB::table('course_materials')->whereIn('course_id', $ids)->where('title', 'ilike', $like)->limit(20)->get(),
        ]]);
    }

    public function gradeSummary(Request $request, Course $course): JsonResponse
    {
        $this->viewCourse($request, $course->id);
        abort_unless(in_array($request->user()->role, ['student', 'lecturer', 'teaching_assistant'], true), 403, 'Administrators cannot access private grades.');
        $rows = DB::table('grades')->join('submissions', 'submissions.id', '=', 'grades.submission_id')->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->where('assignments.course_id', $course->id)->whereNotNull('grades.published_at')
            ->when($request->user()->role === 'student', fn ($q) => $q->where('submissions.student_id', $request->user()->id))
            ->select('submissions.student_id', DB::raw('SUM(grades.score) as earned'), DB::raw('SUM(assignments.max_score) as possible'))->groupBy('submissions.student_id')->get();
        return response()->json(['data' => $rows->map(fn ($row) => (array) $row + ['percentage' => (float) $row->possible > 0 ? round(((float) $row->earned / (float) $row->possible) * 100, 2) : 0])]);
    }

    public function exportGrades(Request $request, Course $course): StreamedResponse
    {
        $this->manageCourse($request, $course->id);
        $rows = DB::table('grades')->join('submissions', 'submissions.id', '=', 'grades.submission_id')->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')->join('users', 'users.id', '=', 'submissions.student_id')
            ->where('assignments.course_id', $course->id)->select('users.name', 'users.email', 'assignments.title', 'grades.score', 'assignments.max_score', 'grades.published_at')->get();
        return response()->streamDownload(function () use ($rows): void { $out = fopen('php://output', 'w'); fputcsv($out, ['Student', 'Email', 'Assignment', 'Score', 'Maximum', 'Published']); foreach ($rows as $row) fputcsv($out, (array) $row); fclose($out); }, $course->code.'-grades.csv', ['Content-Type' => 'text/csv']);
    }

    private function visibleCourseIds(Request $request): array
    {
        $user = $request->user(); $query = DB::table('courses');
        if (! $user->isPlatformAdmin()) $query->where('university_id', $user->university_id);
        if ($user->role === 'student') $query->whereIn('id', DB::table('enrollments')->where('student_id', $user->id)->where('status', 'active')->select('course_id'));
        elseif (in_array($user->role, ['lecturer', 'teaching_assistant'], true)) $query->whereIn('id', DB::table('course_staff')->where('user_id', $user->id)->select('course_id'));
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function scopeCourses(Request $request, $query, string $column): void { $query->whereIn($column, $this->visibleCourseIds($request)); }
    private function viewCourse(Request $request, int $courseId): void { abort_unless(in_array($courseId, $this->visibleCourseIds($request), true), 404); }
    private function manageCourse(Request $request, int $courseId): void { abort_unless($this->canManage($request, $courseId), 403); }
    private function canManage(Request $request, int $courseId): bool
    {
        $user = $request->user(); $course = DB::table('courses')->where('id', $courseId)->first(); if (! $course) return false;
        if ($user->isPlatformAdmin() || $user->managesUniversity((int) $course->university_id)) return true;
        return in_array($user->role, ['lecturer', 'teaching_assistant'], true) && DB::table('course_staff')->where('course_id', $courseId)->where('user_id', $user->id)->exists();
    }
}
