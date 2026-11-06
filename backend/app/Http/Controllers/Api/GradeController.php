<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GradeResource;
use App\Models\Grade;
use App\Models\Submission;
use App\Services\AuditLogger;
use App\Services\GradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GradeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Grade::class);
        $actor = $request->user();
        $query = Grade::query()->with('submission.assignment.course')->latest('created_at')->orderByDesc('id');
        if ($actor->role === 'student') {
            $query->whereNotNull('published_at')->whereHas('submission', function ($submissionQuery) use ($actor): void {
                $submissionQuery->where('student_id', $actor->id)
                    ->whereNotExists(function ($later): void {
                        $later->selectRaw('1')->from('submissions as later')
                            ->whereColumn('later.assignment_id', 'submissions.assignment_id')
                            ->whereColumn('later.student_id', 'submissions.student_id')
                            ->whereColumn('later.attempt_number', '>', 'submissions.attempt_number');
                    });
            });
        } elseif (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->whereHas('submission.assignment.course.staffAssignments', fn ($q) => $q->where('user_id', $actor->id));
        } else {
            abort(403, 'Administrators cannot access private grades.');
        }

        return GradeResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(
        Request $request,
        Submission $submission,
        GradeService $grades,
        AuditLogger $audit,
    ): JsonResponse {
        $submission->loadMissing('assignment.course');
        $this->ensureUniversityVisible($request->user(), $submission->assignment->course->university_id);
        $existing = $submission->grade()->first();
        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:20000'],
            'rubric_scores' => ['nullable', 'array', 'max:30'],
            'rubric_scores.*.criterion' => ['required', 'string', 'max:255'],
            'rubric_scores.*.score' => ['required', 'numeric', 'min:0'],
            'change_reason' => [
                Rule::requiredIf($existing?->published_at !== null),
                'nullable',
                'string',
                'max:1000',
            ],
        ]);
        $grade = $grades->saveGrade(
            $request->user(),
            $submission,
            (float) $data['score'],
            $data['feedback'] ?? null,
            $data['rubric_scores'] ?? null,
            array_key_exists('rubric_scores', $data),
            $data['change_reason'] ?? null,
        );
        $status = $grade->wasRecentlyCreated ? 201 : 200;
        $audit->record($request->user(), 'grade.saved', $grade, [
            'published_revision' => $existing?->published_at !== null,
            'changed_fields' => array_values(array_filter([
                $existing === null || (string) $existing->score !== (string) $grade->score ? 'score' : null,
                $existing === null || $existing->feedback !== $grade->feedback ? 'feedback' : null,
                array_key_exists('rubric_scores', $data) ? 'rubric_scores' : null,
            ])),
        ], $request);

        return (new GradeResource($grade->fresh()))->response()->setStatusCode($status);
    }

    public function show(Request $request, Grade $grade): GradeResource
    {
        $grade->loadMissing('submission.assignment.course');
        $this->ensureUniversityVisible($request->user(), $grade->submission->assignment->course->university_id);
        Gate::authorize('view', $grade);
        if ($request->user()->role !== 'student') {
            $grade->load('revisions.changer:id,name');
        }

        return new GradeResource($grade);
    }

    public function publish(
        Request $request,
        Grade $grade,
        GradeService $grades,
        AuditLogger $audit,
    ): GradeResource {
        $grade->loadMissing('submission.assignment.course');
        $this->ensureUniversityVisible($request->user(), $grade->submission->assignment->course->university_id);
        $grade = $grades->publishGrade($request->user(), $grade);
        $audit->record($request->user(), 'grade.published', $grade, [], $request);

        return new GradeResource($grade);
    }
}
