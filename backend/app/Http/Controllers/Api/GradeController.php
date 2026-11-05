<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GradeResource;
use App\Models\Grade;
use App\Models\Submission;
use App\Services\AuditLogger;
use App\Services\GradeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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
    ): GradeResource {
        $submission->loadMissing('assignment.course');
        $this->ensureUniversityVisible($request->user(), $submission->assignment->course->university_id);
        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:20000'],
        ]);
        $grade = $grades->saveGrade($request->user(), $submission, (float) $data['score'], $data['feedback'] ?? null);
        $audit->record($request->user(), 'grade.saved', $grade, ['score' => $grade->score], $request);

        return new GradeResource($grade);
    }

    public function show(Request $request, Grade $grade): GradeResource
    {
        $grade->loadMissing('submission.assignment.course');
        $this->ensureUniversityVisible($request->user(), $grade->submission->assignment->course->university_id);
        Gate::authorize('view', $grade);

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
