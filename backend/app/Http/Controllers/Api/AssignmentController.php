<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AssignmentResource;
use App\Models\Assignment;
use App\Models\Course;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Assignment::class);
        $actor = $request->user();
        $query = Assignment::query()->with('course')->latest('created_at')->orderByDesc('id');
        $request->whenFilled('course_id', fn (int $courseId) => $query->where('course_id', $courseId));
        if ($actor->role === 'student') {
            $query->whereNotNull('published_at')->whereHas('course.enrollments', fn ($q) => $q->where('student_id', $actor->id)->where('status', 'active'));
        } elseif (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->whereHas('course.staffAssignments', fn ($q) => $q->where('user_id', $actor->id));
        } elseif (! $actor->isPlatformAdmin()) {
            $query->whereHas('course', fn ($q) => $q->where('university_id', $actor->university_id));
        }

        return AssignmentResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): AssignmentResource
    {
        Gate::authorize('create', Assignment::class);
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'due_at' => ['nullable', 'date', 'after:now'],
            'publish' => ['sometimes', 'boolean'],
            'rubric' => ['nullable', 'array', 'max:30'],
            'rubric.*.name' => ['required', 'string', 'max:255'],
            'rubric.*.points' => ['required', 'numeric', 'gt:0'],
        ]);
        $course = Course::query()->findOrFail($data['course_id']);
        $this->ensureUniversityVisible($request->user(), $course->university_id);
        abort_unless($request->user()->staffAssignments()->where('course_id', $course->id)->exists(), 403);
        $assignment = Assignment::query()->create([
            ...collect($data)->except('publish')->all(),
            'created_by' => $request->user()->id,
            'published_at' => $request->boolean('publish') ? now() : null,
        ]);
        $audit->record($request->user(), 'assignment.created', $assignment, ['published' => $assignment->published_at !== null], $request);

        return new AssignmentResource($assignment->load('course'));
    }

    public function show(Request $request, Assignment $assignment): AssignmentResource
    {
        $assignment->loadMissing('course');
        $this->ensureUniversityVisible($request->user(), $assignment->course->university_id);
        Gate::authorize('view', $assignment);

        return new AssignmentResource($assignment);
    }

    public function update(Request $request, Assignment $assignment, AuditLogger $audit): AssignmentResource
    {
        $assignment->loadMissing('course');
        $this->ensureUniversityVisible($request->user(), $assignment->course->university_id);
        Gate::authorize('update', $assignment);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'max_score' => ['sometimes', 'numeric', 'gt:0', 'max:999999.99'],
            'due_at' => ['nullable', 'date'],
            'rubric' => ['nullable', 'array', 'max:30'],
            'rubric.*.name' => ['required', 'string', 'max:255'],
            'rubric.*.points' => ['required', 'numeric', 'gt:0'],
        ]);
        $assignment->update($data);
        $audit->record($request->user(), 'assignment.updated', $assignment, ['changed' => array_keys($data)], $request);

        return new AssignmentResource($assignment);
    }

    public function publish(Request $request, Assignment $assignment, AuditLogger $audit): AssignmentResource
    {
        $assignment->loadMissing('course');
        $this->ensureUniversityVisible($request->user(), $assignment->course->university_id);
        Gate::authorize('update', $assignment);
        $assignment->forceFill(['published_at' => now()])->save();
        $audit->record($request->user(), 'assignment.published', $assignment, [], $request);

        return new AssignmentResource($assignment);
    }
}
