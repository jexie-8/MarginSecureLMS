<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Submission;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GDPR-style transparency and access: a public privacy notice and a
 * password-confirmed export of the signed-in user's own data.
 *
 * The project follows GDPR principles as a design guide and does not claim
 * full regulatory compliance (see the Security Report, section 5.3).
 */
class PrivacyController extends Controller
{
    public function notice(): JsonResponse
    {
        $retentionMonths = (int) round(((int) config('margin.audit_retention_days', 365)) / 30);

        return response()->json([
            'updated_at' => config('margin.privacy.updated_at'),
            'controller' => config('margin.privacy.controller'),
            'contact_email' => config('margin.privacy.contact_email'),
            'sections' => [
                [
                    'title' => 'What we collect',
                    'body' => 'Only what the learning platform needs: your name, email address, role, university and department, your course enrolments, the work you submit, grades and feedback, attendance, notifications, and an optional profile photo.',
                ],
                [
                    'title' => 'Why we use it',
                    'body' => 'To run your courses (materials, assignments, grading, calendar and announcements), to keep your account secure, and to keep a record of important actions for accountability. We do not use your data for advertising or sell it.',
                ],
                [
                    'title' => 'Who can see it',
                    'body' => 'Grades and feedback are visible only to you and the lecturers and teaching assistants assigned to the course. Administrators manage accounts but do not automatically see academic records.',
                ],
                [
                    'title' => 'Security records',
                    'body' => "We log sign-ins, role and account changes, grade changes and blocked access attempts, including your IP address and browser details. These security records are kept for about {$retentionMonths} months and are then deleted automatically.",
                ],
                [
                    'title' => 'How long we keep your data',
                    'body' => 'Account and academic data are kept while your account is active. Contact the address below if you believe data is held for longer than it should be.',
                ],
                [
                    'title' => 'Your rights',
                    'body' => 'You can download a copy of your personal data from the Profile page at any time. To ask for correction or removal of your data, contact the address below.',
                ],
                [
                    'title' => 'Limits of this notice',
                    'body' => 'This platform follows data protection principles as a design guide. It does not claim full legal compliance, which must be assessed before real-world use.',
                ],
            ],
        ]);
    }

    public function export(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        $user = $request->user();

        $submissions = Submission::query()
            ->with(['assignment:id,title,course_id', 'files:id,submission_id,original_name,mime_type,size'])
            ->where('student_id', $user->id)
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Submission $s) => [
                'assignment' => $s->assignment?->title,
                'attempt' => $s->attempt_number,
                'content' => $s->content,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'files' => $s->files->map(fn ($f) => $f->only(['original_name', 'mime_type', 'size']))->values(),
            ]);

        // Students receive only published grades, as everywhere else in the app.
        $grades = Grade::query()
            ->with('submission.assignment:id,title')
            ->whereHas('submission', fn ($q) => $q->where('student_id', $user->id))
            ->whereNotNull('published_at')
            ->get()
            ->map(fn (Grade $g) => [
                'assignment' => $g->submission->assignment?->title,
                'score' => $g->score,
                'feedback' => $g->feedback,
                'published_at' => $g->published_at?->toIso8601String(),
            ]);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'university_id' => $user->university_id,
                'department_id' => $user->department_id,
                'account_status' => $user->account_status,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'mfa_enabled' => $user->mfa_confirmed_at !== null,
            ],
            'enrolments' => DB::table('enrollments')
                ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                ->where('enrollments.student_id', $user->id)
                ->get(['courses.code as course_code', 'courses.name as course_name', 'enrollments.status', 'enrollments.enrolled_at']),
            'teaching_assignments' => DB::table('course_staff')
                ->join('courses', 'courses.id', '=', 'course_staff.course_id')
                ->where('course_staff.user_id', $user->id)
                ->get(['courses.code as course_code', 'courses.name as course_name']),
            'submissions' => $submissions,
            'grades' => $grades,
            'attendance' => DB::table('attendance_records')
                ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
                ->where('attendance_records.student_id', $user->id)
                ->get(['attendance_sessions.title as session', 'attendance_sessions.held_at', 'attendance_records.status', 'attendance_records.notes']),
            'notifications' => DB::table('lms_notifications')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get(['type', 'title', 'message', 'read_at', 'created_at']),
            'security_activity' => DB::table('audit_logs')
                ->where('actor_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(1000)
                ->get(['event', 'ip_address', 'user_agent', 'created_at']),
        ];

        $audit->record($user, 'privacy.data_exported', $user, [], $request);

        return response()->json($payload);
    }
}
