<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Enrollment::class);
        $actor = $request->user();
        $query = Enrollment::query()->with(['course', 'student'])->latest('enrolled_at')->orderByDesc('id');
        if ($actor->role === 'student') {
            $query->where('student_id', $actor->id);
        } elseif (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->whereHas('course.staffAssignments', fn ($q) => $q->where('user_id', $actor->id));
        } elseif (! $actor->isPlatformAdmin()) {
            $query->whereHas('course', fn ($q) => $q->where('university_id', $actor->university_id));
            if ($actor->isDepartmentAdmin()) {
                $query->whereHas('student', fn ($q) => $q->where('department_id', $actor->department_id));
            }
        }

        return EnrollmentResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): EnrollmentResource
    {
        Gate::authorize('create', Enrollment::class);
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(['active', 'withdrawn'])],
        ]);
        $course = Course::query()->findOrFail($data['course_id']);
        $student = User::query()->findOrFail($data['student_id']);
        $this->ensureUniversityVisible($request->user(), $course->university_id);
        abort_unless($student->role === 'student' && $student->university_id === $course->university_id, 422, 'Student and course must belong to the same university.');
        if ($request->user()->isDepartmentAdmin()) {
            abort_unless($student->department_id === $request->user()->department_id, 403);
        }
        $enrollment = Enrollment::query()->updateOrCreate(
            ['course_id' => $course->id, 'student_id' => $student->id],
            ['status' => $data['status'] ?? 'active'],
        );
        $audit->record($request->user(), 'enrollment.saved', $enrollment, ['status' => $enrollment->status], $request);

        return new EnrollmentResource($enrollment->load(['course', 'student']));
    }

    public function update(Request $request, Enrollment $enrollment, AuditLogger $audit): EnrollmentResource
    {
        $enrollment->loadMissing(['course', 'student']);
        $this->ensureUniversityVisible($request->user(), $enrollment->course->university_id);
        Gate::authorize('update', $enrollment);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'withdrawn'])]]);
        $enrollment->update($data);
        $audit->record($request->user(), 'enrollment.status_changed', $enrollment, $data, $request);

        return new EnrollmentResource($enrollment);
    }
}
